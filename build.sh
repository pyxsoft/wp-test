#!/usr/bin/env bash
#
# wp-test — build a heavy WordPress site in place, for benchmarking.
#
# The site is generated on the machine under test rather than downloaded: a
# fixed seed makes the generator produce the same content everywhere, so there
# is no multi-gigabyte artifact to move around and nothing to keep in sync.
#
#   ./build.sh --profile medium --path ~/public_html --url https://example.com \
#              --db-name wp_test --db-user wp_test --db-pass secret
#
# Run it as the account's own user, never as root: the files must end up owned
# by whoever the web server runs PHP as.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$(basename "${BASH_SOURCE[0]}")"
readonly ROOT SELF

# ------------------------------------------------------------------ defaults --

PROFILE="medium"
SITE_PATH=""
SITE_URL=""
DB_NAME=""
DB_USER=""
DB_PASS=""
DB_HOST="localhost"
DB_PREFIX="wp_"
CREATE_DB=0
ADMIN_USER="admin"
ADMIN_PASS=""
ADMIN_EMAIL="admin@wp-test.invalid"
THEME=""
WORKERS=""
SEED=""
EPOCH=""
ONLY=""
SKIP_CORE=0
KEEP_STATE=0
ASSUME_YES=0
PHP_BIN=""
WP_BIN=""
WP_CLI_VERSION=""

STATE_DIR=""

# ------------------------------------------------------------------- logging --

if [[ -t 1 ]]; then
  C_RED=$'\033[31m'; C_GRN=$'\033[32m'; C_YEL=$'\033[33m'; C_BLD=$'\033[1m'; C_OFF=$'\033[0m'
else
  C_RED=""; C_GRN=""; C_YEL=""; C_BLD=""; C_OFF=""
fi

log()  { printf '%s==>%s %s\n' "$C_BLD" "$C_OFF" "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '%s[warn]%s %s\n' "$C_YEL" "$C_OFF" "$*" >&2; }
die()  { printf '%s[error]%s %s\n' "$C_RED" "$C_OFF" "$*" >&2; exit 1; }
ok()   { printf '%s  ok%s %s\n' "$C_GRN" "$C_OFF" "$*"; }

usage() {
  cat <<EOF
${C_BLD}wp-test${C_OFF} — generate a heavy WordPress site for benchmarking

Usage: ./$SELF --path <docroot> --url <url> --db-name <db> --db-user <u> --db-pass <p> [options]

Required:
  --path DIR           Document root to install into (created if missing)
  --url URL            Site URL, e.g. https://bench.example.com
  --db-name NAME       Database name
  --db-user USER       Database user
  --db-pass PASS       Database password

Profiles:
  --profile NAME       small | medium | heavy, or a path to a .env profile
                       (default: $PROFILE)
  --seed N             Generator seed; same seed = same site (default: 20260803)
  --epoch DATE         Fixed clock for content dates (default: 2024-01-15)

Options:
  --db-host HOST       Database host (default: $DB_HOST)
  --db-prefix PREFIX   Table prefix (default: $DB_PREFIX)
  --create-db          Try to create the database and user first (needs a
                       mysql client with rights to do so)
  --admin-user USER    WordPress admin login (default: $ADMIN_USER)
  --admin-pass PASS    WordPress admin password (default: generated, printed)
  --admin-email MAIL   WordPress admin email (default: $ADMIN_EMAIL)
  --theme SLUG         Theme to install and activate (default: WordPress's own)
  --workers N          Parallel workers for image and product generation
                       (default: cores-1, capped at 8)
  --only STEPS         Comma-separated subset: media,content,woo,elementor,manifest
  --skip-core          Site is already installed; only generate content
  --php PATH           PHP CLI binary to use (default: autodetected)
  --wp PATH            wp-cli binary to use (default: autodetected, else
                       downloaded to a temporary directory)
  --keep-state         Keep the intermediate state directory
  -y, --yes            Do not ask for confirmation
  -h, --help           This message

Notes:
  * Run as the site's own user, not as root.
  * --workers >1 speeds up image generation but permutes attachment IDs. Use
    --workers 1 when you need two servers to produce byte-identical databases.
EOF
}

# --------------------------------------------------------------- arg parsing --

while [[ $# -gt 0 ]]; do
  case "$1" in
    --profile)      PROFILE="${2:?}"; shift 2 ;;
    --path)         SITE_PATH="${2:?}"; shift 2 ;;
    --url)          SITE_URL="${2:?}"; shift 2 ;;
    --db-name)      DB_NAME="${2:?}"; shift 2 ;;
    --db-user)      DB_USER="${2:?}"; shift 2 ;;
    --db-pass)      DB_PASS="${2:?}"; shift 2 ;;
    --db-host)      DB_HOST="${2:?}"; shift 2 ;;
    --db-prefix)    DB_PREFIX="${2:?}"; shift 2 ;;
    --create-db)    CREATE_DB=1; shift ;;
    --admin-user)   ADMIN_USER="${2:?}"; shift 2 ;;
    --admin-pass)   ADMIN_PASS="${2:?}"; shift 2 ;;
    --admin-email)  ADMIN_EMAIL="${2:?}"; shift 2 ;;
    --theme)        THEME="${2:?}"; shift 2 ;;
    --workers)      WORKERS="${2:?}"; shift 2 ;;
    --seed)         SEED="${2:?}"; shift 2 ;;
    --epoch)        EPOCH="${2:?}"; shift 2 ;;
    --only)         ONLY="${2:?}"; shift 2 ;;
    --skip-core)    SKIP_CORE=1; shift ;;
    --php)          PHP_BIN="${2:?}"; shift 2 ;;
    --wp)           WP_BIN="${2:?}"; shift 2 ;;
    --keep-state)   KEEP_STATE=1; shift ;;
    -y|--yes)       ASSUME_YES=1; shift ;;
    -h|--help)      usage; exit 0 ;;
    *)              die "unknown option: $1 (try --help)" ;;
  esac
