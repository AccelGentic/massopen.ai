<?php
/**
 * Mass Open — CFP review console.
 *
 * Browse, search, edit and export talk proposals. Scoped to cfp_submissions
 * only: it is deliberately not a general database tool, so a breach here
 * cannot reach the rest of the schema.
 *
 * AUTHENTICATION IS THE WEB SERVER'S JOB. Protect this file with HTTP Basic
 * auth (see the Apache snippet in README.md) so unauthenticated requests are
 * rejected before any of this code runs. The guard below refuses to serve the
 * page if it cannot see an authenticated user, so a misconfigured deploy fails
 * closed rather than publishing every proposal.
 *
 * Routes (all on this one file):
 *   GET  ?                      list, with filters + search
 *   GET  ?id=N                  one proposal, with its edit form and history
 *   GET  ?export=csv            current filter as CSV
 *   POST (action=save)          apply an edit, then redirect
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

const PER_PAGE = 50;

/** Fields an organiser may change. Everything else is read-only. */
const EDITABLE = ['name', 'email', 'topic', 'bio', 'abstract', 'review_status', 'reviewer_notes'];

const REVIEW_STATES = ['new', 'shortlist', 'accepted', 'rejected'];

/* ---------------------------------------------------------------------------
 * Authentication guard
 * ------------------------------------------------------------------------ */

/**
 * Identify the authenticated operator.
 *
 * Apache exposes the Basic-auth user as REMOTE_USER. Under PHP-FPM that only
 * arrives if the Authorization header is forwarded (`CGIPassAuth On`), so
 * several spellings are checked. Setting MASSOPEN_ADMIN_PASSWORD_HASH makes
 * PHP verify the password itself as well, which keeps the page protected even
 * if the web server rule is ever dropped.
 */
function admin_user(): string
{
    $hash = getenv('MASSOPEN_ADMIN_PASSWORD_HASH') ?: '';

    if ($hash !== '') {
        $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');
        $expected = getenv('MASSOPEN_ADMIN_USER') ?: '';

        $userOk = $expected === '' || hash_equals($expected, $user);
        if ($user === '' || !$userOk || !password_verify($pass, $hash)) {
            header('WWW-Authenticate: Basic realm="Mass Open CFP"');
            http_response_code(401);
            exit("Authentication required.\n");
        }
        return $user;
    }

    // ONLY these two. Both are set by the web server after it has verified the
    // password, so their presence is proof of authentication.
    //
    // PHP_AUTH_USER is deliberately NOT trusted here: it is populated straight
    // from the client's Authorization header, so anyone could send one and be
    // waved through if the server-side rule were missing. It is used above,
    // but only where this code verifies the password itself.
    foreach (['REMOTE_USER', 'REDIRECT_REMOTE_USER'] as $key) {
        $candidate = trim((string) ($_SERVER[$key] ?? ''));
        if ($candidate !== '') {
            return substr($candidate, 0, 64);
        }
    }

    // Fail closed: no evidence of authentication, so serve nothing.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "This page is not protected.\n\n" .
        "cfp_admin.php must sit behind HTTP Basic auth. Either add the Apache\n" .
        "rule from README.md (and CGIPassAuth On so REMOTE_USER is forwarded),\n" .
        "or set MASSOPEN_ADMIN_USER and MASSOPEN_ADMIN_PASSWORD_HASH so PHP can\n" .
        "check the password itself.\n\n" .
        "Refusing to serve proposals until one of those is in place.\n"
    );
}

$OPERATOR = admin_user();

// Never cache, never index. Belt and braces behind the auth.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

/** Submissions are untrusted free text — everything is escaped on the way out. */
function h(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * CSRF token. There is no session behind Basic auth, so this reuses the
 * existing single-use form_nonces table — the same mechanism the public forms
 * use. An attacker cannot read one (same-origin), and it is spent on use.
 */
function issue_nonce(PDO $pdo): string
{
    $nonce = mo_token();
    $stmt  = $pdo->prepare(
        'INSERT INTO form_nonces (nonce, issued_at, issued_ip) VALUES (:n, NOW(), :ip)'
    );
    $stmt->execute([':n' => $nonce, ':ip' => mo_client_ip()]);
    return $nonce;
}

function spend_nonce(PDO $pdo, string $nonce): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/', $nonce)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'UPDATE form_nonces SET used_at = NOW()
          WHERE nonce = :n AND used_at IS NULL
            AND issued_at > NOW() - INTERVAL 12 HOUR'
    );
    $stmt->execute([':n' => $nonce]);
    return $stmt->rowCount() === 1;
}

