// loadgen asks a URL for as many pages as a fixed number of concurrent
// visitors can get, and reports what actually came back.
//
//	go build -o loadgen bench/loadgen.go
//	./loadgen -url http://203.0.113.10/ -host example.com -c 20 -d 20s
//
// Run it from a DIFFERENT machine than the one under test. A generator sharing
// the server's cores competes with it for exactly the resource being measured.
//
// Three things it does on purpose, each of which was a wrong number first:
//
//   - It does not decompress. Asking for gzip and gunzipping every response
//     makes the CLIENT the bottleneck long before the server is: a two-core
//     client decompressing 20 KB pages tops out around a few hundred MB/s of
//     gunzip and reports that as the server's limit. A real visitor decompresses
//     one page on their own device; this one would decompress thousands on
//     yours. -decompress is there for when you want to pay that cost knowingly.
//
//   - Rate is computed over the time that actually elapsed, not the duration
//     requested. When a server is saturated, the requests still in flight when
//     the clock runs out can take another minute to drain — dividing by the
//     requested 20s would inflate the result of precisely the server that is
//     doing worst.
//
//   - A request that never came back is reported, not dropped. Under load the
//     interesting number is often not how fast the answers were but how many
//     there were: "3.3 pages per second" and "219 of 483 visitors waited 60
//     seconds and got nothing" describe the same run, and only one of them is
//     the story.
//
// Latency percentiles cover completed requests only, which is why they must be
// read next to the failure count and never on their own.
package main

import (
	"context"
	"crypto/tls"
	"errors"
	"flag"
	"fmt"
	"io"
	"net/http"
	"os"
	"sort"
	"sync"
	"sync/atomic"
	"time"
)

func main() {
	var (
		url       = flag.String("url", "", "target URL (required)")
		host      = flag.String("host", "", "Host header override, for a site that does not resolve publicly")
		conns     = flag.Int("c", 20, "concurrent visitors")
		dur       = flag.Duration("d", 20*time.Second, "how long to keep asking")
		timeout   = flag.Duration("timeout", 60*time.Second, "give up on one request after this")
		gunzip    = flag.Bool("decompress", false, "decompress responses (costs the client CPU; off by default)")
		insecure  = flag.Bool("k", false, "do not verify TLS certificates")
		quietBody = flag.Bool("headers-only", false, "issue HEAD instead of GET")
		// One visitor, one connection is the model being measured. Over TLS,
		// HTTP/2 would multiplex every "visitor" onto a handful of connections
		// and measure something else entirely — closer to one browser opening a
		// page than to a crowd arriving at once.
		useHTTP2 = flag.Bool("http2", false, "negotiate HTTP/2 (multiplexes visitors onto shared connections)")
	)
	flag.Parse()
	if *url == "" {
		flag.Usage()
		os.Exit(2)
	}

	// One connection per visitor, kept open: a benchmark that reconnects every
	// time measures the TCP and TLS handshake, which no returning visitor pays.
	tr := &http.Transport{
		MaxIdleConns:        *conns * 2,
		MaxIdleConnsPerHost: *conns * 2,
		MaxConnsPerHost:     *conns * 2,
		IdleConnTimeout:     90 * time.Second,
		DisableCompression:  !*gunzip, // we ask for gzip ourselves and keep the bytes as they came
		TLSClientConfig:     &tls.Config{InsecureSkipVerify: *insecure},
		ForceAttemptHTTP2:   *useHTTP2,
	}
	client := &http.Client{Transport: tr, Timeout: *timeout}

	var (
		done     atomic.Int64
		timedOut atomic.Int64
		failed   atomic.Int64
		nonOK    atomic.Int64
		bytes    atomic.Int64
		mu       sync.Mutex
		lats     []time.Duration
	)

	method := http.MethodGet
	if *quietBody {
		method = http.MethodHead
	}

	ctx, stop := context.WithTimeout(context.Background(), *dur)
	defer stop()

	start := time.Now()
	var wg sync.WaitGroup
	for i := 0; i < *conns; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for ctx.Err() == nil {
				req, err := http.NewRequest(method, *url, nil)
				if err != nil {
					failed.Add(1)
					return
				}
				if *host != "" {
					req.Host = *host
				}
				if !*gunzip {
					req.Header.Set("Accept-Encoding", "gzip")
				}
				req.Header.Set("User-Agent", "wp-test-loadgen/1.0")

				t0 := time.Now()
				resp, err := client.Do(req)
				if err != nil {
					// A deadline that fired IS the interesting failure: the
					// visitor waited the whole timeout and left with nothing.
					if errors.Is(err, context.DeadlineExceeded) || os.IsTimeout(err) {
						timedOut.Add(1)
					} else {
						failed.Add(1)
					}
					continue
				}
				n, _ := io.Copy(io.Discard, resp.Body)
				resp.Body.Close()
				lat := time.Since(t0)
				if resp.StatusCode < 200 || resp.StatusCode > 299 {
					nonOK.Add(1)
				}
				done.Add(1)
				bytes.Add(n)
				mu.Lock()
				lats = append(lats, lat)
				mu.Unlock()
			}
		}()
	}
	wg.Wait() // in-flight requests finish; this is why elapsed > duration on a saturated server
	elapsed := time.Since(start)

	sort.Slice(lats, func(i, j int) bool { return lats[i] < lats[j] })
	pct := func(p float64) time.Duration {
		if len(lats) == 0 {
			return 0
		}
		i := int(p / 100 * float64(len(lats)))
		if i >= len(lats) {
			i = len(lats) - 1
		}
		return lats[i]
	}

	fmt.Printf("url:          %s (Host: %s)\n", *url, hostOr(*host, "as given"))
	fmt.Printf("visitors:     %d concurrent, %s requested, %s timeout\n", *conns, *dur, *timeout)
	fmt.Printf("elapsed:      %.1fs   (in-flight requests drained after the clock ran out)\n", elapsed.Seconds())
	fmt.Printf("answered:     %d\n", done.Load())
	fmt.Printf("timed out:    %d   (waited %s and got nothing)\n", timedOut.Load(), *timeout)
	fmt.Printf("errors:       %d\n", failed.Load())
	fmt.Printf("non-2xx:      %d\n", nonOK.Load())
	fmt.Printf("pages/s:      %.2f   (answered / elapsed)\n", float64(done.Load())/elapsed.Seconds())
	fmt.Printf("body MB/s:    %.2f%s\n", float64(bytes.Load())/1e6/elapsed.Seconds(), compressionNote(*gunzip))
	fmt.Printf("p50:          %s\np95:          %s\np99:          %s\n", pct(50), pct(95), pct(99))
}

func hostOr(h, fallback string) string {
	if h == "" {
		return fallback
	}
	return h
}

func compressionNote(gunzip bool) string {
	if gunzip {
		return "  (decompressed)"
	}
	return "  (compressed bytes, as sent)"
}