done

# ------------------------------------------------------------- prerequisites --

resolve_profile() {
  if [[ -f "$PROFILE" ]]; then
    PROFILE_FILE="$(cd "$(dirname "$PROFILE")" && pwd)/$(basename "$PROFILE")"
  elif [[ -f "$ROOT/profiles/$PROFILE.env" ]]; then
    PROFILE_FILE="$ROOT/profiles/$PROFILE.env"
  else
    die "no such profile: $PROFILE (expected one of: $(cd "$ROOT/profiles" && ls *.env | sed 's/\.env//' | tr '\n' ' '))"
  fi
  # shellcheck disable=SC1090
  source "$PROFILE_FILE"
}

detect_php() {
  [[ -n "$PHP_BIN" ]] && { [[ -x "$PHP_BIN" ]] || type -P "$PHP_BIN" >/dev/null || die "no such PHP binary: $PHP_BIN"; return; }

  local candidates=()
  # type -P, not command -v: this script defines shell functions named after the
  # tools it wraps, and command -v would happily return the function.
  [[ -n "$(type -P php)" ]] && candidates+=("$(type -P php)")
  # Multi-version hosting layouts, newest first.
  for pattern in \
      "/opt/remi/php8*/root/usr/bin/php" \
      "/opt/cpanel/ea-php8*/root/usr/bin/php" \
      "/opt/alt/php8*/usr/bin/php" \
      "/usr/local/php8*/bin/php" \
      "/usr/bin/php8*"; do
    while IFS= read -r found; do
      [[ -x "$found" ]] && candidates+=("$found")
    done < <(compgen -G "$pattern" 2>/dev/null | sort -rV || true)
  done

  for candidate in "${candidates[@]}"; do
    if "$candidate" -r 'exit(version_compare(PHP_VERSION, "8.0", ">=") && extension_loaded("gd") ? 0 : 1);' 2>/dev/null; then
      PHP_BIN="$candidate"
      return
    fi
  done

  if [[ ${#candidates[@]} -eq 0 ]]; then
    die "no PHP CLI found. Pass one with --php /path/to/php"
  fi
  die "found PHP (${candidates[0]}) but it is older than 8.0 or has no GD extension.
       GD is what draws the images; install php-gd, or point --php at a build that has it."
}

# Can PHP run this file as WP-CLI? Sets WP_CLI_VERSION on success.
#
# The wrappers below invoke wp-cli as `$PHP_BIN <file> …`, so the file has to be
# the phar itself. Anything else — most often a shell launcher named `wp` — is
# read by PHP as plain text, echoed to stdout and exits 0, so the build appears
# to install WordPress in two seconds and then generates nothing into an empty
# document root. Hosts that give every account its own PHP (CorePanel, cPanel,
# Plesk) ship exactly such a launcher, so this is the common case, not an exotic
# one. Hence: never trust the name, always ask it for its version.
#
# Readability is the only file permission that matters here, again because PHP is
# the one executing it: a packaged phar is often 0644 and works perfectly.
wp_phar_works() {
  local candidate="$1"
  [[ -f "$candidate" && -r "$candidate" ]] || return 1

  local -a flags=(--version --skip-plugins --skip-themes)
  [[ "$(id -u)" -eq 0 ]] && flags+=(--allow-root)

  local out
  out="$("$PHP_BIN" -d memory_limit=512M "$candidate" "${flags[@]}" 2>/dev/null)" || return 1
  [[ "$out" == WP-CLI* ]] || return 1
  WP_CLI_VERSION="$(printf '%s' "$out" | head -1)"
}

detect_wp() {
  local requested="$WP_BIN"
  local -a candidates=()
  local found

  if [[ -n "$requested" ]]; then
    if [[ -e "$requested" ]]; then
      candidates+=("$requested")
    elif found="$(type -P "$requested")"; then
      candidates+=("$found")
    else
      die "no such wp-cli binary: $requested"
    fi
  else
    # Must be type -P: `wp` is also the name of the wrapper function below, and
    # command -v would return that instead of the executable.
    found="$(type -P wp || true)"
    [[ -n "$found" ]] && candidates+=("$found")

    # Where hosting stacks keep the real phar that their `wp` launcher runs.
    for pattern in \
        "/opt/corepanel/share/wp-cli/wp-cli.phar" \
        "/usr/local/bin/wp-cli.phar" \
        "/usr/share/wp-cli/wp-cli.phar" \
        "$HOME/wp-cli.phar"; do
      while IFS= read -r f; do
        candidates+=("$f")
      done < <(compgen -G "$pattern" 2>/dev/null || true)
    done
  fi

  for candidate in "${candidates[@]}"; do
    if wp_phar_works "$candidate"; then
      WP_BIN="$candidate"
      return
    fi
  done

  if [[ -n "$requested" ]]; then
    die "$requested is not a wp-cli phar this PHP can run.
       wp-test runs wp-cli as '$PHP_BIN <file>', so --wp must point at wp-cli.phar
       itself, not at a launcher script that picks a PHP of its own. On a hosting
       panel the phar is usually beside the launcher, e.g.
       /opt/corepanel/share/wp-cli/wp-cli.phar. Omit --wp to have one downloaded."
  fi

  local phar="$STATE_DIR/wp-cli.phar"
  if [[ ${#candidates[@]} -gt 0 ]]; then
    log "the 'wp' in PATH (${candidates[0]}) is not a phar this PHP can run; downloading wp-cli to $phar"
  else
    log "wp-cli not found, downloading it to $phar"
  fi
  local url="https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$url" -o "$phar" || die "cannot download wp-cli"
  elif command -v wget >/dev/null 2>&1; then
    wget -qO "$phar" "$url" || die "cannot download wp-cli"
  else
    die "wp-cli is missing and neither curl nor wget is available to fetch it"
  fi
  wp_phar_works "$phar" || die "the downloaded wp-cli does not run under $PHP_BIN"
  WP_BIN="$phar"
}

# wp-cli wrapper: fixed path, generous memory (GD needs it for 4800px images),
# and --allow-root only when we really are root.
wp() {
  local -a flags=(--path="$SITE_PATH")
  [[ "$(id -u)" -eq 0 ]] && flags+=(--allow-root)
  "$PHP_BIN" -d memory_limit=1024M -d max_execution_time=0 "$WP_BIN" "${flags[@]}" "$@"
}

# Same, but without --path, for commands that run before the site exists.
wp_nopath() {
  local -a flags=()
  [[ "$(id -u)" -eq 0 ]] && flags+=(--allow-root)
  "$PHP_BIN" -d memory_limit=1024M -d max_execution_time=0 "$WP_BIN" "${flags[@]}" "$@"
}

want_step() {
  [[ -z "$ONLY" ]] && return 0
  [[ ",$ONLY," == *",$1,"* ]]
}

# Install the profile's plugin pack, if it declares one.
#
# A plugin that fails to install is reported and skipped rather than fatal: a
# slug can be renamed or a pinned version pulled from wordpress.org, and losing
# a whole build to one missing plugin helps nobody. What must not happen is a
# silent difference between the two servers being compared — hence the summary
# at the end, and the versions recorded in the manifest.
install_plugin_pack() {
  local pack="${PLUGIN_PACK:-}"
  [[ -z "$pack" ]] && return 0

  local pack_file="$pack"
  [[ -f "$pack_file" ]] || pack_file="$ROOT/profiles/$pack"
  [[ -f "$pack_file" ]] || die "plugin pack not found: $pack"

  info "installing plugin pack ($(basename "$pack_file"))"

  local -a failed=()
  local entry slug version count=0

  while IFS= read -r entry || [[ -n "$entry" ]]; do
    entry="${entry%%#*}"
    entry="$(printf '%s' "$entry" | tr -d '[:space:]')"
    [[ -z "$entry" ]] && continue

    slug="${entry%%=*}"
    version="${entry#*=}"
    [[ "$version" == "$slug" ]] && version=""

    if [[ -n "$version" ]]; then
      if wp plugin install "$slug" --version="$version" --activate --force >/dev/null 2>&1; then
        count=$((count + 1))
      else
        failed+=("$slug=$version")
      fi
    else
      if wp plugin install "$slug" --activate --force >/dev/null 2>&1; then
        count=$((count + 1))
      else
        failed+=("$slug")
      fi
    fi
  done < "$pack_file"

  info "activated $count plugins"

  if [[ ${#failed[@]} -gt 0 ]]; then
    warn "these plugins could not be installed: ${failed[*]}"
    warn "the fixture is still usable, but the other side of a comparison must"
    warn "be missing exactly the same ones — check both manifests before measuring"
  fi

  # Silence the setup wizards that would otherwise redirect or nag. They do not
  # affect the front end, but they make the admin unusable for a human checking
  # the fixture by hand.
  wp option delete elementor_onboarded >/dev/null 2>&1 || true
  wp option update elementor_disable_typography_schemes yes >/dev/null 2>&1 || true
  wp option update wpseo_flush_rewrite '' >/dev/null 2>&1 || true
  wp transient delete --all >/dev/null 2>&1 || true
}

# --------------------------------------------------------------------- setup --

[[ -n "$SITE_PATH" ]] || { usage; die "--path is required"; }
[[ -n "$SITE_URL" ]]  || die "--url is required"

if [[ $SKIP_CORE -eq 0 ]]; then
  [[ -n "$DB_NAME" ]] || die "--db-name is required (or use --skip-core on an existing site)"
  [[ -n "$DB_USER" ]] || die "--db-user is required"
fi

resolve_profile

mkdir -p "$SITE_PATH"
SITE_PATH="$(cd "$SITE_PATH" && pwd)"

STATE_DIR="${TMPDIR:-/tmp}/wp-test-state-${PROFILE_NAME}-$$"
mkdir -p "$STATE_DIR"
cleanup() {
  if [[ $KEEP_STATE -eq 1 ]]; then
    info "state kept in $STATE_DIR"
  else
    rm -rf "$STATE_DIR"
  fi
}
trap cleanup EXIT

detect_php
detect_wp

# No separate "does wp-cli run?" probe here: detect_wp only accepts a file that
# answered `--version` with a WP-CLI banner under this very PHP, which is a
# stricter proof than a second exit-status check would be. A launcher script
# passes an exit-status check — PHP prints it as text and returns 0.

if [[ -z "$WORKERS" ]]; then
  cores="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 2)"
  WORKERS=$(( cores > 1 ? cores - 1 : 1 ))
  (( WORKERS > 8 )) && WORKERS=8
fi

[[ -z "$ADMIN_PASS" ]] && ADMIN_PASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | head -c 18)"

if [[ "$(id -u)" -eq 0 ]]; then
  warn "running as root: the generated files will be owned by root, which is"
  warn "almost never what you want on a hosting account. Prefer 'su - <user>'."
fi

# Rough disk check: uploads plus thumbnails plus the database.
avail_mb="$(df -Pm "$SITE_PATH" | awk 'NR==2 {print $4}')"
need_mb=$(( TARGET_SIZE_MB + TARGET_SIZE_MB / 4 ))
if [[ -n "$avail_mb" ]] && (( avail_mb < need_mb )); then
  warn "only ${avail_mb} MB free on $SITE_PATH; profile '$PROFILE_NAME' wants about ${need_mb} MB"
fi

log "wp-test build"
info "profile     $PROFILE_NAME  ($PROFILE_FILE)"
info "target      ~${TARGET_SIZE_MB} MB"
info "path        $SITE_PATH"
info "url         $SITE_URL"
info "php         $PHP_BIN ($("$PHP_BIN" -r 'echo PHP_VERSION;'))"
info "wp-cli      $WP_BIN${WP_CLI_VERSION:+ ($WP_CLI_VERSION)}"
info "workers     $WORKERS"
info "seed        ${SEED:-20260803 (default)}"
[[ -n "$ONLY" ]] && info "steps       $ONLY"

if [[ $ASSUME_YES -eq 0 ]]; then
  if [[ -e "$SITE_PATH/wp-config.php" && $SKIP_CORE -eq 0 ]]; then
    warn "$SITE_PATH already contains a WordPress install; it will be reinstalled"
    warn "and the database '$DB_NAME' will be emptied."
  fi
  printf '\n    Continue? [y/N] '
  read -r reply
  [[ "$reply" =~ ^[Yy] ]] || die "aborted"
fi

BUILD_START=$(date +%s)

# ------------------------------------------------------------- 10 — WordPress --

if [[ $SKIP_CORE -eq 0 ]]; then
  log "step 10 — WordPress core"

  if [[ $CREATE_DB -eq 1 ]]; then
    if command -v mysql >/dev/null 2>&1; then
      info "creating database $DB_NAME and user $DB_USER"
      mysql -h "$DB_HOST" <<SQL || warn "could not create the database; assuming it already exists"
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    else
      warn "--create-db was given but no mysql client is available"
    fi
  fi

  if [[ ! -f "$SITE_PATH/wp-load.php" ]]; then
    info "downloading WordPress core"
    wp_nopath core download --path="$SITE_PATH" --force >/dev/null
  fi

  info "writing wp-config.php"
  wp config create \
    --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" \
    --dbhost="$DB_HOST" --dbprefix="$DB_PREFIX" --force \
    --extra-php <<'PHP' >/dev/null
// Benchmarks measure the server, not WordPress's debug machinery.
define( 'WP_DEBUG', false );
// wp-cron off, and this one is not cosmetic: with it on, requests fire a
// loopback HTTP call back into the same server at unpredictable moments. That
// adds variance to every latency measurement, and on a single-worker server it
// deadlocks outright — the server waits on a request only it can serve.
// Turn it back on only for a scenario specifically about cron handling.
define( 'DISABLE_WP_CRON', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );
// Post revisions would multiply wp_posts by an amount that varies with how the
// generator ran, which would make two builds of the same profile disagree.
define( 'WP_POST_REVISIONS', false );
PHP

  wp db check >/dev/null 2>&1 || die "cannot reach the database as '$DB_USER'@'$DB_HOST' — check the credentials"

  info "installing WordPress"
  wp db reset --yes >/dev/null
  wp core install \
    --url="$SITE_URL" \
    --title="wp-test $PROFILE_NAME" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email >/dev/null

  # WordPress derives siteurl from the script path when it is installed from the
  # command line, so a docroot that is not the web root leaves the site
  # answering on <url>/public_html. Nothing later notices — the generator writes
  # content happily — and the fixture only reveals it as a redirect on the first
  # request measured. Say it outright instead.
  wp option update siteurl "$SITE_URL" >/dev/null
  wp option update home "$SITE_URL" >/dev/null

  # Pretty permalinks: benchmarking index.php?p=1 would measure the wrong thing.
  wp rewrite structure '/%postname%/' --hard >/dev/null 2>&1 || true
  wp option update blog_public 0 >/dev/null

  if [[ -n "$THEME" ]]; then
    info "installing theme $THEME"
    wp theme install "$THEME" --activate >/dev/null || warn "could not install theme $THEME; keeping the default"
  fi

  if [[ "${WOO_ENABLED:-0}" == "1" ]]; then
    info "installing WooCommerce"
    wp plugin install woocommerce --activate >/dev/null || die "could not install WooCommerce"
    wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json >/dev/null 2>&1 || true
    wp option update woocommerce_task_list_hidden yes >/dev/null 2>&1 || true
  fi

  ok "WordPress $(wp core version) installed"
else
  log "step 10 — skipped (--skip-core)"
  [[ -f "$SITE_PATH/wp-load.php" ]] || die "--skip-core was given but $SITE_PATH holds no WordPress install"
fi

# Outside the install block on purpose: with --skip-core the plugins still have
# to be there, or step 50 silently finds no Elementor and produces a fixture
# that looks fine and measures nothing.
install_plugin_pack

# ---------------------------------------------------- shared step environment --

export WP_TEST_PROFILE="$PROFILE_FILE"
export WP_TEST_STATE_DIR="$STATE_DIR"
[[ -n "$SEED" ]]  && export WP_TEST_SEED="$SEED"
[[ -n "$EPOCH" ]] && export WP_TEST_EPOCH="$EPOCH"

# Run one step across N shards and wait for all of them, failing if any fails.
run_sharded() {
  local script="$1" label="$2" workers="$3"
  local -a pids=()
  local rc=0

  if (( workers <= 1 )); then
    WP_TEST_SHARD="1/1" WP_TEST_SHARD_LABEL="$label" wp eval-file "$script"
    return $?
  fi

  for ((i = 1; i <= workers; i++)); do
    WP_TEST_SHARD="$i/$workers" WP_TEST_SHARD_LABEL="$label$i" wp eval-file "$script" &
    pids+=($!)
  done
  for pid in "${pids[@]}"; do
    wait "$pid" || rc=1
  done
  return $rc
}

# ------------------------------------------------------------------ 20 — media --

if want_step media; then
  log "step 20 — media library (${IMAGES} originals, up to ${IMAGE_MAX_WIDTH}px wide)"
  info "this is the slow part: every original is also resized to each"
  info "registered thumbnail size, exactly as a real upload would be"
  run_sharded "$ROOT/steps/20-media.php" "media" "$WORKERS" || die "media step failed"
  ok "media done"
fi

# ---------------------------------------------------------------- 30 — content --

if want_step content; then
  log "step 30 — posts, pages, comments and menus"
  WP_TEST_SHARD="1/1" wp eval-file "$ROOT/steps/30-content.php" || die "content step failed"
  ok "content done"
fi

# ------------------------------------------------------------ 40 — woocommerce --

if want_step woo && [[ "${WOO_ENABLED:-0}" == "1" ]]; then
  log "step 40 — WooCommerce (${WOO_PRODUCTS} products, ${WOO_ORDERS} orders)"
  run_sharded "$ROOT/steps/40-woocommerce.php" "woo" "$WORKERS" || die "woocommerce step failed"
  ok "woocommerce done"
fi

# -------------------------------------------------------------- 50 — elementor --

if want_step elementor && [[ "${ELEMENTOR_PAGES:-0}" -gt 0 ]]; then
  log "step 50 — Elementor pages (${ELEMENTOR_PAGES}, ${ELEMENTOR_MIN_SECTIONS}-${ELEMENTOR_MAX_SECTIONS} sections each)"
  info "these render from a JSON document on every uncached request — this is"
  info "the profile's PHP cost, and it does not show up in the disk figure"
  WP_TEST_SHARD="1/1" wp eval-file "$ROOT/steps/50-elementor.php" || die "elementor step failed"
  ok "elementor done"
fi

# --------------------------------------------------------------- 90 — manifest --

if want_step manifest; then
  log "step 90 — manifest"
  WP_TEST_SHARD="1/1" wp eval-file "$ROOT/steps/90-manifest.php" || die "manifest step failed"
fi

# Flush anything the bulk inserts left stale.
wp cache flush >/dev/null 2>&1 || true
wp rewrite flush --hard >/dev/null 2>&1 || true

BUILD_END=$(date +%s)
ELAPSED=$(( BUILD_END - BUILD_START ))

log "done in $((ELAPSED / 60))m $((ELAPSED % 60))s"
if [[ $SKIP_CORE -eq 0 ]]; then
  info "site   $SITE_URL"
  info "admin  $SITE_URL/wp-admin/  ($ADMIN_USER / $ADMIN_PASS)"
fi
info "search engines are disabled on this site (blog_public=0) — it is a fixture, not a site"
