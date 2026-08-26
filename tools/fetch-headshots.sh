#!/usr/bin/env bash
#
# Mass Open — pull the speaker headshots out of an announcement post.
#
#   tools/fetch-headshots.sh                       # the DC post -> assets/img/speakers/
#   tools/fetch-headshots.sh --dry-run             # show the plan, download nothing
#   tools/fetch-headshots.sh --html saved.html     # parse a page you saved by hand
#   tools/fetch-headshots.sh --event oct01-bos --url https://news.massopen.ai/...
#
# Files land as assets/img/speakers/<speaker-slug>.<ext> — exactly the names
# the agenda templates look for, so a rebuild picks them up with nothing else
# to change. Existing files are left alone unless you pass --force.
#
# Matching an image to a speaker is guesswork, so it is done by proximity: the
# speaker names come from _data/agenda.yml, and each image goes to whichever
# scheduled speaker is named closest to it — in its alt text first, then the
# caption after it, then the words before it. One image per speaker; anything
# left over is still downloaded, into <out>/unmatched/, and listed at the end
# with the caption it sat next to, for you to rename. Always eyeball the
# result: --dry-run prints the plan without downloading anything.
#
# Needs: bash, curl, awk, sed. ImageMagick is optional (--max-width).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

URL="https://news.massopen.ai/agentic-101-in-washington-dc/"
HTML_FILE=""
EVENT="sept15-dc"
OUT="$ROOT/assets/img/speakers"
DRY_RUN=0
FORCE=0
MAX_WIDTH=""
MIN_BYTES=8000          # below this it is a logo, an icon or a spacer

die()  { printf '%s\n' "$*" >&2; exit 1; }
note() { printf '%s\n' "$*" >&2; }

usage() {
    sed -n '2,25p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

while [ $# -gt 0 ]; do
    case "$1" in
        --url)       URL="${2:?--url needs a value}"; shift 2 ;;
        --html)      HTML_FILE="${2:?--html needs a file}"; shift 2 ;;
        --event)     EVENT="${2:?--event needs a slug}"; shift 2 ;;
        --out)       OUT="${2:?--out needs a directory}"; shift 2 ;;
        --max-width) MAX_WIDTH="${2:?--max-width needs a number}"; shift 2 ;;
        --min-bytes) MIN_BYTES="${2:?--min-bytes needs a number}"; shift 2 ;;
        --dry-run)   DRY_RUN=1; shift ;;
        --force)     FORCE=1; shift ;;
        -h|--help)   usage 0 ;;
        *)           note "unknown option: $1"; usage 1 ;;
    esac
done

command -v curl >/dev/null || die "curl is not installed."

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
page="$work/page.html"

# --- the page ---------------------------------------------------------------
if [ -n "$HTML_FILE" ]; then
    [ -r "$HTML_FILE" ] || die "cannot read $HTML_FILE"
    cat "$HTML_FILE" > "$page"
    note "Reading $HTML_FILE"
else
    note "Fetching $URL"
    curl -fsSL --retry 3 --retry-delay 2 -A 'Mozilla/5.0 (massopen.ai headshot fetcher)' \
         "$URL" -o "$page" \
      || die "Could not fetch the post. Save it from the browser (Ctrl+S, and pick
'Web Page, HTML Only' so the image URLs stay pointed at the site) and re-run
with --html <file>. The images themselves are still downloaded from the site,
so that path needs network too."
fi
[ -s "$page" ] || die "The post came back empty."

