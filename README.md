# Mass Open

A one-page website for **Mass Open** — growing the open source AI community in
New England. Built with the [Jekyll](https://jekyllrb.com/) flat-file static
site generator.

## How it's built

The home page is a single scrolling document made of anchored sections, with a
sticky nav that jumps to each anchor (`#mission`, `#events`, `#read`). The nav
also carries standalone pages (`/cfp/`, `/sponsors/`) and one external link,
the Ghost newsletter at news.massopen.ai.

```
.
├── _config.yml             # site config + navigation + content links
├── index.html              # the page: pulls in each section include
├── _layouts/
│   └── default.html        # HTML shell (head, nav, footer, scripts)
├── _includes/
│   ├── head.html           # <head> + meta
│   ├── nav.html            # sticky anchor navigation
│   ├── footer.html
│   └── sections/           # one include per page section
│       ├── hero.html
│       ├── mission.html
│       ├── events.html
│       ├── read.html
│       ├── cfp-form.html
│       └── benefits / audience / tiers.html   (Sponsors)
├── _data/
│   ├── events.yml          # upcoming events
│   ├── tiers.yml           # sponsorship table
│   └── pillars.yml / involved.yml
├── assets/
│   ├── css/style.css
│   ├── js/main.js          # mobile nav, scroll-spy
│   ├── js/cfp.js           # CFP nonce, counters, submit
│   ├── favicon.svg
│   └── mass-open-bg.svg    # tiling background
├── cfp.html                # /cfp/ call for papers page
├── cfp_token.php           # issues single-use form nonces
├── cfp_submit.php          # proposal intake + anti-spam
├── cfp_confirm.php         # verifies proposals from unknown addresses
├── cfp_admin.php           # review console (behind HTTP Basic auth)
├── subscribe_lib.php       # shared helpers (DB, tokens, Mailgun)
├── tools/                  # favicon + subscriber migration scripts
├── db_config.php           # DB credentials (read from env vars)
├── mail_config.php         # Mailgun + opt-in settings (read from env vars)
├── schema.sql              # subscriber tables (excluded from the build)
└── cfp-received.html       # /cfp-received/ landing page
```

## Editing content

- **Navigation:** edit the `nav:` list in `_config.yml`.
- **Section copy:** edit the relevant file in `_includes/sections/`.
- **Cards / steps:** edit `_data/pillars.yml` and `_data/involved.yml`.
- **Essay link:** edit `essay:` in `_config.yml`.

## Running locally

```bash
bundle install
bundle exec jekyll serve
# open http://localhost:4000
```

The build output is written to `_site/`.

## Newsletter — retired, now Ghost

The PHP double opt-in signup has been retired. Subscriptions live on the
self-hosted Ghost site at <https://news.massopen.ai/>, which handles
confirmation, unsubscribe and sending.

Removed: `submit.php`, `confirm.php`, `unsubscribe.php`,
`_includes/sections/join.html`, `confirmed.html`, `unsubscribed.html`, and the
signup handler in `assets/js/main.js`.

Kept on purpose:

- `subscribe_lib.php`, `db_config.php`, `mail_config.php` — the CFP still uses
  them. Two helpers inside (`mo_throttle_ok`, `mo_mailgun_address_ok`) are now
  unused; they are left alone so the CFP path is untouched.
- The `subscribers` and `signup_throttle` tables — `cfp_submit.php` reads
  `subscribers` so an already-confirmed address can skip proposal
  verification, and the table is the record of who consented and when.
  Nothing writes to either any more. See the header of `schema.sql`.

To move the existing list into Ghost:

```bash
tools/migrate-subscribers-to-ghost.sh          # writes a Ghost-ready CSV
```

Only `confirmed` addresses migrate as subscribed; `unsubscribed` carry across
suppressed; `pending` are never migrated.

### Linking to the newsletter

The URL is set once, as `news_url` in `_config.yml`. The nav resolves it via
`external: news_url`, and the hero button uses `{{ site.news_url }}`. One
exception: `_data/sept24-bos.yml` contains the URL literally, because Liquid
is not evaluated inside data files.

## News feed from Ghost

`_plugins/external_feed.rb` pulls the newsletter's RSS into the
`external_feed` collection so the home page can list recent posts.

The newsletter is a separate service, so it will sometimes be unreachable.
The plugin degrades instead of failing the build:

1. fetch the live feed; if it parses, use it and cache it
2. otherwise fall back to the last good copy in `.jekyll-cache/`
3. otherwise render the section with no items and a link to the newsletter

The cache is the point. Without it an outage would quietly empty the news
section on the next deploy; with it the site keeps showing the most recent
posts it ever saw. Every failure logs a warning naming what went wrong, and
the request has a 10-second timeout so a hung feed cannot hang the build.

The URL defaults to `https://news.massopen.ai/rss` and can be overridden in
`_config.yml`:

```yaml
news_feed_url: "https://news.massopen.ai/rss"
```

To see the fallback locally, point it at something that isn't there and
build — the site should still come out, with warnings.

## Agenda — published talks

`/agenda/` lists every event; each event page shows its running order; each
talk has its own page with the speaker's headshot, name and bio, the event and
date, and the topic and abstract.

These are real generated pages (`_plugins/agenda.rb`), not a JavaScript
widget, so every talk has a URL a speaker can share.

### Publishing

Scheduling happens in the database; publishing is a deliberate step.

```bash
php tools/agenda.php sync      # _data/events.yml  -> events table
php tools/agenda.php status    # what is scheduled, and what is not
php tools/agenda.php export    # accepted talks    -> _data/agenda.yml
bundle exec jekyll build       # publish
```

Two one-way flows, so ownership is never ambiguous: **events** are authored in
`_data/events.yml` and pushed into the database (which needs them only so a
talk can point at one); **agendas** are assembled in the database — review,
accept, schedule — and pulled back into git for the build.

Each event in `_data/events.yml` needs a `slug` (stable — never change it once
talks are scheduled) and a machine-readable `starts_on`.

### What reaches the public site

Only talks that are **both** `review_status = 'accepted'` **and**
`status = 'verified'`, and only these fields: speaker name, bio, topic,
abstract, headshot. Emails, IP addresses, reviewer notes, spam telemetry and
every rejected or unverified proposal stay in the database, so no template bug
can leak them.

Assign a talk to an event in the review console: pick **Scheduled at** and set
a **Running order** (low numbers first).

The running order is shown as a start time, mapped in `_data/slot_times.yml`:
slot 0 is 9:30am, 1 is 10am, 2 is 10:45am, 3 is 11:30am, 4 is 1pm, 5 is
1:45pm, 6 is 2:30pm, 7 is 3:30pm, 8 is 4:15pm, 9 is 5pm. Change a time, or add
another slot, by editing that file — no re-export needed, just a rebuild. A slot with no entry
falls back to showing its number. `status` reports any accepted talk
that still has no event. An event with nothing scheduled still appears, saying
the programme is not announced yet.

`_data/agenda.yml` is generated — do not hand-edit it; the next export wins.

### Speaker headshots

A talk page shows the speaker's photo next to their bio. There are two ways to
give a speaker one, tried in this order:

1. **A file named after them.** Drop the image in `assets/img/speakers/` named
   after the speaker — `Reva Schwartz` → `assets/img/speakers/reva-schwartz.jpg`.
   The match is on the slugified filename, so `Reva-Schwartz.JPG` works too and
   the extension doesn't matter. Nothing to re-export: this is a build-time
   lookup on the speaker's name, so it survives the next `agenda.php export`.
2. **The `Headshot` field in the review console.** A path under
   `/assets/img/speakers/`, or a full `https://` URL if the photo is hosted
   elsewhere. Stored in the database, so it takes precedence over a file and
   comes back on every export. Only those two shapes are accepted — the value
   goes straight into an `<img src>` on a public page.

With neither, the speaker card shows their initials, so a photo that hasn't
arrived yet leaves a filled circle rather than a broken image.

The photo also appears as a small circle beside the talk in the event's
running order. That one is decoration, so it is there only if the photo is —
no initials — and the rows reserve space for it only when somebody on that
bill has one, which keeps the talk titles lined up without carving out an
empty column on a list that has no photos at all.

Square images crop best — they're displayed as a 132px circle (104px on a
phone). Keep them small; nothing resizes them at build time.

