#!/usr/bin/env bash
#
# Protocol-level differences between two servers serving the same fixture.
#
# These are the things a latency number never shows: whether Early Hints are
# emitted at all, whether an image is negotiated to WebP, what compression is
# offered, which HTTP version is negotiated. They are also the cheapest possible
# check that two passes are really serving the same site.
#
#   ./lowlevel.sh --url https://bench.example.com --urls urls.txt
#
# Every finding here is a fact about headers, not an opinion about speed. Quote
# them next to the numbers; they usually explain them.

set -euo pipefail

SITE_URL=""
URLS_FILE=""
CURL_OPTS=(-s -o /dev/null --max-time 20)

usage() {
  cat <<EOF
Usage: ./lowlevel.sh --url <site-url> [--urls <file>] [--insecure]

  --url URL      Base URL of the site under test (required)
  --urls FILE    Output of urls.sh, used to pick representative paths
  --insecure     Accept self-signed certificates (-k)
  -h, --help     This message
EOF
}

INSECURE=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --url)      SITE_URL="${2:?}"; shift 2 ;;
    --urls)     URLS_FILE="${2:?}"; shift 2 ;;
    --insecure) INSECURE=1; shift ;;
    -h|--help)  usage; exit 0 ;;
    *)          echo "unknown option: $1" >&2; exit 1 ;;
  esac
done

[[ -n "$SITE_URL" ]] || { usage; echo "error: --url is required" >&2; exit 1; }
SITE_URL="${SITE_URL%/}"
(( INSECURE )) && CURL_OPTS+=(-k)

command -v curl >/dev/null || { echo "error: curl is required" >&2; exit 1; }

# Pick one URL of a class from the urls.txt, or fall back to a sensible guess.
pick() {
  local class="$1" fallback="$2"
  if [[ -n "$URLS_FILE" && -f "$URLS_FILE" ]]; then
    local found
    found="$(awk -v c="$class" '$1==c {print $2; exit}' "$URLS_FILE")"
    [[ -n "$found" ]] && { printf '%s' "$found"; return; }
  fi
  printf '%s%s' "$SITE_URL" "$fallback"
}

HTML_URL="$(pick builder /)"
JPEG_URL="$(pick static-jpeg /)"
PNG_URL="$(pick static-png /)"
CSS_URL="$(pick static-asset /)"

hr() { printf '%s\n' "------------------------------------------------------------"; }

printf '\nwp-test low-level check — %s\n' "$SITE_URL"
printf 'curl %s\n' "$(curl --version | head -1 | awk '{print $2}')"
hr

# ------------------------------------------------------------ HTTP version --

printf '\n[ HTTP version ]\n'
for proto in "--http1.1 HTTP/1.1" "--http2 HTTP/2" "--http3 HTTP/3"; do
  set -- $proto
  flag="$1"; label="$2"
  if out="$(curl "${CURL_OPTS[@]}" "$flag" -w '%{http_version} %{http_code}' "$SITE_URL/" 2>/dev/null)"; then
    printf '  %-9s negotiated=%s status=%s\n' "$label" "${out% *}" "${out#* }"
  else
    printf '  %-9s not supported by this curl or this server\n' "$label"
  fi
done

# ------------------------------------------------------------- Early Hints --

printf '\n[ Early Hints (103) ]\n'
printf '  target: %s\n' "$HTML_URL"

# curl only surfaces 1xx interim responses when it is asked to dump headers and
# the connection is HTTP/2. A 103 on a static file is not expected: engines emit
# it from the PHP/proxy path, so test an HTML page, not an image.
hints="$(curl "${CURL_OPTS[@]}" --http2 -D - -o /dev/null "$HTML_URL" 2>/dev/null | grep -iE '^HTTP/[0-9.]+ 103|^link:' | head -8 || true)"
if [[ -n "$hints" ]]; then
  printf '  emitted:\n'
  printf '%s\n' "$hints" | sed 's/^/    /'
else
  printf '  none observed\n'
  printf '  (expected on PHP-served HTML only; never on plain static files)\n'
fi

# ------------------------------------------------------------------- WebP --

printf '\n[ WebP negotiation ]\n'
check_webp() {
  local label="$1" url="$2"
  [[ "$url" == "$SITE_URL" ]] && { printf '  %-6s no sample in urls.txt\n' "$label"; return; }

  local plain_type plain_len webp_type webp_len
  plain_type="$(curl "${CURL_OPTS[@]}" -w '%{content_type}' "$url")"
  plain_len="$(curl "${CURL_OPTS[@]}" -w '%{size_download}' "$url")"
  webp_type="$(curl "${CURL_OPTS[@]}" -H 'Accept: image/webp,*/*' -w '%{content_type}' "$url")"
  webp_len="$(curl "${CURL_OPTS[@]}" -H 'Accept: image/webp,*/*' -w '%{size_download}' "$url")"

  if [[ "$webp_type" == *webp* ]]; then
    local pct=$(( plain_len > 0 ? webp_len * 100 / plain_len : 0 ))
    printf '  %-6s CONVERTED  %s -> %s  %d -> %d bytes (%d%%)\n' \
      "$label" "$plain_type" "$webp_type" "$plain_len" "$webp_len" "$pct"
  else
    printf '  %-6s not converted (served %s, %d bytes)\n' "$label" "$webp_type" "$webp_len"
  fi
}
# JPEG and PNG separately, always: an engine's worthwhile-ness threshold treats
# them very differently, and a single sample cannot tell you which path works.
check_webp "jpeg" "$JPEG_URL"
check_webp "png" "$PNG_URL"

# ------------------------------------------------------------ compression --

printf '\n[ Compression ]\n'
for enc in gzip br zstd; do
  out="$(curl "${CURL_OPTS[@]}" -H "Accept-Encoding: $enc" -D - -o /dev/null "$HTML_URL" 2>/dev/null \
        | grep -i '^content-encoding:' | tr -d '\r' | awk '{print $2}' || true)"
  size="$(curl "${CURL_OPTS[@]}" -H "Accept-Encoding: $enc" -w '%{size_download}' "$HTML_URL")"
  printf '  %-5s -> %-8s %s bytes\n' "$enc" "${out:-none}" "$size"
done
raw="$(curl "${CURL_OPTS[@]}" -H 'Accept-Encoding: identity' -w '%{size_download}' "$HTML_URL")"
printf '  none  -> identity %s bytes\n' "$raw"

# ------------------------------------------------------------ cache headers --

printf '\n[ Cache and security headers ]\n'
for target in "$HTML_URL" "$JPEG_URL" "$CSS_URL"; do
  [[ "$target" == "$SITE_URL" ]] && continue
  printf '  %s\n' "${target#"$SITE_URL"}"
  curl "${CURL_OPTS[@]}" -D - -o /dev/null "$target" 2>/dev/null \
    | grep -iE '^(cache-control|expires|etag|last-modified|vary|x-.*cache.*|age|server|alt-svc):' \
    | tr -d '\r' | sed 's/^/    /' | head -10
done

hr
printf '\nAll of the above are header facts, not benchmarks. Record them next to\n'
printf 'the latency numbers: they usually explain the difference.\n\n'
