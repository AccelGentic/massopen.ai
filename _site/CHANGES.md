# Sponsors page — changed files

Extract over your checkout of `massopen.ai`, preserving paths. Every file below
sits at its normal repo location, except `_preview/`, which is not part of the
site (see the note at the bottom).

## New files

| Path | What it is |
| --- | --- |
| `sponsors.html` | The Sponsors page. Permalink `/sponsors/`; pulls in the three section includes. |
| `_includes/sections/benefits.html` | "Benefits" section — heading only, body intentionally empty. |
| `_includes/sections/audience.html` | "Audience" section — heading only, body intentionally empty. |
| `_includes/sections/tiers.html` | "Sponsorship Tiers" section — renders the table from `_data/tiers.yml`. |
| `_data/tiers.yml` | Table content. Fill in `brand:` and `attendee:` for each row. |

## Modified files

| Path | Change |
| --- | --- |
| `_config.yml` | Added the `Sponsors` nav entry, using a new `url:` key instead of `anchor:`. |
| `_includes/nav.html` | Nav now supports both in-page anchors and standalone page links; anchors are prefixed with the home URL so they still work from `/sponsors/`. |
| `assets/js/main.js` | Scroll-spy updated for the new `/#section` href form; only spies on sections present on the current page. |
| `assets/css/style.css` | Added `.page-hero`, `.visually-hidden`, and the `.table-wrap` / `.tiers` table styles. Nothing existing was changed. |

## The table

Two named columns, three named rows, driven by `_data/tiers.yml`:

|                     | Brand Sponsor | Attendee Sponsor |
| ------------------- | ------------- | ---------------- |
| **Description**     | *(blank)*     | *(blank)*        |
| **Amount**          | *(blank)*     | *(blank)*        |
| **Sponsor Benefit** | *(blank)*     | *(blank)*        |

Cells are left empty and render an em dash until you fill them in. To add copy,
edit `_data/tiers.yml` only — no markup changes needed:

```yaml
rows:
  - label: Description
    brand: "Logo on stage, website, and signage."
    attendee: "Covers tickets for students and early-career attendees."
```

## Preview file

`_preview/sponsors-standalone.html` is a single self-contained copy of the
rendered page — CSS, JS, background, and favicon all inlined — so you can
double-click it and see the design without running Jekyll. It is a preview
only: do not commit it into the site, and it will not reflect later edits to
`_data/tiers.yml`. Its top-nav links point at https://massopen.ai so they
aren't dead when opened from disk.

## Verifying

```bash
bundle exec jekyll build      # or: bundle exec jekyll serve
# then open http://localhost:4000/sponsors/
```

Built clean with Jekyll 4.4.1. The nav renders the Sponsors link on every page,
and it carries `is-active` only on `/sponsors/`.
