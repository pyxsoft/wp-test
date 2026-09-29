// benchgen measures one web server with the same rules for HTTP/1.1, HTTP/2
// and HTTP/3: every visitor owns its own connection, rate is computed over the
// time that really elapsed, and requests that never came back are counted.
//
//	benchgen -mode load -proto h2 -c 100 -d 30s -url https://site/path
//	benchgen -mode hold -hold 2000 -probe-every 1s -d 60s -url https://site/
//
// Mode "load": -c visitors ask for -url back to back. Each visitor has a
// private transport, so HTTP/2 and HTTP/3 are not allowed to fold a crowd onto
// one connection (that would measure one browser, not many visitors). When the
// server closes a keep-alive connection (MaxKeepAliveRequests), the visitor
// reconnects, as a browser would.
//
// Mode "hold": -hold HTTP/1.1 connections each fetch the URL once and then keep
// the connection open with a new request every -hold-every (below the server's
// keep-alive timeout), the way browsers with a tab open do. Meanwhile a probe
// opens a NEW connection every -probe-every and times a full request: what a
// newly arriving visitor experiences while the others are connected.
//
// Output is one JSON object on stdout.
//
//	cd bench/benchgen && go build -o benchgen .
//
// Run it from a different machine than the one under test, and read the server's
// own CPU and memory alongside it (../srvstat.py): a result where the server is
// not busy is a result about the client or the network, not about the server.
//
// Things it does on purpose, each of which produced a wrong number first:
//
//   - It reconnects. h2load in HTTP/1.1 mode does not: when a server closes the
//     connection after its keep-alive request limit (100 is a common default), that
//     client simply stops, and the run reports visitors × limit requests as if it
//     were throughput.
//   - One transport per visitor, in every protocol. A shared HTTP/2 transport puts
//     every visitor on one connection, which measures one browser, not a crowd.
//   - -ae defaults to what a browser sends. Compare servers with -ae "" (no
//     compression, identical bytes from everyone) before comparing them with it:
//     a server that does not compress answers faster and sends several times more.
//   - Requests that started inside the window count even if they finish after it,
//     and the rate is divided by the time that really elapsed.
//   - Many visitors from ONE client machine converge on one network port. With
//     large responses at high concurrency the switch in front of the client drops
//     packets (incast), and the numbers describe the network. Check retransmits
//     (nstat TcpRetransSegs) on the server before believing a collapse.
package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"sort"
	"sync"
	"sync/atomic"
	"time"

	"github.com/quic-go/quic-go"
	"github.com/quic-go/quic-go/http3"
	"golang.org/x/net/http2"
)

type result struct {
	Mode       string  `json:"mode"`
	Proto      string  `json:"proto"`
	URL        string  `json:"url"`
	Conns      int     `json:"conns"`
	Duration   float64 `json:"duration_s"`
	Elapsed    float64 `json:"elapsed_s"`
	Answered   int64   `json:"answered"`
	TimedOut   int64   `json:"timed_out"`
	Errors     int64   `json:"errors"`
	NonOK      int64   `json:"non_2xx"`
	RPS        float64 `json:"rps"`
	MBps       float64 `json:"body_mb_s"`
	P50        float64 `json:"p50_ms"`
	P90        float64 `json:"p90_ms"`
	P99        float64 `json:"p99_ms"`
	P999       float64 `json:"p999_ms"`
	Max        float64 `json:"max_ms"`
	NegProto   string  `json:"negotiated"`
	HoldOpen   int64   `json:"hold_open,omitempty"`
	HoldFailed int64   `json:"hold_failed,omitempty"`
	FirstErr   string  `json:"first_error,omitempty"`
}

var (
	url      = flag.String("url", "", "target URL")
	mode     = flag.String("mode", "load", "load | hold")
	proto    = flag.String("proto", "h1", "h1 | h2 | h3")
	conns    = flag.Int("c", 10, "concurrent visitors (load mode)")
	dur      = flag.Duration("d", 30*time.Second, "measurement duration")
	warmup   = flag.Duration("warmup", 3*time.Second, "requests during warm-up are not counted")
	timeout  = flag.Duration("timeout", 60*time.Second, "give up on one request after this")
	accEnc   = flag.String("ae", "gzip, br", "Accept-Encoding sent (empty = none)")
	hold     = flag.Int("hold", 0, "connections to hold open (hold mode)")
	holdEvry = flag.Duration("hold-every", 4*time.Second, "request interval on held connections")
	probeEv  = flag.Duration("probe-every", time.Second, "probe interval (hold mode)")
	rampUp   = flag.Duration("ramp", 10*time.Second, "time to open the held connections")
)

func newClient(p string) *http.Client {
	tlsCfg := &tls.Config{}
	var rt http.RoundTripper
	switch p {
	case "h1":
		rt = &http.Transport{
			MaxIdleConnsPerHost: 1, MaxConnsPerHost: 1, IdleConnTimeout: 90 * time.Second,
			DisableCompression: true, TLSClientConfig: tlsCfg, ForceAttemptHTTP2: false,
			TLSNextProto: map[string]func(string, *tls.Conn) http.RoundTripper{},
			DialContext:  (&net.Dialer{Timeout: 30 * time.Second}).DialContext,
		}
	case "h2":
		rt = &http2.Transport{TLSClientConfig: tlsCfg, DisableCompression: true}
	case "h3":
		rt = &http3.Transport{TLSClientConfig: tlsCfg, DisableCompression: true,
			QUICConfig: &quic.Config{MaxIdleTimeout: 60 * time.Second}}
	default:
		fmt.Fprintln(os.Stderr, "unknown proto", p)
		os.Exit(2)
	}
	return &http.Client{Transport: rt, Timeout: *timeout}
}