# --- who we are looking for -------------------------------------------------
# Straight out of the published agenda, so the spelling matches what the site
# will look for at build time.
awk -v want="$EVENT" '
    /^  - slug: "/ { in_event = ($0 ~ "\"" want "\"") ; next }
    in_event && /^        speaker: "/ {
        line = $0
        sub(/^        speaker: "/, "", line)
        sub(/"[[:space:]]*$/, "", line)
        if (line != "") print line
    }
' "$ROOT/_data/agenda.yml" > "$work/speakers" || true

speaker_count=$(wc -l < "$work/speakers" | tr -d ' ')
[ "$speaker_count" -gt 0 ] \
  || die "No speakers found for event '$EVENT' in _data/agenda.yml.
Run 'php tools/agenda.php export' first, or pass a different --event."
note "Looking for $speaker_count speaker(s) scheduled at $EVENT."

slugify() {
    printf '%s' "$1" \
      | tr '[:upper:]' '[:lower:]' \
      | sed -e 's/[^a-z0-9]\{1,\}/-/g' -e 's/^-//' -e 's/-$//'
}

# --- every image in the post, in document order, with its context ------------
# One record per <img>: the tag's attributes, then the text around it with the
# markup stripped. A caption or a heading beside a photo is what names the
# person in it, so that text is the evidence the next pass matches against.
awk '
    function attr(tag, name,   re, s) {
        re = name "=\"[^\"]*\""
        if (match(tag, re)) {
            s = substr(tag, RSTART, RLENGTH)
            sub(name "=\"", "", s)
            sub(/"$/, "", s)
            return s
        }
        return ""
    }
    # Markup out, whitespace collapsed: what a reader would see.
    function text(html,   s) {
        s = html
        gsub(/<[^>]*>/, " ", s)
        gsub(/&nbsp;/, " ", s)
        gsub(/&amp;/, "\\&", s)
        gsub(/&#x27;|&#39;|&rsquo;|&lsquo;/, "\x27", s)
        gsub(/&#8212;|&mdash;|&ndash;/, "-", s)
        gsub(/[[:space:]]+/, " ", s)
        sub(/^ /, "", s)
        return s
    }
    # Largest URL in a srcset, for when src is a lazy-load placeholder.
    function widest(set,   n, i, parts, best, bestw, w, u) {
        if (set == "") return ""
        n = split(set, parts, ",")
        bestw = -1
        for (i = 1; i <= n; i++) {
            gsub(/^[[:space:]]+|[[:space:]]+$/, "", parts[i])
            u = parts[i]; sub(/[[:space:]].*$/, "", u)
            w = 0
            if (match(parts[i], /[0-9]+w/)) w = substr(parts[i], RSTART, RLENGTH - 1) + 0
            if (w > bestw) { bestw = w; best = u }
        }
        return best
    }
    BEGIN { RS = "<img"; prev_tail = "" }
    NR == 1 { prev_tail = text($0); next }
    {
        close_at = index($0, ">")
        if (close_at == 0) next
        tag  = substr($0, 1, close_at)
        rest = substr($0, close_at + 1)

        src = attr(tag, "src")
        if (src == "" || src ~ /^data:/) src = widest(attr(tag, "srcset"))
        if (src == "" || src ~ /^data:/) src = attr(tag, "data-src")
        if (src == "" || src ~ /^data:/) { prev_tail = text(rest); next }

        after = text(rest)
        printf "%s\t%s\t%s\t%s\n",
               src, attr(tag, "alt"),
               substr(prev_tail, length(prev_tail) > 240 ? length(prev_tail) - 240 : 1),
               substr(after, 1, 240)
        prev_tail = after
    }
' "$page" > "$work/candidates"

total=$(wc -l < "$work/candidates" | tr -d ' ')
note "Found $total image(s) in the page."
[ "$total" -gt 0 ] || die "No images in that page — is the URL right?"

# --- match each image to a speaker ------------------------------------------
# Closest mention wins, because a post runs photo, caption, photo, caption: the
# name before an image is usually the *previous* speaker's. Alt text counts as
# distance zero, then the caption after, then the words before. A speaker can
# only claim one image, so a stray shot at the end doesn't steal a name that
# already has its photo.
awk -F'\t' '
    function lastidx(hay, needle,   pos, at, off) {
        pos = 0; off = 1
        while ((at = index(substr(hay, off), needle)) > 0) {
            pos = off + at - 1
            off = pos + 1
        }
        return pos
    }
    function distance(alt, before, after, needle,   at) {
        if (index(tolower(alt), needle))          return 0
        if ((at = index(tolower(after), needle)))  return at
        if ((at = lastidx(tolower(before), needle))) return length(before) - at
        return -1
    }
    NR == FNR {
        n++
        name[n]  = $0
        full[n]  = tolower($0)
        c = split($0, p, " ")
        sur[n]   = tolower(p[c])
        surseen[sur[n]]++
        next
    }
    {
        url = $1; alt = $2; before = $3; after = $4
        best = 0; bestd = 1e9
        for (i = 1; i <= n; i++) {
            if (claimed[i]) continue
            d = distance(alt, before, after, full[i])
            # A caption reading only "Schwartz" still identifies her, as long
            # as no other speaker shares the surname — but a full-name match
            # anywhere beats it.
            if (d < 0 && length(sur[i]) >= 5 && surseen[sur[i]] == 1) {
                d = distance(alt, before, after, sur[i])
                if (d >= 0) d += 500
            }
            if (d >= 0 && d < bestd) { bestd = d; best = i }
        }
        # Evidence more than a paragraph away from the image is not evidence.
        if (best && bestd <= 800) {
            claimed[best] = 1
            printf "%s\t%s\t%s\n", url, name[best], substr(after, 1, 70)
        } else {
            # A literal dash, not an empty field: bash collapses a run of tabs
            # when IFS is a tab, and the evidence would slide into the name.
            printf "%s\t-\t%s\n", url, substr(after, 1, 70)
        }
    }
' "$work/speakers" "$work/candidates" > "$work/plan"

# --- download ---------------------------------------------------------------
mkdir -p "$OUT"
matched=0; skipped=0; unmatched=0; report=""

fetch_to() {   # fetch_to <url> <stem-without-extension>; prints the file written
    local url="$1" stem="$2" tmp ext magic original dest size

    # Ghost serves resized copies under /content/images/size/wNNN/ — ask for
    # the original by dropping that segment, and fall back if it isn't there.
    original="$(printf '%s' "$url" | sed 's#/size/w[0-9]\{1,\}/#/#')"

    tmp="$work/dl"
    if ! curl -fsL --retry 2 -A 'Mozilla/5.0 (massopen.ai headshot fetcher)' "$original" -o "$tmp"; then
        curl -fsL --retry 2 -A 'Mozilla/5.0 (massopen.ai headshot fetcher)' "$url" -o "$tmp" || return 1
    fi

    size=$(wc -c < "$tmp" | tr -d ' ')
    [ "$size" -ge "$MIN_BYTES" ] || return 2

    # Extension from the bytes, not the URL, which may not carry one.
    magic="$(head -c 16 "$tmp" | od -An -tx1 | tr -d ' \n')"
    case "$magic" in
        ffd8ff*)            ext=jpg ;;
        89504e47*)          ext=png ;;
        52494646*57454250*) ext=webp ;;
        47494638*)          ext=gif ;;
        *) case "$(printf '%s' "${url##*.}" | tr '[:upper:]' '[:lower:]')" in
               jpg|jpeg) ext=jpg ;; png) ext=png ;; webp) ext=webp ;; *) ext=jpg ;;
           esac ;;
    esac

    dest="$stem.$ext"
    mv "$tmp" "$dest"

    if [ -n "$MAX_WIDTH" ]; then
        if command -v magick >/dev/null; then
            magick "$dest" -resize "${MAX_WIDTH}x>" "$dest"
        elif command -v convert >/dev/null; then
            convert "$dest" -resize "${MAX_WIDTH}x>" "$dest"
        else
            note "  (--max-width ignored: ImageMagick is not installed)"
        fi
    fi

    printf '%s' "$dest"
}

