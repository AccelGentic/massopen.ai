#!/usr/bin/env bash
#
# Port subscribers from the Mass Open PHP double opt-in table into Ghost.
#
# Tested on Fedora 44 (bash 5, dnf5, MariaDB client).
#
# Consent is the whole point of this migration:
#
#   status = 'confirmed'     -> imported, subscribed to the newsletter. These
#                               people clicked the confirmation link, so their
#                               opt-in carries over intact.
#   status = 'unsubscribed'  -> imported with the newsletter OFF. They are
#                               carried across precisely so a later import
#                               cannot quietly re-add them. Skipping them would
#                               lose that suppression.
#   status = 'pending'       -> NEVER imported. They never proved they control
#                               the mailbox. Importing them would turn a
#                               double opt-in list into a single opt-in one.
#
# By default the script only WRITES A CSV — nothing touches Ghost. Pass
# --import to push through the Ghost Admin API.
#
# Usage:
#   ./migrate-subscribers-to-ghost.sh                       # write ghost-members.csv
#   ./migrate-subscribers-to-ghost.sh --out /tmp/list.csv
#   ./migrate-subscribers-to-ghost.sh --import --url https://massopen.ai/news \
#                                     --key <admin-api-key>
#   ./migrate-subscribers-to-ghost.sh --import --dry-run    # show what would be sent
#
# Database credentials are read from the same environment variables the PHP
# uses, so this runs wherever submit.php runs:
#
#   MASSOPEN_DB_HOST  MASSOPEN_DB_PORT  MASSOPEN_DB_NAME
#   MASSOPEN_DB_USER  MASSOPEN_DB_PASS
#
# The Ghost Admin API key comes from Ghost Admin -> Settings -> Integrations ->
# Add custom integration. It looks like <hex id>:<hex secret>. Pass it with
# --key, or set GHOST_ADMIN_KEY. It is never echoed.

set -euo pipefail

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------
OUT="ghost-members.csv"
DO_IMPORT=0
DRY_RUN=0
INCLUDE_UNSUBSCRIBED=1
LABEL="massopen-php-import"
GHOST_URL="${GHOST_URL:-}"
GHOST_ADMIN_KEY="${GHOST_ADMIN_KEY:-}"
LIMIT=0
SLEEP_BETWEEN="0.15"

readonly RED=$'\e[31m' GREEN=$'\e[32m' YELLOW=$'\e[33m' DIM=$'\e[2m' RESET=$'\e[0m'

log()  { printf '%s\n' "$*" >&2; }
info() { log "${DIM}··${RESET} $*"; }
ok()   { log "${GREEN}✓${RESET} $*"; }
warn() { log "${YELLOW}!${RESET} $*"; }
die()  { log "${RED}✗${RESET} $*"; exit 1; }

usage() {
    sed -n '2,/^set -euo/p' "$0" | sed 's/^# \{0,1\}//; $d'
    exit "${1:-0}"
}

# ---------------------------------------------------------------------------
# Arguments
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
    case "$1" in
        --out)                 OUT="${2:?--out needs a path}"; shift 2 ;;
        --import)              DO_IMPORT=1; shift ;;
        --dry-run)             DRY_RUN=1; shift ;;
        --url)                 GHOST_URL="${2:?--url needs a value}"; shift 2 ;;
        --key)                 GHOST_ADMIN_KEY="${2:?--key needs a value}"; shift 2 ;;
        --label)               LABEL="${2:?--label needs a value}"; shift 2 ;;
        --limit)               LIMIT="${2:?--limit needs a number}"; shift 2 ;;
        --skip-unsubscribed)   INCLUDE_UNSUBSCRIBED=0; shift ;;
        -h|--help)             usage 0 ;;
        *)                     die "Unknown option: $1 (try --help)" ;;
    esac
done

