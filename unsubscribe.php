<?php
/**
 * Mass Open — unsubscribe endpoint.
 *
 * Every message you send to a confirmed subscriber should carry a link to
 * {site}/unsubscribe.php?token={unsubscribe token}. Same GET-then-POST shape
 * as confirm.php, so a link-prefetching mail scanner cannot unsubscribe
 * someone on their behalf.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

$mail   = mo_config()['mail'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = trim((string) ($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));

function mo_unsub_failed(string $detail): void
{
    $site = mo_config()['mail']['site_url'];
    $d    = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');

    mo_render_page('Unsubscribe', <<<HTML
      <p class="section__eyebrow">Subscription</p>
      <h2 class="section__title">We couldn't process that link</h2>
      <p class="section__lead">{$d}</p>
      <p style="margin-top:28px;">
        <a class="btn btn--ghost" href="{$site}/">Back to the site</a>
      </p>
HTML, 400);
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    mo_unsub_failed('The unsubscribe link was incomplete. Try copying the whole URL from the email.');
}

try {
    $pdo = mo_pdo();

    $stmt = $pdo->prepare(
        'SELECT id, email, status FROM subscribers WHERE unsubscribe_token = :token'
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();

    if (!$row) {
        mo_unsub_failed('We could not find a subscription for that link.');
    }

    if ($row['status'] === 'unsubscribed') {
        header('Location: ' . $mail['site_url'] . '/unsubscribed/', true, 303);
        exit;
    }

    if ($method !== 'POST') {
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($row['email'], ENT_QUOTES, 'UTF-8');

        mo_render_page('Unsubscribe', <<<HTML
          <p class="section__eyebrow">Sorry to see you go</p>
          <h2 class="section__title">Unsubscribe</h2>
          <p class="section__lead">
            Confirm that you want to remove
            <strong style="color:#eaf2ff;">{$safeEmail}</strong> from the Mass Open list.
          </p>
          <form method="post" action="unsubscribe.php" style="margin-top:28px;">
            <input type="hidden" name="token" value="{$safeToken}">
            <button type="submit" class="btn btn--primary">Unsubscribe me</button>
          </form>
HTML);
    }

    // Keep the row rather than deleting it: it records that this address opted
    // out, so a later import cannot quietly re-add them.
    $stmt = $pdo->prepare(
        'UPDATE subscribers
            SET status = "unsubscribed",
                unsubscribed_at = NOW(),
                confirm_token_hash = NULL,
                confirm_expires_at = NULL
          WHERE id = :id'
    );
    $stmt->execute([':id' => $row['id']]);
} catch (PDOException $e) {
    error_log('Mass Open unsubscribe error: ' . $e->getMessage());
    mo_unsub_failed('Something went wrong on our end. Please try again shortly.');
}

header('Location: ' . $mail['site_url'] . '/unsubscribed/', true, 303);
exit;
