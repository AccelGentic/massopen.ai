<?php
/**
 * Mass Open — verify a CFP proposal from an address we don't already know.
 *
 * Same shape as confirm.php: GET renders a page with a button, POST performs
 * the verification. Mail security scanners pre-fetch links, so a GET that
 * verified would let them wave through proposals nobody actually sent.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

$mail   = mo_config()['mail'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$token  = trim((string) ($method === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '')));

function mo_cfp_failed(string $heading, string $detail): void
{
    $site = mo_config()['mail']['site_url'];
    $h    = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $d    = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');

    mo_render_page('Verification failed', <<<HTML
      <p class="section__eyebrow">Call for papers</p>
      <h2 class="section__title">{$h}</h2>
      <p class="section__lead">{$d}</p>
      <p style="margin-top:28px;">
        <a class="btn btn--primary" href="{$site}/cfp/">Submit again</a>
      </p>
HTML, 400);
}

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    mo_cfp_failed(
        'That link looks broken',
        'The verification link was incomplete. Some mail clients split long links across lines — try copying the whole URL, or submit again.'
    );
}

try {
    $pdo = mo_pdo();

    $stmt = $pdo->prepare(
        'SELECT id, name, topic, status,
                (verify_expires_at IS NOT NULL AND verify_expires_at < NOW()) AS expired
           FROM cfp_submissions
          WHERE verify_token_hash = :hash'
    );
    $stmt->execute([':hash' => mo_token_hash($token)]);
    $row = $stmt->fetch();

    if (!$row) {
        mo_cfp_failed(
            'This link has already been used',
            'If you already verified, your proposal is with us and there is nothing more to do. Otherwise, submit again for a fresh link.'
        );
    }

    if ($row['status'] === 'verified') {
        header('Location: ' . $mail['site_url'] . '/cfp-received/', true, 303);
        exit;
    }

    if ((int) $row['expired'] === 1) {
        mo_cfp_failed(
            'This link has expired',
            'Verification links are valid for ' . $mail['confirm_ttl'] . ' hours. Submit your proposal again and we will send a fresh one.'
        );
    }

    /* --- GET: show the button, write nothing --------------------------- */
    if ($method !== 'POST') {
        $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $safeTopic = htmlspecialchars($row['topic'], ENT_QUOTES, 'UTF-8');

        mo_render_page('Verify your proposal', <<<HTML
          <p class="section__eyebrow">One last step</p>
          <h2 class="section__title">Verify your proposal</h2>
          <p class="section__lead">
            Press the button to confirm you submitted
            <strong style="color:#eaf2ff;">{$safeTopic}</strong>.
          </p>
          <form method="post" action="cfp_confirm.php" style="margin-top:28px;">
            <input type="hidden" name="token" value="{$safeToken}">
            <button type="submit" class="btn btn--primary">Yes, this is my proposal</button>
          </form>
HTML);
    }

    /* --- POST: a human pressed the button ------------------------------ */
    $stmt = $pdo->prepare(
        'UPDATE cfp_submissions
            SET status = "verified",
                verified_via = "email",
                verified_at = NOW(),
                verify_token_hash = NULL,
                verify_expires_at = NULL
          WHERE id = :id
            AND status = "pending"'
    );
    $stmt->execute([':id' => $row['id']]);

    // Now that it's real, tell the organisers.
    if ($stmt->rowCount() === 1 && $mail['cfp_notify'] !== '') {
        $stmt = $pdo->prepare(
            'SELECT name, email, topic, bio, abstract FROM cfp_submissions WHERE id = :id'
        );
        $stmt->execute([':id' => $row['id']]);
        if ($full = $stmt->fetch()) {
            mo_cfp_notify_organisers(
                $mail,
                (int) $row['id'],
                $full['name'],
                $full['email'],
                $full['topic'],
                $full['bio'],
                $full['abstract']
            );
        }
    }
} catch (PDOException $e) {
    error_log('Mass Open CFP confirm error: ' . $e->getMessage());
    mo_cfp_failed(
        'Something went wrong',
        'We could not verify your proposal just now. Please try the link again in a few minutes.'
    );
}

header('Location: ' . $mail['site_url'] . '/cfp-received/', true, 303);
exit;
