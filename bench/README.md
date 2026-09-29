# Benchmarks

The fixture lives one directory up; this is where the measurements go.

Nothing here yet — the harnesses land in the next phase. This file records the
decisions that shape them, so the first harness written does not have to
re-litigate them.

## Rules that make results comparable

**Always quote the manifest.** `wp-test-manifest.json` states the profile, the
seed, the WordPress and PHP versions, and the real on-disk sizes. A number
without its fixture is not a result.

**Compare like for like.** The point of generating in place is that the same
seed yields the same site on every box. Before comparing two servers, check
that their manifests agree on `pixels_hash` and row counts. If they don't, the
fixtures differ and the comparison is meaningless.

**Separate what is being measured.** Three regimes behave completely
differently and mixing them produces averages that describe nothing:

| Regime | What it exercises | Representative URLs |
|--------|-------------------|---------------------|
| Static assets | Web server, sendfile, compression, HTTP/2-3, image formats | `/wp-content/uploads/**.jpg` |
| Cacheable HTML | Full-page cache, TTFB on hit, revalidation | home, post permalinks, category archives |
| Uncacheable HTML | PHP-FPM, opcode cache, MySQL | `/cart/`, `/checkout/`, `/my-account/`, search, admin |

**Warm and cold both matter.** A cold cache after a deploy is a real user
experience; report both rather than picking the flattering one.

**One variable at a time.** PHP version, cache mode, worker count, image
format. Changing two at once and reporting the delta is how benchmarks end up
meaning nothing.

## The `agency` profile as a workload

`agency` exists to compare **servers and panels**, not to study Elementor.
Elementor is the load generator: it produces the traffic shape that separates
web servers most sharply, and it does it the way a real customer site does.

What it puts under stress that the other profiles do not:

| Property of a builder page | What it exercises in the server |
|---|---|
| 30-80 CSS/JS assets per page | HTTP/2-3 multiplexing, connection handling, static delivery |
| Per-page CSS generated on first hit | Cold-cache behaviour, write path, cache warm-up |
| 235 KB of HTML per page | Compression (brotli vs gzip), buffering, TTFB vs full-load |
| Rebuilt from JSON on every miss | PHP-FPM pool, opcache, per-account CPU limits |
| Assets known before the HTML is ready | **Early Hints** — the one place a 103 pays off most |

That last row is the interesting one for CorePanel: a page with dozens of
sub-resources is exactly where Early Hints should show a measurable win, and a
`small` fixture with three assets would show nothing either way.

## What a repeated GET cannot measure

A load generator answers one question well — how much traffic the machine takes
before it falls over — and is structurally incapable of answering the one that
matters to a visitor. Three reasons, each fatal on its own:

- **Early Hints require a client that acts on a 103.** curl, wrk and k6 receive
  the interim response and ignore it. The entire benefit is that the browser
  starts fetching CSS *before* the HTML exists; a client that fetches nothing
  extra measures exactly zero improvement, no matter how well the server works.
- **WebP requires content negotiation.** Conversion happens because the client
  sent `Accept: image/webp`. A load generator does not, unless told to.
- **A page is not a request.** Optimisations change how many requests a page
  needs and how many bytes they carry. Hitting one URL in a loop never sees it.

So there are two harnesses, and they answer different questions. Keep their
numbers in separate tables.

| Question | Tool | Metrics |
|---|---|---|
| What does a visitor experience? | Headless browser | TTFB, FCP, LCP, requests, bytes, waterfall |
| How much load does it take? | k6 / wrk | req/s, p95 under concurrency, error rate |
| What does it cost the server? | Server-side sampling | **CPU-seconds per page**, RSS |

## How the three optimisations actually work

They are not three versions of "faster". Each attacks a different part of the
page load, which is why they have to be measured separately before being
measured together.

**Early Hints tapes over PHP's thinking time.** The browser cannot request the
stylesheet until the HTML arrives. If WordPress needs 400 ms to build the page,
that is 400 ms of a browser sitting idle. The 103 goes out immediately, turning
the wait into downloads. The ceiling on the win is therefore *the generation
time itself* — not a function of network latency. Which means the heavier the
site, the more there is to gain, and measuring this on a trivial page will
correctly report "no difference".

**WebP removes bytes.** The LCP element of a WordPress page is usually an image;
a smaller image paints sooner. This is the one optimisation whose benefit scales
with the visitor's bandwidth rather than with server speed, so it needs
throughput emulation to show its real shape. It also has a cold cost — the first
request pays for the conversion — which the variant cache then amortises.

**The dynamic cache removes the work.** On a hit there is no PHP at all: TTFB
collapses and CPU per page drops to a fraction.

### The interaction to get ahead of

A reviewer will notice this, so state it first: **the dynamic cache shrinks the
window Early Hints exploits.** On a cache hit the HTML arrives almost
immediately, so there is no dead time left to cover. That is not a
contradiction; they cover different situations, and the honest framing is:

| Situation | What helps |
|---|---|
| Cache hit | Dynamic cache — TTFB near zero, CPU near zero |
| Cache miss, first visit | Early Hints — covers the whole generation |
| **Uncacheable page** (cart, checkout, search, logged-in) | **Early Hints only** — no cache can help here |

That last row is the strongest and least obvious argument: on the pages no cache
can rescue, the 103 is the only thing left. They are also the pages that hurt
most in a shop.

### Measurement matrix

On the `agency` profile, since it has both a long generation time and many
assets:

| # | Configuration | What it demonstrates |
|---|---|---|
| S1 | everything off | Baseline |
| S2 | + WebP | Bytes and LCP, at several bandwidths |
| S3 | + Early Hints | How much of the PHP window is recovered |
| S4 | + WebP + Early Hints | **The real default** of the free tier |
| S5 | + dynamic cache, **hit** | TTFB floor and CPU collapse |
| S6 | dynamic cache **miss**, all on | The realistic mixed case |
| S7 | **uncacheable** page, all on | Where Early Hints is the only help |

Report every scenario in three states, because they are three different truths:
**cold** (server has not learned its hints yet, no variants cached), **warm
server** (hints learned, variants cached, browser cache empty — this is what a
returning visitor's *first* page load looks like), and **warm everything**.

The proof that convinces is not "LCP improved 12 %". It is the waterfall showing
the stylesheet starting to download 300 ms earlier, next to the same waterfall
without hints. Save the traces.

### A limit worth stating before someone else does

Early Hints is honoured by Chromium-based browsers and Firefox. **Safari does
not implement it.** Any claim about it should say so; it still covers most
traffic, and saying it up front is cheaper than being corrected.

## What to measure

- TTFB and full-load percentiles (p50/p95/p99) — never averages alone
- Requests per second at a fixed concurrency, plus the concurrency at which
  latency knees
- Bytes on the wire per page view (this is where WebP and compression show up)
- CPU seconds and peak RSS per request, server-side
- Cache hit ratio, reported next to every HTML latency number

## Axes of comparison

Two, and they must not be mixed in one table:

1. **Control panel vs control panel** — same fixture, same VM spec, whatever
   each panel installs by default. This measures the *product*, and most of what
   it measures is defaults: PHP handler, opcache, MPM. Say which defaults you
   left alone, because that is what the number is really about.
2. **Web server vs web server** — same fixture, same box, same PHP-FPM, swapping
   only the server in front. This measures the *engine*, and it is the cleaner
   experiment of the two: one variable, and no packaging differences to argue
   about.

Axis 2 is where the fixture pays off most, because everything except the server
can be held identical — including the PHP-FPM pool, which should be the *same
running pool* wherever possible so that PHP is provably not the variable.

Two rules that are about honesty rather than legality, though they help with
both:

- **Tune the other side before you compare against it.** A panel left at its
  defaults may be running PHP through CGI with no opcache; measuring that
  against a tuned stack produces a number that is true and useless. Report the
  default *and* the tuned configuration, and headline the tuned one.
- **Results carry hostnames.** `wp-test-manifest.json` records `site.url`, and
  load-generator output usually embeds the target host too. Strip them before
  publishing or committing results — what you are comparing is configurations,
  not somebody's server.

## Harnesses

Written:

- `urls.sh` — derives the URL list from a built site, keeping the classes apart
- `lowlevel.sh` — header facts: HTTP version, 103, WebP negotiation (JPEG and
  PNG separately), compression, cache headers
- `servers/` — nginx and Apache pointed at the same docroot and the same running
  PHP-FPM pool, so PHP is never the variable
- `benchgen/` — the load generator for comparing servers: HTTP/1.1, HTTP/2 and
  HTTP/3 with one connection per visitor, reconnecting after a keep-alive limit,
  percentiles, timeouts counted apart. `-mode hold` keeps N visitors connected
  and times a newly arriving one — the test that separates a worker-per-connection
  server from an event-driven one. Its header lists the traps it avoids.
- `srvstat.py` — server-side sampling during a run: whole-machine CPU-seconds and
  peak PSS of the web server and of one account's PHP. CPU per request is its
  `cpu_s` divided by the requests answered in the window.

To write:

- `page.js` — headless Chromium (Playwright) collecting TTFB/FCP/LCP, request
  count, transferred bytes and the full waterfall, N repetitions per scenario,
  fresh profile each time, with bandwidth emulation for the WebP scenarios.
  **This is the primary harness**: everything in the matrix above needs a real
  browser.
- `load.sh` — k6 driver, for capacity only. Deliberately last: it answers a
  question the others do not, but it cannot see any of the optimisations.

## Open questions

- Load generator for page-level capacity (a URL list, not one URL): `benchgen`
  hits one URL per run. `k6` scripts realistically at the cost of a runtime on
  the client box. Whatever runs it, verify first that the client is not the
  bottleneck.
- Bandwidth profiles for the WebP scenarios. Fibre hides the entire benefit;
  something mobile-shaped shows it. Pick two, say which they are, never average
  them together.