# ---------------------------------------------------------------------------
# Dependencies — Fedora 44 package names
# ---------------------------------------------------------------------------
MYSQL_BIN=""
for candidate in mariadb mysql; do
    if command -v "$candidate" >/dev/null 2>&1; then MYSQL_BIN="$candidate"; break; fi
done
[[ -n "$MYSQL_BIN" ]] || die "No MariaDB client found.  sudo dnf install -y mariadb"

missing=()
for bin in awk curl openssl; do
    command -v "$bin" >/dev/null 2>&1 || missing+=("$bin")
done
if (( DO_IMPORT )); then
    command -v jq >/dev/null 2>&1 || missing+=("jq")
fi
if (( ${#missing[@]} )); then
    die "Missing: ${missing[*]}.  sudo dnf install -y ${missing[*]} mariadb"
fi

# ---------------------------------------------------------------------------
# Database connection
# ---------------------------------------------------------------------------
DB_HOST="${MASSOPEN_DB_HOST:-localhost}"
DB_PORT="${MASSOPEN_DB_PORT:-3306}"
DB_NAME="${MASSOPEN_DB_NAME:-}"
DB_USER="${MASSOPEN_DB_USER:-}"
DB_PASS="${MASSOPEN_DB_PASS:-}"

[[ -n "$DB_NAME" ]] || die "MASSOPEN_DB_NAME is not set."
[[ -n "$DB_USER" ]] || die "MASSOPEN_DB_USER is not set."

# Credentials go in a 0600 defaults file rather than the command line, where
# they would be visible to anyone running ps.
DEFAULTS_FILE="$(mktemp)"
chmod 600 "$DEFAULTS_FILE"
cleanup() { rm -f "$DEFAULTS_FILE" "${TMP_FILES[@]:-}"; }
TMP_FILES=()
trap cleanup EXIT

cat > "$DEFAULTS_FILE" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
EOF

db() { "$MYSQL_BIN" --defaults-extra-file="$DEFAULTS_FILE" --batch --skip-column-names "$DB_NAME"; }

info "Connecting to ${DB_NAME} at ${DB_HOST}:${DB_PORT} as ${DB_USER}"
echo 'SELECT 1' | db >/dev/null 2>&1 || die "Cannot connect to the database. Check MASSOPEN_DB_* variables."

# ---------------------------------------------------------------------------
# Counts, so the operator sees what is about to move
# ---------------------------------------------------------------------------
read -r n_confirmed n_pending n_unsub < <(
    printf '%s' "
        SELECT
          SUM(status='confirmed'),
          SUM(status='pending'),
          SUM(status='unsubscribed')
        FROM subscribers;" | db | awk '{print ($1==""?0:$1), ($2==""?0:$2), ($3==""?0:$3)}'
)

log ""
log "  ${GREEN}confirmed${RESET}     ${n_confirmed:-0}  -> import, subscribed"
if (( INCLUDE_UNSUBSCRIBED )); then
    log "  unsubscribed  ${n_unsub:-0}  -> import, newsletter off (keeps the opt-out)"
else
    log "  unsubscribed  ${n_unsub:-0}  ${DIM}-> skipped (--skip-unsubscribed)${RESET}"
fi
log "  ${YELLOW}pending${RESET}       ${n_pending:-0}  -> ${YELLOW}never imported${RESET} (never confirmed their address)"
log ""

# ---------------------------------------------------------------------------
# Extract
# ---------------------------------------------------------------------------
STATUS_FILTER="'confirmed'"
(( INCLUDE_UNSUBSCRIBED )) && STATUS_FILTER="'confirmed','unsubscribed'"
LIMIT_SQL=""
(( LIMIT > 0 )) && LIMIT_SQL="LIMIT $LIMIT"

RAW="$(mktemp)"; TMP_FILES+=("$RAW")

# Only rows whose address still looks deliverable. email_canonical is the
# de-duplicated form, so it also protects against case-variant duplicates.
printf '%s' "
    SELECT
      email_canonical,
      status,
      DATE_FORMAT(COALESCE(confirmed_at, created_at), '%Y-%m-%dT%H:%i:%SZ')
    FROM subscribers
    WHERE status IN ($STATUS_FILTER)
      AND email_canonical LIKE '%_@_%._%'
    ORDER BY id
    $LIMIT_SQL;" | db > "$RAW"

ROWS=$(wc -l < "$RAW" | tr -d ' ')
(( ROWS > 0 )) || die "Nothing to migrate."

# ---------------------------------------------------------------------------
# Write the Ghost-shaped CSV
# ---------------------------------------------------------------------------
# Ghost's importer reads: email, name, note, subscribed, created_at, labels.
# The PHP table never collected names, so that column stays empty and Ghost
# falls back to the address.
{
    printf 'email,name,note,subscribed,created_at,labels\n'
    awk -F'\t' -v label="$LABEL" 'BEGIN { OFS="," }
        {
            email = $1; status = $2; created = $3
            subscribed = (status == "confirmed") ? "true" : "false"
            note = "Imported from massopen.ai PHP list (status: " status ")"
            # Quote every text field; double any embedded quotes.
            gsub(/"/, "\"\"", email); gsub(/"/, "\"\"", note)
            printf "\"%s\",\"\",\"%s\",%s,\"%s\",\"%s\"\n", email, note, subscribed, created, label
        }' "$RAW"
} > "$OUT"

ok "Wrote ${ROWS} member(s) to ${OUT}"

if (( ! DO_IMPORT )); then
    log ""
    info "No changes made to Ghost. To load this list:"
    info "  Ghost Admin -> Members -> the ⚙ menu -> Import members -> upload ${OUT}"
    info "Or re-run with:  --import --url https://massopen.ai/news --key <admin-api-key>"
    exit 0
fi

# ---------------------------------------------------------------------------
# Import through the Ghost Admin API
# ---------------------------------------------------------------------------
[[ -n "$GHOST_URL" ]]       || die "--import needs --url (e.g. https://massopen.ai/news)"
[[ -n "$GHOST_ADMIN_KEY" ]] || die "--import needs --key (Ghost Admin API key) or GHOST_ADMIN_KEY"
[[ "$GHOST_ADMIN_KEY" == *:* ]] || die "Admin API key should look like <id>:<secret>."

GHOST_URL="${GHOST_URL%/}"
KEY_ID="${GHOST_ADMIN_KEY%%:*}"
KEY_SECRET="${GHOST_ADMIN_KEY##*:}"

b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }

# Ghost authenticates the Admin API with a short-lived HS256 JWT whose key id
# is the first half of the admin key and whose secret is the hex-decoded
# second half.
mint_token() {
    local now exp header payload signature
    now=$(date +%s); exp=$((now + 300))
    header=$(printf '{"alg":"HS256","typ":"JWT","kid":"%s"}' "$KEY_ID" | b64url)
    payload=$(printf '{"iat":%d,"exp":%d,"aud":"/admin/"}' "$now" "$exp" | b64url)
    signature=$(printf '%s' "${header}.${payload}" \
        | openssl dgst -sha256 -mac HMAC -macopt "hexkey:${KEY_SECRET}" -binary \
        | b64url)
    printf '%s.%s.%s' "$header" "$payload" "$signature"
}

api() {
    local method="$1" path="$2" body="${3:-}"
    local args=(-sS -X "$method"
        -H "Authorization: Ghost $(mint_token)"
        -H "Accept-Version: v5.0"
        -H "Content-Type: application/json"
        -w '\n%{http_code}')
    [[ -n "$body" ]] && args+=(-d "$body")
    curl "${args[@]}" "${GHOST_URL}/ghost/api/admin${path}"
}

info "Authenticating against ${GHOST_URL}"
resp=$(api GET "/site/") || die "Could not reach the Ghost Admin API."
code=$(tail -n1 <<<"$resp"); payload=$(sed '$d' <<<"$resp")
[[ "$code" == "200" ]] || die "Ghost Admin API returned HTTP ${code}. Check --url and --key. ${payload}"
ok "Connected to Ghost: $(jq -r '.site.title // "?"' <<<"$payload")"

# In Ghost 5 a member's subscription is a link to a newsletter, not a boolean,
# so the default newsletter's id is needed to actually subscribe anyone.
resp=$(api GET "/newsletters/?limit=all")
code=$(tail -n1 <<<"$resp"); payload=$(sed '$d' <<<"$resp")
[[ "$code" == "200" ]] || die "Could not list newsletters (HTTP ${code})."
NEWSLETTER_ID=$(jq -r '[.newsletters[] | select(.status=="active")][0].id // empty' <<<"$payload")
NEWSLETTER_NAME=$(jq -r '[.newsletters[] | select(.status=="active")][0].name // empty' <<<"$payload")
[[ -n "$NEWSLETTER_ID" ]] || die "No active newsletter found in Ghost. Create one first."
ok "Subscribing confirmed members to: ${NEWSLETTER_NAME}"

log ""
imported=0; skipped=0; failed=0
REPORT="${OUT%.csv}-report.csv"
printf 'email,result,detail\n' > "$REPORT"

while IFS=$'\t' read -r email status created; do
    [[ -n "$email" ]] || continue

    if [[ "$status" == "confirmed" ]]; then
        newsletters="[{\"id\":$(jq -Rn --arg i "$NEWSLETTER_ID" '$i')}]"
    else
        newsletters="[]"   # carried across, but not subscribed
    fi

    # created_at preserves when the person actually joined, so the Ghost list
    # keeps its real age and ordering rather than everyone appearing today.
    body=$(jq -cn \
        --arg email "$email" \
        --arg note "Imported from massopen.ai PHP list (status: $status)" \
        --arg label "$LABEL" \
        --arg created "$created" \
        --argjson newsletters "$newsletters" \
        '{members: [{email: $email, note: $note, created_at: $created,
                     labels: [{name: $label}], newsletters: $newsletters}]}')

    if (( DRY_RUN )); then
        printf '%s  %s\n' "$email" "${DIM}would import (${status})${RESET}" >&2
        printf '%s,dry-run,%s\n' "$email" "$status" >> "$REPORT"
        continue
    fi

    # send_email=false is essential: without it Ghost can mail every address
    # you import.
    resp=$(api POST "/members/?send_email=false" "$body")
    code=$(tail -n1 <<<"$resp"); payload=$(sed '$d' <<<"$resp")

    case "$code" in
        201)
            imported=$((imported + 1))
            printf '%s,imported,%s\n' "$email" "$status" >> "$REPORT"
            ;;
        422|409)
            # Ghost rejects an address that is already a member — that is a
            # skip, not a failure, so the script is safe to re-run.
            detail=$(jq -r '.errors[0].message // "already exists"' <<<"$payload" 2>/dev/null || echo "already exists")
            skipped=$((skipped + 1))
            printf '%s,skipped,%s\n' "$email" "${detail//,/;}" >> "$REPORT"
            ;;
        *)
            detail=$(jq -r '.errors[0].message // "unknown error"' <<<"$payload" 2>/dev/null || echo "HTTP $code")
            failed=$((failed + 1))
            warn "${email}: HTTP ${code} — ${detail}"
            printf '%s,failed,%s\n' "$email" "${detail//,/;}" >> "$REPORT"
            ;;
    esac

    printf '\r  %d imported  %d skipped  %d failed' "$imported" "$skipped" "$failed" >&2
    sleep "$SLEEP_BETWEEN"
done < "$RAW"

log ""
log ""
if (( DRY_RUN )); then
    ok "Dry run complete — nothing was sent to Ghost. See ${REPORT}"
else
    ok "Imported ${imported}, skipped ${skipped} (already present), failed ${failed}"
    info "Per-address results: ${REPORT}"
    (( failed == 0 )) || warn "Re-run the script to retry failures; existing members are skipped."
fi