Speakers do not upload photos through the CFP form; an organiser adds them.

There is a script for the common case — lifting the photos out of the event's
announcement post on the Ghost site:

```bash
tools/fetch-headshots.sh --dry-run     # show what it would take, and from where
tools/fetch-headshots.sh               # write them into assets/img/speakers/
```

It reads the scheduled speakers for the event out of `_data/agenda.yml`, then
gives each image to whichever of them is named closest to it — alt text first,
then the caption below, then the words above. Files land under the exact names
the templates look for, so a rebuild is all that's left. Existing files are
kept unless you pass `--force`.

Matching a photo to a name by scraping is guesswork, so the script is built to
be checked rather than trusted: `--dry-run` prints the plan without writing
anything, an image it can't place goes to `assets/img/speakers/unmatched/`
with the caption it sat beside, and it always ends by naming the speakers
still without a photo. Look at the faces before you publish.

`--event <slug>` and `--url <post>` point it at a different event. `--html
<file>` parses a page you saved from the browser, for when the site refuses a
scripted fetch. `--max-width <px>` downscales the result if ImageMagick is
installed. Nothing in `tools/` is published — `_config.yml` excludes it.

### Event images

An event can carry one image: a banner across the top of its agenda page, and
the thumbnail on its card at `/agenda/`. Same two routes as a headshot:

