# Swapping the web server between passes

The point of axis 2 is that **only the web server changes**. Same machine, same
document root, same PHP-FPM pool — ideally the *same running pool*, so that PHP
is provably not the variable. These files are the minimum configuration needed
to make each server serve the fixture that way.

> **Not yet validated on a live box.** They are written from the documented
> behaviour of each server; expect to fix a path or two the first time. Once a
> run has gone through them, delete this warning.

## Protocol between passes

Stop one server, start the next, and treat every pass identically:

```bash
systemctl stop corehttpd            # or nginx, or httpd
systemctl start nginx
./bench/lowlevel.sh --url https://bench.example.com --urls urls.txt   # sanity
# purge caches, warm up, then measure
```

Three things that silently ruin a comparison if you skip them:

1. **Purge every cache between passes**, including the previous server's variant
   cache and any per-page CSS the builder generated. Otherwise pass two inherits
   pass one's warm-up.
2. **Warm up identically.** Same number of discarded requests, same URL order.
   Report cold and warm separately — cold is a real user experience after a
   deploy, not an anomaly to hide.
3. **Check `lowlevel.sh` after every switch.** If one server negotiates HTTP/2
   and another does not, or one compresses and another does not, you are no
   longer comparing what you think you are.

## The compression trap

Defaults differ, and this one decides the bytes-on-the-wire figure outright:

| Server | Compression by default |
|---|---|
| nginx | **off** (`gzip off`) |
| Apache | `mod_deflate` present, applied per configuration |
| corehttpd | on |

Leaving the defaults means the "bytes transferred" column measures who ships a
better default, not who compresses better. Both readings are legitimate — but
they are *different claims*, so run both and label them:

- **as-shipped**: every server exactly as its package installs it
- **levelled**: compression on everywhere, same algorithm, same level

Say which one a table is showing. Mixing them is how a benchmark ends up
meaning nothing.

## Other things to hold constant

- **TLS**: same certificate and same protocol set everywhere. HTTP/2 needs TLS
  in practice, so a plain-HTTP pass and an HTTPS pass are not comparable.
- **Static file caching headers**: `expires`/`Cache-Control` change what a
  repeat visit does. Either match them or report first-visit numbers only.
- **Worker counts**: leave every server at its own default and *say so*. Tuning
  one and not the others is the most common way to bias this experiment, and
  tuning all of them fairly is a much bigger project than it looks.
- **OpenLiteSpeed does not fit this model.** It manages its own `lsphp`
  processes rather than talking to an external pool, so "same PHP-FPM" cannot
  hold. Either accept it as a different configuration and label it clearly, or
  configure it as an external-app proxy to the same socket — and verify it
  really is not spawning its own PHP.
