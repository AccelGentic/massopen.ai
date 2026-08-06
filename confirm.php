<?php
/**
 * Mass Open — step 2 of double opt-in: confirm a pending subscription.
 *
 * GET  /confirm.php?token=…  shows a confirmation page with a single button.
 * POST /confirm.php          performs the confirmation, then redirects.
 *
 * Why the extra button instead of confirming on GET:
 * corporate mail security (Outlook Safe Links, Proofpoint, and friends)
 * pre-fetches every URL in an incoming message. A GET that confirms would let
 * those scanners silently opt people in, which defeats the entire point of
 * double opt-in. Requiring a POST means a human pressed the button.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

$mail   = mo_config()['mail'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = (string) ($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''));
$token  = trim($token);

/** Error page shared by every failure path. */
function mo_confirm_failed(string $heading, string $detail): void
{
    $site = mo_config()['mail']['site_url'];
    $h    = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $d    = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');

    mo_render_page('Confirmation failed', <<<HTML
      <p class="section__eyebrow">Subscription</p>
      <h2 class="section__title">{$h}</h2>
      <p class="section__lead">{$d}</p>
      <p style="margin-top:28px;">
        <a class="btn btn--primary" href="{$site}/#join">Sign up again</a>
      </p>
HTML, 400);
}

// A token is 64 hex characters. Reject anything else before touching the DB.
if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    mo_confirm_failed(
        'That link looks broken',
        'The confirmation link was incomplete. Some mail clients split long links across lines — try copying the whole URL, or just sign up again.'
    );
}

try {
    $pdo = mo_pdo();

    $stmt = $pdo->prepare(
        'SELECT id, email, status,
                (confirm_expires_at IS NOT NULL AND confirm_expires_at < NOW()) AS expired
           FROM subscribers
          WHERE confirm_token_hash = :hash'
    );
    $stmt->execute([':hash' => mo_token_hash($token)]);
    $row = $stmt->fetch();

    if (!$row) {
        // Either a bad token, or a good one already spent — the token is
        // cleared on confirmation, so a second click lands here.
        mo_confirm_failed(
            'This link has already been used',
            'If you already confirmed, you are on the list and nothing more is needed. Otherwise, sign up again to get a fresh link.'
        );
    }

    if ($row['status'] === 'confirmed') {
        header('Location: ' . $mail['site_url'] . '/confirmed/', true, 303);
        exit;
    }

    if ((int) $row['expired'] === 1) {
        mo_confirm_failed(
            'This link has expired',
            'Confirmation links are valid for ' . $mail['confirm_ttl'] . ' hours. Sign up again and we will send a fresh one.'
        );
    }

    /* ---------------------------------------------------------------------
     * GET — show the button. Nothing is written yet.
     * ------------------------------------------------------------------ */
    if ($method !== 'POST') {
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8');

        mo_render_page('Confirm your subscription', <<<HTML
          <h2 class="section__title">ONE LAST STEP!</h2>
          <h2 class="section__title">Please confirm your subscription</h2>
          <p class="section__lead">
            Press the button to add <strong style="color:#eaf2ff;">{$safeEmail}</strong>
            to the Mass Open list.
          </p>
          <form method="post" action="confirm.php" style="margin-top:28px;">
            <input type="hidden" name="token" value="{$safeToken}">
            <button type="submit" class="btn btn--primary">Yes, confirm my subscription</button>
          </form>
HTML);
    }

    /* ---------------------------------------------------------------------
     * POST — a human pressed the button. Confirm and spend the token.
     * ------------------------------------------------------------------ */
    $stmt = $pdo->prepare(
        'UPDATE subscribers
            SET status = "confirmed",
                confirmed_at = NOW(),
                confirm_token_hash = NULL,
                confirm_expires_at = NULL,
                confirm_ip = :ip,
                confirm_user_agent = :ua
          WHERE id = :id
            AND status = "pending"'
    );
    $stmt->execute([
        ':ip' => mo_client_ip(),
        ':ua' => mo_user_agent(),
        ':id' => $row['id'],
    ]);
} catch (PDOException $e) {
    error_log('Mass Open confirm error: ' . $e->getMessage());
    mo_confirm_failed(
        'Something went wrong',
        'We could not confirm your subscription just now. Please try the link again in a few minutes.'
    );
}

// Post/Redirect/Get, so a refresh does not re-submit a spent token.
header('Location: ' . $mail['site_url'] . '/confirmed/', true, 303);
exit;
