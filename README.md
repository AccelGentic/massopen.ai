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
├── submit.php              # email signup endpoint (PHP)
└── db_config.php           # DB credentials (read from env vars)
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

## The signup form

The "Join" form posts to `submit.php`, which validates the address and stores it
in a MariaDB/MySQL `subscribers` table. Jekyll copies `submit.php` and
`db_config.php` into the build untouched, so deploy the site to a PHP-enabled
host for the form to work. Database credentials are read from environment
variables (`MASSOPEN_DB_HOST`, `MASSOPEN_DB_NAME`, `MASSOPEN_DB_USER`,
`MASSOPEN_DB_PASS`, `MASSOPEN_DB_PORT`).

## License

Released under [CC0 1.0 Universal](LICENSE).