1. **A file named after the slug** — `assets/img/events/sept15-dc.jpg` for the
   event whose `slug` is `sept15-dc`. Case and extension don't matter.
2. **`image:` on the event in `_data/events.yml`**, which is where the rest of
   the event's wording already lives. A path under the site or a full
   `https://` URL, and it wins over a file. Add `image_alt:` when the picture
   carries something the title doesn't; the title is the alt text otherwise.

```yaml
- slug: sept15-dc
  image: /assets/img/events/sept15-dc.jpg
  image_alt: Speakers on stage at the DC State of the Stack venue
```

An event with no image renders exactly as before — the card keeps its ◇ and
the agenda page starts at the title. Nothing here touches `_data/agenda.yml`:
that file is rewritten by every export, and an event's picture is wording, not
schedule, so it belongs in git with the rest of the event.

Wide images work best. The banner crops to a strip (320px tall at most, less
on a phone) and the thumbnail to 16:9, both with `object-fit: cover` — so a
1200×630 post header, the usual shape, lands well in both. Nothing is resized
at build time, so save them at a sensible size first.

## Call for papers

`/cfp/` collects talk proposals — name, email, speaker bio, speaking topic and
an abstract capped at 2000 characters. Proposals land in `cfp_submissions` and
only count once `status` = `verified`:

```sql
SELECT name, email, topic, abstract FROM cfp_submissions WHERE status = 'verified';
```

How an address gets verified depends on whether we already know it:

| Submitter | What happens |
| --- | --- |
| Already a **confirmed subscriber** | Verified immediately (`verified_via = 'known_subscriber'`) — that address has already proved it owns a working mailbox. They get a receipt email. |
| **Anyone else** | Stored as `pending` with a one-time link emailed to them. Clicking it (and pressing the button) sets `verified_via = 'email'`. |

Both paths send email and return the identical message, so the form cannot be
used to work out who is on the mailing list.

### Anti-spam

Every submission, known address or not, has to clear all of these:

- **Two honeypots** — hidden `website` and `company_url` fields. Bots that fill
  every input trip at least one.
- **Invisible challenge** — the form posts a nonce fetched from
  `cfp_token.php` by the page's JavaScript. A bot that posts the form without
  running that JavaScript never has one.
- **Timing trap** — the nonce's issue time is recorded *server side*, so the
  client cannot lie about it. Submissions faster than
  `MASSOPEN_CFP_MIN_SECONDS` (default 5) are dropped; nonces older than
  `MASSOPEN_CFP_MAX_HOURS` (default 3) are refused.
