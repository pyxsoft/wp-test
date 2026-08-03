#!/usr/bin/env bash
#
# Derive the URL list for a benchmark run from a site built by ../build.sh.
#
# Every pass — every web server, every configuration — must hit exactly the same
# paths, or the comparison is between URL lists rather than between servers. So
# the list is generated once from the fixture and reused verbatim.
#
#   ./urls.sh --path ~/public_html --url https://bench.example.com > urls.txt
#
# Output is one URL per line, prefixed by a class name:
#
#   home      https://host/
#   builder   https://host/services/
#   post      https://host/tuning-php-fpm-pools/
#   archive   https://host/category/performance/
#   static    https://host/wp-content/uploads/2024/03/landscape-0001-photo.jpg
#   dynamic   https://host/?s=cache
#
# The classes matter as much as the URLs: static assets, cacheable HTML and
# uncacheable HTML behave so differently that averaging them describes nothing.
# Keep them apart all the way to the report.

set -euo pipefail

SITE_PATH=""
SITE_URL=""
PHP_BIN=""
WP_BIN=""
PER_CLASS=10

usage() {
  cat <<EOF
Usage: ./urls.sh --path <docroot> [--url <url>] [options]

  --path DIR        Document root of the built fixture (required)
  --url URL         Override the site URL (default: whatever WordPress says)
  --per-class N     How many URLs per class (default: $PER_CLASS)
  --php PATH        PHP CLI binary
  --wp PATH         wp-cli binary
  -h, --help        This message

Writes to stdout: "<class> <url>", one per line.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --path)      SITE_PATH="${2:?}"; shift 2 ;;
    --url)       SITE_URL="${2:?}"; shift 2 ;;
    --per-class) PER_CLASS="${2:?}"; shift 2 ;;
    --php)       PHP_BIN="${2:?}"; shift 2 ;;
    --wp)        WP_BIN="${2:?}"; shift 2 ;;
    -h|--help)   usage; exit 0 ;;
    *)           echo "unknown option: $1" >&2; exit 1 ;;
  esac
done

[[ -n "$SITE_PATH" ]] || { usage; echo "error: --path is required" >&2; exit 1; }
[[ -f "$SITE_PATH/wp-load.php" ]] || { echo "error: no WordPress at $SITE_PATH" >&2; exit 1; }

# type -P, not command -v: `wp` is a function below.
[[ -n "$PHP_BIN" ]] || PHP_BIN="$(type -P php || true)"
[[ -n "$PHP_BIN" ]] || for candidate in /opt/remi/php8*/root/usr/bin/php /opt/cpanel/ea-php8*/root/usr/bin/php /usr/bin/php8*; do
  [[ -x "$candidate" ]] && PHP_BIN="$candidate" && break
done
[[ -n "$PHP_BIN" ]] || { echo "error: no PHP CLI found, pass --php" >&2; exit 1; }

[[ -n "$WP_BIN" ]] || WP_BIN="$(type -P wp || true)"
[[ -n "$WP_BIN" ]] || { echo "error: wp-cli not found, pass --wp" >&2; exit 1; }

wp() {
  local -a flags=(--path="$SITE_PATH" --skip-plugins --skip-themes)
  [[ "$(id -u)" -eq 0 ]] && flags+=(--allow-root)
  "$PHP_BIN" "$WP_BIN" "${flags[@]}" "$@" 2>/dev/null
}

[[ -n "$SITE_URL" ]] || SITE_URL="$(wp option get home)"
SITE_URL="${SITE_URL%/}"

emit() { # class, path-or-url
  local class="$1" url="$2"
  [[ "$url" == http* ]] || url="$SITE_URL$url"
  printf '%s %s\n' "$class" "$url"
}

# ------------------------------------------------------------------- home ---

emit home "/"

# --------------------------------------------------------------- builder ---

# Pages carrying an Elementor document. These are the PHP-heavy ones: the
# server rebuilds them from a JSON tree on every uncached request.
while IFS= read -r id; do
  [[ -n "$id" ]] && emit builder "$(wp post url "$id")"
done < <(wp post list --post_type=page --post_status=publish \
           --meta_key=_elementor_edit_mode --format=ids --posts_per_page="$PER_CLASS" \
           | tr ' ' '\n' | head -n "$PER_CLASS")

# ------------------------------------------------------------------ posts ---

while IFS= read -r id; do
  [[ -n "$id" ]] && emit post "$(wp post url "$id")"
done < <(wp post list --post_type=post --post_status=publish \
           --format=ids --posts_per_page="$PER_CLASS" --orderby=ID --order=ASC \
           | tr ' ' '\n' | head -n "$PER_CLASS")

# --------------------------------------------------------------- archives ---

# Archives are the cheapest cacheable pages with many thumbnails on them — a
# different shape of work from both a builder page and a single post.
while IFS= read -r slug; do
  [[ -n "$slug" ]] && emit archive "/category/$slug/"
done < <(wp term list category --field=slug --number=$(( PER_CLASS / 2 + 1 )) | head -n $(( PER_CLASS / 2 + 1 )))

blog_page="$(wp option get page_for_posts || echo 0)"
if [[ "${blog_page:-0}" != "0" ]]; then
  emit archive "$(wp post url "$blog_page")"
fi

# ---------------------------------------------------------------- statics ---

# Taken from the filesystem rather than from the HTML: we want the originals and
# the generated thumbnails in known proportions, including both formats. WebP
# conversion behaves differently for JPEG and for PNG, so a run that lumps them
# together cannot tell you which path works.
uploads="$SITE_PATH/wp-content/uploads"
if [[ -d "$uploads" ]]; then
  while IFS= read -r file; do
    emit static-jpeg "/wp-content/uploads${file#"$uploads"}"
  done < <(find "$uploads" -name '*.jpg' -type f | sort | head -n "$PER_CLASS")

  while IFS= read -r file; do
    emit static-png "/wp-content/uploads${file#"$uploads"}"
  done < <(find "$uploads" -name '*.png' -type f | sort | head -n $(( PER_CLASS / 2 + 1 )))
fi

# Theme and plugin assets: many small files per page view, which is what
# separates web servers on connection handling rather than on throughput.
for dir in wp-includes/css wp-includes/js wp-content/plugins; do
  [[ -d "$SITE_PATH/$dir" ]] || continue
  while IFS= read -r file; do
    emit static-asset "/${file#"$SITE_PATH/"}"
  done < <(find "$SITE_PATH/$dir" \( -name '*.css' -o -name '*.js' \) -type f | sort | head -n 5)
done

# -------------------------------------------------------------- dynamic ----

# Deliberately uncacheable. A full-page cache in front makes every other class
# measure the cache; these measure the server.
emit dynamic "/?s=cache"
emit dynamic "/?s=performance&post_type=post"

for path in /cart/ /checkout/ /my-account/ /shop/; do
  if wp post list --post_type=page --field=post_name | tr ' ' '\n' | grep -qx "${path//\//}"; then
    emit dynamic "$path"
  fi
done

# A cache-busting query on a cacheable page: isolates origin cost from cache
# behaviour without needing a shop.
emit dynamic "/?nocache=1"
