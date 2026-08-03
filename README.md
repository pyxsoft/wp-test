# wp-test

A heavy WordPress site, generated on demand, for benchmarking web hosting stacks.

Benchmarks that hit a default `hello world` WordPress measure almost nothing:
the page is 12 KB, the database fits in any cache, and every stack looks fast.
This repository builds the opposite — a site with a large media library,
thousands of thumbnails, long posts, comments, and optionally a WooCommerce
catalogue — so that caching, image handling, PHP-FPM tuning and disk I/O have
something real to work on.

Nothing is downloaded. The site is **generated in place on the machine under
test**, from a fixed seed, so two servers produce the same fixture without
moving gigabytes across the network.

```bash
git clone https://github.com/pyxsoft/wp-test.git
cd wp-test
./build.sh --profile medium \
           --path ~/public_html \
           --url https://bench.example.com \
           --db-name wp_bench --db-user wp_bench --db-pass 'secret'
```

## Profiles

| Profile  | On disk | Content | Files in uploads | Build time | Use it for |
|----------|---------|---------|------------------|-----------|------------|
| `small`  | 50 MB   | 25 posts, 45 originals | 261 | 40 s | Smoke tests, validating a harness |
| `medium` | 507 MB  | 400 posts, 2500 comments, 145 originals | 1077 | 4 min | Cache, WebP, Early Hints, bandwidth |
| `agency` | 140 MB  | 30 Elementor pages (2400 widgets), 8 plugins, 120 posts | 459 | 2 min | PHP render cost, opcache, FPM, per-account CPU |
| `heavy`  | ~3.4 GB | 1500 posts, 480 large originals, 5000 products, 2000 orders | ~3500 | ~45 min | PHP-FPM, MySQL, uncacheable pages |

The three big ones load different parts of the machine on purpose: `medium` the
disk and the network, `heavy` the database, `agency` the interpreter. Mixing
them into one fixture would blur which of the three a result is about.

**`agency` in particular** carries a page builder and the plugin pack a real
agency site accumulates — SEO, forms, redirects, backups, builder add-ons — each
hooking into every request. Its pages live in Elementor's `_elementor_data` JSON
and are rebuilt on every uncached hit. Measured against an ordinary post from
the same fixture: **+36 % TTFB and 2.6× the HTML**. Elementor Pro is deliberately
absent (paid, unpinnable); the free plugin's widgets are enough to load the
renderer. Pin plugin versions in `profiles/agency.plugins` — the manifest always
records the versions actually installed.

`small` and `medium` are measured (2–3 workers on a modest VM); `heavy` is
extrapolated from a reduced run of the same profile, so treat its numbers as an
estimate until you build one. The manifest always reports what was actually
produced.

**Originals versus files.** The number that drives disk usage is not the
original count — WordPress renders every registered thumbnail size, which turns
one original into about 7.6 files and roughly doubles its bytes. At `medium`'s
dimensions that works out to ~3.4 MB of disk per original. To make a fixture
bigger, raise `IMAGES` in the profile and multiply by that figure; the profiles
carry the measured constants in their comments.

`heavy` is the only profile with WooCommerce. That matters because shop, cart
and checkout pages are uncacheable by design: a blog behind a full-page cache
measures the cache, while a shop measures the server.

## What it generates

- **Images that JPEG cannot compress away.** A synthetic gradient encodes to
  nothing; real photos are heavy because of high-frequency detail. The
  generator layers a scaled noise base, a stamped detail tile and translucent
  shapes so a 3000×2000 original lands around 2 MB, where a real photo does.
  Drawing is done with GD, which is present on any server that can run
  WordPress at all — no ImageMagick, Python or Docker required.
- **Both JPEG and PNG** (see `PNG_SHARE`), because they are not
  interchangeable for image-optimisation benchmarks. A server that converts to
  WebP applies a worthwhile-ness threshold, and the two formats sit on opposite
  sides of it: measured on this fixture, PNG converts to 2–3 % of the original
  while JPEG converts to 55–70 %. Both clear a typical 80 % threshold, but only
  a library with both can tell you whether *each* path actually works. The
  manifest reports the split under `media_by_format`.
- **Real thumbnails.** Attachments go in through `wp_insert_attachment()` and
  `wp_generate_attachment_metadata()`, so every registered size is rendered on
  disk exactly as an upload through the admin would produce. This is the slowest
  part of the build and most of the disk footprint.
