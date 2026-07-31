# Mass Open

A one-page website for **Mass Open** — growing the open source AI community in
New England. Built with the [Jekyll](https://jekyllrb.com/) flat-file static
site generator.

## How it's built

The page is a single scrolling document made of anchored sections, with a
sticky in-page navigation that jumps to each anchor (`#mission`, `#community`,
`#involved`, `#read`, `#join`).

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
│       ├── community.html
│       ├── involved.html
│       ├── read.html
│       └── join.html
├── _data/
│   ├── pillars.yml         # "Community" cards
│   └── involved.yml        # "Get Involved" steps
├── assets/
│   ├── css/style.css
│   ├── js/main.js          # mobile nav, scroll-spy, signup form
│   ├── favicon.svg
│   └── mass-open-bg.svg    # tiling background
├── submit.php              # step 1: record pending + send confirmation
├── confirm.php             # step 2: confirm (GET shows button, POST commits)
├── unsubscribe.php         # opt-out, same GET/POST shape
├── cfp.html                # /cfp/ call for papers page
├── cfp_token.php           # issues single-use form nonces
├── cfp_submit.php          # proposal intake + anti-spam
├── cfp_confirm.php         # verifies proposals from unknown addresses
├── subscribe_lib.php       # shared helpers (DB, tokens, Mailgun)
├── db_config.php           # DB credentials (read from env vars)
├── mail_config.php         # Mailgun + opt-in settings (read from env vars)
├── schema.sql              # subscriber tables (excluded from the build)
├── confirmed.html          # /confirmed/  landing page
├── unsubscribed.html       # /unsubscribed/ landing page
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

## The signup form — double opt-in

Nobody is added to the list by filling in the form. An address only becomes a
subscriber after the person proves they control the mailbox.

```
  Join form  ──POST──▶  submit.php        row created, status = pending
                            │
                            └── Mailgun API ──▶ confirmation email
                                                     │
                                       recipient clicks the link
                                                     │
                            ┌────────────────────────┘
                            ▼
                        confirm.php   GET  → "Confirm my subscription" button
                                      POST → status = confirmed  ──▶ /confirmed/
```

Only rows with `status = 'confirmed'` should ever be mailed:

```sql
SELECT email, unsubscribe_token FROM subscribers WHERE status = 'confirmed';
```

### Why the confirmation page has a button

Corporate mail security (Outlook Safe Links, Proofpoint, and friends)
pre-fetches every URL in an incoming message. If a plain `GET` confirmed the
subscription, those scanners would silently opt people in — which defeats the
whole point. So `GET /confirm.php?token=…` only *renders* a page; the actual
confirmation is a `POST`. `unsubscribe.php` works the same way.

### Why Mailgun's HTTP API rather than SMTP or `mail()`

The confirmation message is the one email that absolutely has to arrive — if it
lands in spam, the subscriber is lost silently. PHP's `mail()` from a web host
has no SPF/DKIM alignment and is routinely filtered. The Mailgun Messages API
also avoids an SMTP library, works over port 443, and returns a message id you
can trace in the Mailgun logs.

Note the flow deliberately does *not* use Mailgun Mailing Lists (which have
their own opt-in confirmation): that would move the subscriber record and the
confirmation copy into Mailgun. Here Mailgun is only the transport, and your
database stays the source of truth. Click and open tracking are switched off on
the confirmation message so the link the recipient sees is the real one.

### Setup

1. Create the tables:

   ```bash
   mysql -u <user> -p <database> < schema.sql
   ```

2. Set the environment variables in your web server / PHP-FPM pool:

   | Variable | Required | Notes |
   | --- | --- | --- |
   | `MASSOPEN_DB_HOST` / `_NAME` / `_USER` / `_PASS` / `_PORT` | yes | as before |
   | `MASSOPEN_MAILGUN_API_KEY` | yes | Mailgun private API key |
   | `MASSOPEN_MAILGUN_DOMAIN` | yes | e.g. `mg.massopen.ai` |
   | `MASSOPEN_MAILGUN_BASE` | no | `https://api.eu.mailgun.net` for EU accounts |
   | `MASSOPEN_SITE_URL` | no | public origin, used to build links. Default `https://massopen.ai` |
   | `MASSOPEN_MAIL_FROM` | no | default `Mass Open <hello@DOMAIN>` |
   | `MASSOPEN_MAIL_REPLY_TO` | no | |
   | `MASSOPEN_CONFIRM_TTL_HOURS` | no | link lifetime, default 48 |
   | `MASSOPEN_RESEND_SECONDS` | no | min gap between resends, default 90 |
   | `MASSOPEN_IP_HOURLY_LIMIT` | no | signups per IP per hour, default 10 |
   | `MASSOPEN_VALIDATE_EMAILS` | no | `1` enables the Mailgun validation pre-check (metered, off by default) |

3. Verify SPF/DKIM for the sending domain in the Mailgun dashboard before going
   live, or the confirmation mail will land in spam.

### Abuse handling

- **Enumeration** — the form returns the same body *and* the same status code
  whether an address is new, pending, or already subscribed, so it cannot be
  used to test who is on the list.
- **List bombing** — signups are capped per IP per hour (`signup_throttle`), and
  a given address is only mailed once per `MASSOPEN_RESEND_SECONDS`.
- **Bots** — a honeypot field is accepted and discarded.
- **Token safety** — confirmation tokens are 256-bit, single-use, expiring, and
  stored only as a SHA-256 hash, so a database leak cannot confirm anyone. The
  unsubscribe token is stored in clear because it must be reproducible for
  every future send; it grants nothing but unsubscribing.

Known residual: a fresh signup makes a Mailgun API call and so takes measurably
longer than a "nothing to do" response. A determined attacker could use that
timing difference as a weak membership oracle. Removing it entirely would mean
sending the mail asynchronously via a queue.

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

### Migrating the old single opt-in rows

Addresses collected by the previous version never confirmed, so they are not
double opt-in. Import them as `pending` and let them confirm, or leave them
alone — do not mark them `confirmed`. See the notes at the bottom of
`schema.sql`.

## License

Released under [CC0 1.0 Universal](LICENSE).