type stats struct {
	answered, timedOut, errs, nonOK, bytes atomic.Int64
	mu                                     sync.Mutex
	lats                                   []time.Duration
	neg                                    atomic.Value
	firstErr                               atomic.Value
}

func (s *stats) do(ctx context.Context, c *http.Client, count bool) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, *url, nil)
	if *accEnc != "" {
		req.Header.Set("Accept-Encoding", *accEnc)
	}
	req.Header.Set("User-Agent", "benchgen/1.0")
	t0 := time.Now()
	resp, err := c.Do(req)
	if err != nil {
		if ctx.Err() != nil && !count {
			return
		}
		if errors.Is(err, context.DeadlineExceeded) || os.IsTimeout(err) {
			if count {
				s.timedOut.Add(1)
			}
		} else if count {
			s.errs.Add(1)
			s.firstErr.CompareAndSwap(nil, err.Error())
		}
		return
	}
	n, _ := io.Copy(io.Discard, resp.Body)
	resp.Body.Close()
	lat := time.Since(t0)
	s.neg.Store(resp.Proto)
	if !count {
		return
	}
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		s.nonOK.Add(1)
	}
	s.answered.Add(1)
	s.bytes.Add(n)
	s.mu.Lock()
	s.lats = append(s.lats, lat)
	s.mu.Unlock()
}

func ms(d time.Duration) float64 { return float64(d.Microseconds()) / 1000 }

func (s *stats) fill(r *result, elapsed time.Duration) {
	sort.Slice(s.lats, func(i, j int) bool { return s.lats[i] < s.lats[j] })
	pct := func(p float64) float64 {
		if len(s.lats) == 0 {
			return 0
		}
		i := int(p / 100 * float64(len(s.lats)))
		if i >= len(s.lats) {
			i = len(s.lats) - 1
		}
		return ms(s.lats[i])
	}
	r.Elapsed = elapsed.Seconds()
	r.Answered, r.TimedOut, r.Errors, r.NonOK = s.answered.Load(), s.timedOut.Load(), s.errs.Load(), s.nonOK.Load()
	r.RPS = float64(r.Answered) / r.Elapsed
	r.MBps = float64(s.bytes.Load()) / 1e6 / r.Elapsed
	r.P50, r.P90, r.P99, r.P999 = pct(50), pct(90), pct(99), pct(99.9)
	if len(s.lats) > 0 {
		r.Max = ms(s.lats[len(s.lats)-1])
	}
	if v, ok := s.neg.Load().(string); ok {
		r.NegProto = v
	}
	if v, ok := s.firstErr.Load().(string); ok {
		r.FirstErr = v
	}
}

func runLoad() result {
	s := &stats{}
	r := result{Mode: "load", Proto: *proto, URL: *url, Conns: *conns, Duration: dur.Seconds()}
	ctx, cancel := context.WithCancel(context.Background())
	var measuring atomic.Bool
	var wg sync.WaitGroup
	for i := 0; i < *conns; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			c := newClient(*proto)
			for ctx.Err() == nil {
				// A request counts if it STARTED inside the window; it is
				// allowed to finish after the clock ran out (drain).
				s.do(context.Background(), c, measuring.Load())
			}
			if t, ok := c.Transport.(io.Closer); ok {
				t.Close()
			}
		}()
	}
	time.Sleep(*warmup)
	measuring.Store(true)
	start := time.Now()
	time.Sleep(*dur)
	cancel()
	wg.Wait()
	s.fill(&r, time.Since(start))
	return r
}

func runHold() result {
	s := &stats{}
	r := result{Mode: "hold", Proto: "h1", URL: *url, Conns: *hold, Duration: dur.Seconds()}
	ctx, cancel := context.WithCancel(context.Background())
	var open, failed atomic.Int64
	var wg sync.WaitGroup
	gap := *rampUp / time.Duration(max(*hold, 1))
	for i := 0; i < *hold; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			c := newClient("h1")
			ok := false
			for ctx.Err() == nil {
				req, _ := http.NewRequestWithContext(ctx, http.MethodGet, *url, nil)
				req.Header.Set("Accept-Encoding", *accEnc)
				resp, err := c.Do(req)
				if err != nil {
					if ok {
						open.Add(-1)
						ok = false
					}
					if ctx.Err() == nil {
						failed.Add(1)
					}
				} else {
					io.Copy(io.Discard, resp.Body)
					resp.Body.Close()
					if !ok {
						open.Add(1)
						ok = true
					}
				}
				select {
				case <-ctx.Done():
				case <-time.After(*holdEvry):
				}
			}
		}()
		time.Sleep(gap)
	}
	time.Sleep(*warmup)
	start := time.Now()
	deadline := start.Add(*dur)
	var pw sync.WaitGroup
	for time.Now().Before(deadline) {
		pw.Add(1)
		go func() {
			defer pw.Done()
			c := newClient("h1") // a fresh visitor: new TCP + TLS every time
			s.do(context.Background(), c, true)
			c.Transport.(*http.Transport).CloseIdleConnections()
		}()
		time.Sleep(*probeEv)
	}
	r.HoldOpen = open.Load()
	pw.Wait()
	cancel()
	wg.Wait()
	r.HoldFailed = failed.Load()
	s.fill(&r, time.Since(start))
	return r
}

func main() {
	flag.Parse()
	if *url == "" {
		flag.Usage()
		os.Exit(2)
	}
	var r result
	if *mode == "hold" {
		r = runHold()
	} else {
		r = runLoad()
	}
	json.NewEncoder(os.Stdout).Encode(r)
}
