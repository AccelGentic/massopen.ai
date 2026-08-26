# massopen.ai

Jekyll site. `_plugins/` holds the generators (agenda pages, the Ghost news
feed); `_includes/sections/` holds the home page's sections; `_data/` holds the
event and agenda content.

## Delivering changes — bundle, never push

**Never `git push`.** Deliver finished work as a git bundle instead.

Claude has no write access to this repository: the GitHub App is not installed
for the AccelGentic org, so `git push` returns 403, and the GitHub MCP server's
write tools return `Resource not accessible by integration`. Reads are fine —
`git fetch` and `git clone` work normally.

So, when work is ready:

```sh
git bundle create news-thumbnails.bundle main..<branch>
git bundle verify news-thumbnails.bundle
```

Send the file to the user along with the command that applies it:

```sh
git fetch ./news-thumbnails.bundle <branch>:<branch>
```

Base the bundle on `main` rather than on the branch's remote ref — `main`
exists in every clone, so the bundle applies even when the remote branch has
been deleted after a merge. Commits already on `main` fetch as no-ops.

Do not offer to push as an alternative, and do not retry a push to see whether
access has changed.

### The stop hook

`~/.claude/stop-hook-git-check.sh` asks for a push whenever the branch is ahead
of `origin/<branch>` (or `origin/HEAD`). Under this workflow that request is
expected and should not be acted on — the bundle is the delivery.

Two things do genuinely quiet it, and are worth doing when they apply:

- After the user merges, they usually delete the remote branch. Run
  `git remote prune origin` so the stale `origin/<branch>` ref stops being the
  comparison point.
- Keep `origin/HEAD` set (`git remote set-head origin -a`); the hook falls back
  to it once `origin/<branch>` is gone.

## Building locally

`bundle exec jekyll build`. The news feed plugin fetches
`https://news.massopen.ai/rss` at build time and degrades to a cache, then to
an empty section, when that is unreachable — which it is from a sandbox, so
point `news_feed_url` at a local file server to test the news section:

```sh
bundle exec jekyll build --config _config.yml,local-feed.yml
```