- **Replay protection** — nonces are single-use, spent atomically, so a
  harvested one is worthless for a second submission.
- **Link stuffing** — more than five URLs across the bio, topic and abstract is
  treated as spam.
- **Volume caps** — `MASSOPEN_CFP_DAILY_IP` (default 5) per IP per day and
  `MASSOPEN_CFP_DAILY_EMAIL` (default 3) per address per day. Note the IP cap
  binds first when several people submit from one office network.

Detections that indicate a bot (honeypot, too-fast, link stuffing) return the
same success response as a real submission and silently discard it, so there is
no feedback to tune against. Failures a *person* might hit (expired nonce, over
the character limit, over quota) say what went wrong.

`fill_seconds` and `spam_score` are recorded on every stored row, so you can
tune the thresholds against real traffic instead of guessing.

### Reviewing proposals

`cfp_admin.php` is the review console: browse, filter, search, edit and export
proposals. It touches only `cfp_submissions`, so it is not a general database
tool — a breach there cannot reach the rest of the schema.

**It must sit behind HTTP Basic auth.** The page refuses to serve anything if
it cannot see an authenticated user, so a half-finished deploy fails closed
rather than publishing every proposal.

#### Option A — Apache does the auth (recommended)

```apache
<Files "cfp_admin.php">
    AuthType Basic
    AuthName "Mass Open CFP"
    AuthUserFile /etc/httpd/massopen.htpasswd
    Require valid-user
    # Forwards the verified user to PHP-FPM as REMOTE_USER.
    CGIPassAuth On
</Files>
```

```bash
sudo htpasswd -c /etc/httpd/massopen.htpasswd johnmark   # -c only the first time
sudo systemctl reload httpd
```

**Keep the `.htpasswd` file out of this repo.** Anything in the source tree
that is not excluded gets copied into `_site` and served — you would publish
your own password hash. `/etc/httpd/` is a fine home for it.

Without `CGIPassAuth On`, Apache verifies the password but PHP never sees
`REMOTE_USER`, and the page will fail closed with an explanation. If you
cannot enable it, use Option B.

#### Option B — PHP verifies the password

Set two environment variables and PHP checks the password itself, which also
keeps the page protected if the Apache rule is ever dropped:

```bash
php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
```

| Variable | Notes |
| --- | --- |
| `MASSOPEN_ADMIN_USER` | the username to accept |
| `MASSOPEN_ADMIN_PASSWORD_HASH` | output of the command above — the hash, never the password |

Setting both is fine and is the belt-and-braces option.

#### What you can edit

Name, email, topic, bio, abstract, headshot, review status and private
reviewer notes.
Every change is written to `cfp_revisions` with the field, the old and new
values, and who made it — these are words someone else wrote, so the original
is always recoverable.

`status` (did the submitter verify their address) is **read-only** here and
deliberately separate from `review_status` (new / shortlist / accepted /
rejected). They are different questions, and `cfp_confirm.php` matches on
`status = 'pending'`, so triage must never touch it.

Editing an address does not re-run verification, and the page says so.

"Export CSV" downloads whatever the current filter shows — useful for sharing
a shortlist with a committee that doesn't need a login.

#### Upgrading an existing database

The review columns, the revision table and `headshot` are new. `schema.sql`
carries the `ALTER TABLE … ADD COLUMN IF NOT EXISTS` statements near the CFP
section; they are safe to re-run.

### CFP settings

| Variable | Notes |
| --- | --- |
| `MASSOPEN_CFP_NOTIFY` | optional address emailed a copy of each verified proposal |
| `MASSOPEN_CFP_MIN_SECONDS` | minimum fill time, default 5 |
| `MASSOPEN_CFP_MAX_HOURS` | nonce lifetime, default 3 |
| `MASSOPEN_CFP_DAILY_IP` | proposals per IP per day, default 5 |
| `MASSOPEN_CFP_DAILY_EMAIL` | proposals per address per day, default 3 |

Prune spent nonces from cron:

```sql
DELETE FROM form_nonces WHERE issued_at < NOW() - INTERVAL 2 DAY;
```

## License

Released under [CC0 1.0 Universal](LICENSE).
