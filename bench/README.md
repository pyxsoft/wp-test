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

## What to measure

- TTFB and full-load percentiles (p50/p95/p99) — never averages alone
- Requests per second at a fixed concurrency, plus the concurrency at which
  latency knees
- Bytes on the wire per page view (this is where WebP and compression show up)
- CPU seconds and peak RSS per request, server-side
- Cache hit ratio, reported next to every HTML latency number

## Planned harnesses

- `bench/urls.sh` — derive a URL list from a built site (posts, archives,
  products, uploads) so every tool measures the same paths
- `bench/load.sh` — driver around a load generator, with warm/cold runs and
  percentile output
- `bench/compare.md` — how to run the same profile on two hosts and present the
  difference honestly

## Open questions

- Which load generator to standardise on. `wrk` gives clean percentiles but
  cannot follow a URL list well; `k6` scripts realistically but adds a runtime
  dependency to the client box. The client must never be the bottleneck —
  whatever we pick, verify that first.
- Whether to measure from the same LAN or across the internet. Both are
  legitimate; they answer different questions and must not be mixed in one
  table.