- **Editorial-shaped content.** Posts carry headings, lists, quotes, code
  blocks, inline links, featured images and galleries — not one lorem blob that
  compresses to nothing.
- **Uploads spread over time.** Content dates run backwards from a fixed epoch,
  so `wp-content/uploads` fills many `YYYY/MM` directories instead of one.

## Determinism

Everything comes from one seed (`--seed`, default `20260803`) through a small
LCG in `lib/rng.php`. Each item draws from its own derived stream, so image 900
does not depend on images 1–899 — that is what allows parallel workers.

Two guarantees, and one honest caveat:

- **Same seed, same pixels.** The manifest's `pixels_hash` is computed from the
  generated pixels, sampled on a fixed grid before encoding, and should match
  across servers. That is also why the image scaler is pinned to
  `IMG_BILINEAR_FIXED`: GD's bicubic upscaler is absent from some builds
  (measured: fine on GD 2.1.0, fails on every upscale with GD 2.3.3), so relying
  on it would split fixtures into two families that cannot be compared.
- **Same seed, same content.** Titles, bodies, dates, prices and comments are
  identical between runs.
- **JPEG bytes may differ by a fraction of a percent** between servers running
  different libjpeg versions, and so may the pixels you get back from decoding
  them. That is exactly why the hash is taken before the encoder runs: it
  answers "did both servers draw the same thing" without the answer depending on
  a library version. Compare `pixels_hash`, never `md5sum` of the files.

`--workers > 1` permutes attachment IDs, since the shards insert concurrently.
The content is the same either way; use `--workers 1` when you need two
databases to match row for row.

## Requirements

On the machine being benchmarked:

- PHP 8.0+ CLI **with the GD extension** (`php-gd`)
- MySQL or MariaDB, and a database the account can write to
- `wp-cli` — downloaded automatically to a temp directory if missing. It has to be
  the **phar itself**: the build runs it as `php wp-cli.phar …`, so a `wp` in `PATH`
  that is really a shell launcher (what hosting panels with per-account PHP install)
  is detected and skipped rather than fed to PHP. Point `--wp` at the phar — often
  next to the launcher, e.g. `/opt/corepanel/share/wp-cli/wp-cli.phar` — or leave it
  out and let the build fetch one. The phar does not need to be executable.
- Network access for the WordPress core download (and WooCommerce on `heavy`)

Run it as the account's own user, never as root: the files have to be owned by
whoever the web server runs PHP as. `build.sh` warns if you forget.

## Useful invocations

```bash
# Quick fixture on an existing WordPress, content only
./build.sh --profile small --path ~/public_html --url https://x.test --skip-core -y

# Full stress fixture, all cores, fixed clock
./build.sh --profile heavy --path ~/public_html --url https://x.test \
           --db-name wp --db-user wp --db-pass pw --workers 8 --epoch '2025-01-01'

# Regenerate only the media library
./build.sh --profile medium --path ~/public_html --url https://x.test --only media --skip-core

# Byte-comparable build across two servers
./build.sh --profile medium ... --workers 1 --seed 424242
```

## The manifest

Every build writes `wp-test-manifest.json` into the document root: profile,
seed, WordPress and PHP versions, row counts per table, uploads size and file
count, database size, and the pixel hash. Quote it alongside any benchmark
number — a result without the fixture it ran against is not comparable to
anything.

## Layout

```
build.sh              Orchestrator: install WordPress, run the steps
profiles/*.env        Profile parameters (KEY=VALUE; read by bash and PHP)
lib/rng.php           Deterministic PRNG with derivable sub-streams
lib/media.php         GD image generator
lib/text.php          Deterministic editorial text
lib/config.php        Profile loading, fixed clock, shard handling
lib/elementor.php     Deterministic Elementor document builder
steps/20-media.php    Media library and thumbnails
steps/30-content.php  Taxonomies, pages, posts, comments, menus
steps/40-woocommerce.php  Catalogue and orders
steps/50-elementor.php    Builder pages (agency profile)
steps/90-manifest.php     Measure and record what was built
tools/probe-media.php Calibrate image size/time without building a site
bench/                Benchmark harnesses (see bench/README.md)
```

## Licence

MIT. This generates test fixtures; it is not a WordPress site you should serve
to the public. Builds set `blog_public=0` and use `*.invalid` email addresses.