/** Build the WHERE clause shared by the list view and the CSV export. */
function build_filter(array $q): array
{
    $where = [];
    $args  = [];

    $status = $q['status'] ?? 'verified';
    if ($status !== 'any') {
        $where[]          = 'status = :status';
        $args[':status']  = $status;
    }

    $review = $q['review'] ?? 'any';
    if ($review !== 'any' && in_array($review, REVIEW_STATES, true)) {
        $where[]          = 'review_status = :review';
        $args[':review']  = $review;
    }

    $search = trim((string) ($q['q'] ?? ''));
    if ($search !== '') {
        // One placeholder per column. Native prepared statements (this project
        // sets EMULATE_PREPARES => false) reject a named placeholder that is
        // bound more than once, so :s cannot simply be repeated.
        $like  = '%' . $search . '%';
        $parts = [];
        foreach (['name', 'email', 'topic', 'abstract'] as $i => $col) {
            $parts[]            = "$col LIKE :s$i";
            $args[":s$i"]       = $like;
        }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $args];
}

function page_open(string $title, string $subtitle = ''): void
{
    $site = mo_config()['mail']['site_url'];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         '<meta name="robots" content="noindex, nofollow">',
         '<title>', h($title), ' — Mass Open CFP</title>',
         '<link rel="stylesheet" href="', h($site), '/assets/css/style.css">',
         '<style>',
         // A dense data table over the tiling circuit pattern is unreadable.
         // This is an internal tool, so legibility wins over the brand texture.
         'body{background-image:none;background-color:var(--bg)}',
         '.admin{max-width:1200px;margin:0 auto;padding:28px 24px 72px}',
         '.admin__bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin-bottom:22px}',
         '.admin h1{font-size:1.6rem;margin:0;color:#fff}',
         '.admin__sub{color:var(--ink-faint);font-size:.85rem;margin:4px 0 0}',
         '.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;margin-bottom:18px}',
         '.filters label{display:block;font-size:.75rem;text-transform:uppercase;letter-spacing:.14em;color:var(--accent);margin-bottom:5px}',
         '.filters select,.filters input{padding:9px 12px;font:inherit;font-size:.92rem;color:var(--ink);background:rgba(5,8,22,.8);border:1px solid var(--border-strong);border-radius:8px}',
         'table.grid{width:100%;border-collapse:collapse;font-size:.9rem}',
         'table.grid th,table.grid td{padding:11px 12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}',
         'table.grid thead th{background:rgba(47,109,240,.16);color:#fff;white-space:nowrap}',
         'table.grid tbody tr:hover{background:rgba(90,169,255,.05)}',
         'a.row-link{color:#fff;font-weight:700;text-decoration:none}a.row-link:hover{color:var(--accent)}',
         '.pill{display:inline-block;padding:2px 10px;border-radius:999px;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;border:1px solid var(--border-strong)}',
         '.pill--new{color:var(--ink-dim)}.pill--shortlist{color:#ffc46b;border-color:#ffc46b}',
         '.pill--accepted{color:#7ee787;border-color:#7ee787}.pill--rejected{color:#ff8a8a;border-color:#ff8a8a}',
         '.pill--pending{color:#ffc46b;border-color:#ffc46b}',
         '.panel{background:var(--bg-panel);border:1px solid var(--border);border-radius:14px;padding:26px;margin-bottom:20px}',
         '.panel h2{margin:0 0 16px;font-size:1.1rem;color:#fff}',
         '.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;font-size:.85rem;color:var(--ink-dim)}',
         '.meta b{display:block;color:var(--accent);font-size:.72rem;text-transform:uppercase;letter-spacing:.12em;margin-bottom:3px}',
         '.f{margin-bottom:18px}.f label{display:block;font-weight:700;font-size:.9rem;color:#fff;margin-bottom:6px}',
         '.f input,.f select,.f textarea{width:100%;padding:11px 13px;font:inherit;color:var(--ink);background:rgba(5,8,22,.8);border:1px solid var(--border-strong);border-radius:8px}',
         '.f textarea{min-height:120px;resize:vertical;line-height:1.6}',
         '.f .count{font-size:.78rem;color:var(--ink-faint);margin-top:5px}',
         '.hist{font-size:.82rem;color:var(--ink-dim)}',
         '.hist td{padding:8px 10px;border-bottom:1px solid var(--border)}',
         '.hist del{color:#ff8a8a;text-decoration:none;background:rgba(255,138,138,.09)}',
         '.hist ins{color:#7ee787;text-decoration:none;background:rgba(126,231,135,.09)}',
         '.flash{padding:12px 16px;border-radius:8px;border:1px solid #7ee787;color:#7ee787;margin-bottom:18px}',
         '</style></head><body><div class="admin">';
    echo '<div class="admin__bar"><div><h1>', h($title), '</h1>';
    if ($subtitle !== '') {
        echo '<p class="admin__sub">', h($subtitle), '</p>';
    }
    echo '</div>';
}