host="$(printf '%s' "$URL" | sed -e 's#^\(https\{0,1\}://[^/]*\).*#\1#')"

while IFS=$'\t' read -r url name evidence; do
    [ -n "$url" ] || continue
    case "$url" in
        //*) url="https:$url" ;;
        /*)  url="$host$url" ;;
        http://*|https://*) ;;
        *)   url="$host/$url" ;;
    esac
    # Site furniture, not people.
    case "$(printf '%s' "$url" | tr '[:upper:]' '[:lower:]')" in
        *favicon*|*logo*|*icon*|*gravatar*|*profile_image*|*.svg) continue ;;
    esac

    [ "$name" = "-" ] && name=""

    if [ -n "$name" ]; then
        stem="$OUT/$(slugify "$name")"
        label="$name"
    else
        mkdir -p "$OUT/unmatched"
        stem="$OUT/unmatched/$(printf '%s' "${url##*/}" | sed -e 's/?.*$//' -e 's/\.[a-zA-Z]\{1,\}$//' | cut -c1-40)"
        label="(unidentified)"
    fi

    existing="$(ls "$stem".* 2>/dev/null | head -1 || true)"
    if [ -n "$existing" ] && [ "$FORCE" -eq 0 ]; then
        note "  = $label — already have ${existing#"$ROOT"/}"
        skipped=$((skipped + 1))
        [ -n "$name" ] && printf '%s\n' "$name" >> "$work/have"
        continue
    fi

    if [ "$DRY_RUN" -eq 1 ]; then
        note "  + $label <- $url"
        if [ -n "$name" ]; then
            matched=$((matched + 1)); printf '%s\n' "$name" >> "$work/have"
        else
            unmatched=$((unmatched + 1))
        fi
        continue
    fi

    if written="$(fetch_to "$url" "$stem")"; then
        note "  + $label -> ${written#"$ROOT"/}"
        if [ -n "$name" ]; then
            matched=$((matched + 1)); printf '%s\n' "$name" >> "$work/have"
        else
            unmatched=$((unmatched + 1))
            report="$report
  ${written#"$ROOT"/}
      sat next to: $evidence"
        fi
    else
        case $? in
            2) : ;;  # too small to be a headshot
            *) note "  ! could not download $url" ;;
        esac
    fi
done < "$work/plan"

# --- what is left to do -----------------------------------------------------
note ""
note "Matched $matched, skipped $skipped already present, $unmatched unidentified."

touch "$work/have"
missing=""
while IFS= read -r speaker; do
    [ -n "$speaker" ] || continue
    grep -Fqx -- "$speaker" "$work/have" && continue
    ls "$OUT/$(slugify "$speaker")".* >/dev/null 2>&1 && continue
    missing="$missing  $speaker
"
done < "$work/speakers"

if [ -n "$missing" ]; then
    note ""
    note "Still without a photo — ask them, or save one as"
    note "${OUT#"$ROOT"/}/<their-name>.jpg:"
    printf '%s' "$missing" >&2
fi

if [ -n "$report" ]; then
    note ""
    note "Downloaded but not identified. Rename each to <speaker-name>.<ext>"
    note "and move it up out of unmatched/:"
    printf '%s\n' "$report" >&2
fi

if [ "$DRY_RUN" -eq 0 ] && [ "$matched" -gt 0 ]; then
    note ""
    note "Check the faces are on the right names, then rebuild:"
    note "  bundle exec jekyll build"
fi