function page_close(): void
{
    echo '</div></body></html>';
}

function pill(string $value): string
{
    return '<span class="pill pill--' . h($value) . '">' . h($value) . '</span>';
}

/* ---------------------------------------------------------------------------
 * Request handling
 * ------------------------------------------------------------------------ */

try {
    $pdo = mo_pdo();

    /* --- Save an edit -------------------------------------------------- */
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $id = (int) ($_POST['id'] ?? 0);

        if (!spend_nonce($pdo, (string) ($_POST['nonce'] ?? ''))) {
            http_response_code(400);
            exit('This form expired. Go back, reload the proposal and try again.');
        }

        $stmt = $pdo->prepare('SELECT * FROM cfp_submissions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $before = $stmt->fetch();
        if (!$before) {
            http_response_code(404);
            exit('No such proposal.');
        }

        $changes = [];
        foreach (EDITABLE as $field) {
            if (!array_key_exists($field, $_POST)) {
                continue;
            }
            $new = trim((string) $_POST[$field]);

            if ($field === 'review_status' && !in_array($new, REVIEW_STATES, true)) {
                continue;
            }
            if ($field === 'email' && $new !== '' && !filter_var($new, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                exit('That email address is not valid.');
            }
            if ($new !== (string) $before[$field]) {
                $changes[$field] = $new;
            }
        }

        if ($changes) {
            $pdo->beginTransaction();

            $sets = [];
            $args = [':id' => $id];
            foreach ($changes as $field => $value) {
                $sets[]              = "`$field` = :$field";
                $args[":$field"]     = $value;
            }
            // Editing the address changes the de-duplication key too.
            if (isset($changes['email'])) {
                $sets[]                    = '`email_canonical` = :email_canonical';
                $args[':email_canonical']  = mo_canonical($changes['email']);
            }
            if (isset($changes['review_status'])) {
                $sets[] = '`reviewed_at` = NOW()';
            }

            $stmt = $pdo->prepare(
                'UPDATE cfp_submissions SET ' . implode(', ', $sets) . ' WHERE id = :id'
            );
            $stmt->execute($args);

            $log = $pdo->prepare(
                'INSERT INTO cfp_revisions (submission_id, field, old_value, new_value, edited_by)
                      VALUES (:id, :field, :old, :new, :by)'
            );
            foreach ($changes as $field => $value) {
                $log->execute([
                    ':id'    => $id,
                    ':field' => $field,
                    ':old'   => $before[$field],
                    ':new'   => $value,
                    ':by'    => $OPERATOR,
                ]);
            }

            $pdo->commit();
        }

        // Post/Redirect/Get so a refresh cannot replay the edit.
        $saved = $changes ? count($changes) : 0;
        header('Location: ?id=' . $id . '&saved=' . $saved, true, 303);
        exit;
    }

    /* --- CSV export ---------------------------------------------------- */
    if (isset($_GET['export'])) {
        [$where, $args] = build_filter($_GET);
        $stmt = $pdo->prepare(
            "SELECT id, created_at, name, email, topic, bio, abstract, status,
                    review_status, reviewer_notes, verified_at, spam_score, fill_seconds
               FROM cfp_submissions $where ORDER BY id DESC"
        );
        $stmt->execute($args);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cfp-proposals-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        $first = true;
        while ($row = $stmt->fetch()) {
            if ($first) {
                fputcsv($out, array_keys($row));
                $first = false;
            }
            fputcsv($out, $row);
        }
        if ($first) {
            fputcsv($out, ['id', 'created_at', 'name', 'email', 'topic']);
        }
        fclose($out);
        exit;
    }

    /* --- Single proposal ------------------------------------------------ */
    if (isset($_GET['id'])) {
        $id   = (int) $_GET['id'];
        $stmt = $pdo->prepare('SELECT * FROM cfp_submissions WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $p = $stmt->fetch();

        if (!$p) {
            http_response_code(404);
            page_open('Not found');
            echo '</div><p class="section__lead">No proposal with that id. <a href="?">Back to the list</a>.</p>';
            page_close();
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM cfp_revisions WHERE submission_id = :id ORDER BY edited_at DESC, id DESC'
        );
        $stmt->execute([':id' => $id]);
        $history = $stmt->fetchAll();

        $nonce = issue_nonce($pdo);

        page_open('Proposal #' . $p['id'], $p['topic']);
        echo '<a class="btn btn--ghost btn--sm" href="?">&larr; All proposals</a></div>';

        if (isset($_GET['saved'])) {
            $n = (int) $_GET['saved'];
            echo '<p class="flash">', $n === 0 ? 'No changes to save.' : 'Saved ' . $n . ' change(s).', '</p>';
        }

        echo '<div class="panel"><h2>Submission</h2><div class="meta">',
             '<div><b>Submitted</b>', h($p['created_at']), '</div>',
             '<div><b>Verification</b>', pill($p['status']),
                ($p['verified_via'] ? ' <span style="color:var(--ink-faint)">via ' . h($p['verified_via']) . '</span>' : ''), '</div>',
             '<div><b>Verified at</b>', h($p['verified_at'] ?? '—'), '</div>',
             '<div><b>Fill time</b>', h((string) ($p['fill_seconds'] ?? '—')), 's</div>',
             '<div><b>Link count</b>', h((string) $p['spam_score']), '</div>',
             '<div><b>Submitted from</b>', h($p['submit_ip'] ?? '—'), '</div>',
             '</div></div>';

        echo '<form method="post" class="panel">',
             '<h2>Edit</h2>',
             '<input type="hidden" name="id" value="', (int) $p['id'], '">',
             '<input type="hidden" name="nonce" value="', h($nonce), '">',
             '<input type="hidden" name="action" value="save">';

        echo '<div class="f"><label for="review_status">Review status</label><select id="review_status" name="review_status">';
        foreach (REVIEW_STATES as $s) {
            echo '<option value="', h($s), '"', $p['review_status'] === $s ? ' selected' : '', '>', h(ucfirst($s)), '</option>';
        }
        echo '</select></div>';

        echo '<div class="f"><label for="reviewer_notes">Reviewer notes <span style="font-weight:400;color:var(--ink-faint)">(private)</span></label>',
             '<textarea id="reviewer_notes" name="reviewer_notes" rows="3">', h($p['reviewer_notes']), '</textarea></div>';

        echo '<div class="f"><label for="name">Name</label><input id="name" name="name" maxlength="120" value="', h($p['name']), '"></div>';
        echo '<div class="f"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="254" value="', h($p['email']), '">',
             '<p class="count">Changing this does not re-run verification.</p></div>';
        echo '<div class="f"><label for="topic">Topic</label><input id="topic" name="topic" maxlength="200" value="', h($p['topic']), '"></div>';
        echo '<div class="f"><label for="bio">Speaker bio</label><textarea id="bio" name="bio" rows="5" maxlength="1500">', h($p['bio']), '</textarea></div>';
        echo '<div class="f"><label for="abstract">Abstract</label><textarea id="abstract" name="abstract" rows="10" maxlength="2000">', h($p['abstract']), '</textarea></div>';

        echo '<button type="submit" class="btn btn--primary">Save changes</button>',
             '</form>';

        echo '<div class="panel"><h2>Edit history</h2>';
        if (!$history) {
            echo '<p style="color:var(--ink-faint);margin:0">Never edited — this is exactly as submitted.</p>';
        } else {
            echo '<table class="hist" style="width:100%;border-collapse:collapse">';
            foreach ($history as $r) {
                echo '<tr><td style="white-space:nowrap;color:var(--ink-faint)">', h($r['edited_at']), '</td>',
                     '<td style="white-space:nowrap"><b>', h($r['field']), '</b></td>',
                     '<td style="white-space:nowrap;color:var(--ink-faint)">', h($r['edited_by'] ?? '—'), '</td>',
                     '<td><del>', h(mb_strimwidth((string) $r['old_value'], 0, 160, '…')), '</del><br>',
                     '<ins>', h(mb_strimwidth((string) $r['new_value'], 0, 160, '…')), '</ins></td></tr>';
            }
            echo '</table>';
        }
        echo '</div>';

        page_close();
        exit;
    }

    /* --- List ------------------------------------------------------------ */
    [$where, $args] = build_filter($_GET);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cfp_submissions $where");
    $stmt->execute($args);
    $total = (int) $stmt->fetchColumn();

    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * PER_PAGE;

    $stmt = $pdo->prepare(
        "SELECT id, created_at, name, email, topic, status, review_status, spam_score, fill_seconds
           FROM cfp_submissions $where
          ORDER BY id DESC LIMIT " . PER_PAGE . " OFFSET " . (int) $offset
    );
    $stmt->execute($args);
    $rows = $stmt->fetchAll();

    $qs = static function (array $over) : string {
        return '?' . http_build_query(array_merge(
            array_intersect_key($_GET, array_flip(['status', 'review', 'q', 'page'])),
            $over
        ));
    };

    page_open('CFP proposals', $total . ' matching · signed in as ' . $OPERATOR);
    echo '<a class="btn btn--ghost btn--sm" href="', h($qs(['export' => 'csv', 'page' => null])), '">Export CSV</a></div>';

    $status = $_GET['status'] ?? 'verified';
    $review = $_GET['review'] ?? 'any';
    echo '<form method="get" class="filters">',
         '<div><label for="status">Verification</label><select id="status" name="status">';
    foreach (['verified' => 'Verified', 'pending' => 'Pending', 'withdrawn' => 'Withdrawn', 'any' => 'Any'] as $v => $lbl) {
        echo '<option value="', h($v), '"', $status === $v ? ' selected' : '', '>', h($lbl), '</option>';
    }
    echo '</select></div><div><label for="review">Review</label><select id="review" name="review"><option value="any">Any</option>';
    foreach (REVIEW_STATES as $s) {
        echo '<option value="', h($s), '"', $review === $s ? ' selected' : '', '>', h(ucfirst($s)), '</option>';
    }
    echo '</select></div>',
         '<div style="flex:1 1 240px"><label for="q">Search</label>',
         '<input id="q" name="q" placeholder="name, email, topic or abstract" value="', h((string) ($_GET['q'] ?? '')), '"></div>',
         '<button class="btn btn--primary btn--sm" type="submit">Filter</button>',
         '</form>';

    if (!$rows) {
        echo '<p class="section__lead">Nothing matches that filter.</p>';
    } else {
        echo '<table class="grid"><thead><tr>',
             '<th>#</th><th>Submitted</th><th>Speaker</th><th>Topic</th>',
             '<th>Verification</th><th>Review</th><th>Fill</th><th>Links</th>',
             '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>',
                 '<td>', (int) $r['id'], '</td>',
                 '<td style="white-space:nowrap;color:var(--ink-faint)">', h(substr((string) $r['created_at'], 0, 10)), '</td>',
                 '<td><a class="row-link" href="?id=', (int) $r['id'], '">', h($r['name']), '</a>',
                    '<br><span style="color:var(--ink-faint);font-size:.82rem">', h($r['email']), '</span></td>',
                 '<td>', h(mb_strimwidth((string) $r['topic'], 0, 70, '…')), '</td>',
                 '<td>', pill($r['status']), '</td>',
                 '<td>', pill($r['review_status']), '</td>',
                 '<td style="color:var(--ink-faint)">', h((string) ($r['fill_seconds'] ?? '—')), 's</td>',
                 '<td style="color:', ((int) $r['spam_score'] > 2 ? '#ffc46b' : 'var(--ink-faint)'), '">', (int) $r['spam_score'], '</td>',
                 '</tr>';
        }
        echo '</tbody></table>';

        $pages = (int) ceil($total / PER_PAGE);
        if ($pages > 1) {
            echo '<nav class="pagination" style="margin-top:26px">';
            echo $page > 1
                ? '<a href="' . h($qs(['page' => $page - 1])) . '">&larr; Newer</a>'
                : '<span></span>';
            echo '<span>Page ', $page, ' of ', $pages, '</span>';
            echo $page < $pages
                ? '<a href="' . h($qs(['page' => $page + 1])) . '">Older &rarr;</a>'
                : '<span></span>';
            echo '</nav>';
        }
    }

    page_close();
} catch (PDOException $e) {
    error_log('Mass Open CFP admin error: ' . $e->getMessage());
    http_response_code(500);
    exit("Database error. Check the server log.\n");
}
