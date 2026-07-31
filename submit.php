<?php
/**
 * Mass Open email signup endpoint — step 1 of double opt-in.
 *
 * Accepts a POST with an `email` field (form-encoded or JSON), records it as
 * PENDING, and emails a one-time confirmation link via the Mailgun API.
 * Nothing is ever added to the mailing list here; only confirm.php can
 * promote a row to 'confirmed'.
 *
 * Always responds with JSON: { ok: bool, message: string }.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mo_json(405, false, 'Method not allowed.');
}

$config = mo_config();
$mail   = $config['mail'];

// The response is identical whether the address is new, already pending, or
// already confirmed. Anything else turns this form into an oracle for testing
// which addresses are on the list.
$GENERIC_OK = 'Thanks! Check your inbox for a confirmation link to finish signing up.';

/* ---------------------------------------------------------------------------
 * Input
 * ------------------------------------------------------------------------ */

$payload = $_POST;
if (!isset($payload['email'])) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $payload = $json;
        }
    }
}

// Honeypot: a field hidden from humans. Bots fill it in. Report success so
// they have no signal to adapt to, but do nothing.
if (trim((string) ($payload['website'] ?? '')) !== '') {
    mo_json(200, true, $GENERIC_OK);
}

$email = is_string($payload['email'] ?? null) ? trim($payload['email']) : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    mo_json(422, false, 'Please enter a valid email address.');
}
if (strlen($email) > 254) {
    mo_json(422, false, 'That email address is too long.');
}

$canonical = mo_canonical($email);

/* ---------------------------------------------------------------------------
 * Store as pending and send the confirmation link
 * ------------------------------------------------------------------------ */

try {
    $pdo = mo_pdo();

    if (!mo_throttle_ok($pdo, mo_client_ip(), $mail['ip_hourly_limit'])) {
        mo_json(429, false, 'Too many signups from this network. Please try again later.');
    }

    // Optional, metered pre-check. Fails open — see subscribe_lib.php.
    if ($mail['validate_emails'] && !mo_mailgun_address_ok($mail, $email)) {
        mo_json(422, false, 'That address looks undeliverable. Please check it and try again.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, status, confirm_sent_at,
                (confirm_sent_at > NOW() - INTERVAL :gap SECOND) AS sent_recently
           FROM subscribers
          WHERE email_canonical = :canonical'
    );
    $stmt->execute([':gap' => $mail['resend_seconds'], ':canonical' => $canonical]);
    $existing = $stmt->fetch();

    // Already on the list: say nothing revealing and send nothing. Re-sending
    // a confirmation to a confirmed subscriber is a spam vector.
    if ($existing && $existing['status'] === 'confirmed') {
        mo_json(200, true, $GENERIC_OK);
    }

    // Pending and we mailed them moments ago: don't send a duplicate.
    if ($existing && (int) $existing['sent_recently'] === 1) {
        mo_json(200, true, $GENERIC_OK);
    }

    $token   = mo_token();
    $hash    = mo_token_hash($token);
    $unsub   = mo_token();

    if ($existing) {
        // Pending or previously unsubscribed — issue a fresh token. An
        // unsubscribed address re-subscribing must confirm again.
        $stmt = $pdo->prepare(
            'UPDATE subscribers
                SET email = :email,
                    status = "pending",
                    confirm_token_hash = :hash,
                    confirm_expires_at = NOW() + INTERVAL :ttl HOUR,
                    confirm_sent_at = NOW(),
                    confirm_send_count = confirm_send_count + 1,
                    unsubscribe_token = COALESCE(unsubscribe_token, :unsub),
                    signup_ip = :ip,
                    signup_user_agent = :ua,
                    unsubscribed_at = NULL
              WHERE id = :id'
        );
        $stmt->execute([
            ':email' => $email,
            ':hash'  => $hash,
            ':ttl'   => $mail['confirm_ttl'],
            ':unsub' => $unsub,
            ':ip'    => mo_client_ip(),
            ':ua'    => mo_user_agent(),
            ':id'    => $existing['id'],
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO subscribers
                 (email, email_canonical, status, confirm_token_hash,
                  confirm_expires_at, confirm_sent_at, confirm_send_count,
                  unsubscribe_token, signup_ip, signup_user_agent)
             VALUES
                 (:email, :canonical, "pending", :hash,
                  NOW() + INTERVAL :ttl HOUR, NOW(), 1,
                  :unsub, :ip, :ua)'
        );
        $stmt->execute([
            ':email'     => $email,
            ':canonical' => $canonical,
            ':hash'      => $hash,
            ':ttl'       => $mail['confirm_ttl'],
            ':unsub'     => $unsub,
            ':ip'        => mo_client_ip(),
            ':ua'        => mo_user_agent(),
        ]);
    }
} catch (PDOException $e) {
    // A concurrent request won the unique-key race; the other one is sending.
    if ($e->getCode() === '23000') {
        mo_json(200, true, $GENERIC_OK);
    }
    error_log('Mass Open signup error: ' . $e->getMessage());
    mo_json(500, false, 'Something went wrong. Please try again later.');
}

/* ---------------------------------------------------------------------------
 * The confirmation email
 * ------------------------------------------------------------------------ */

$confirmUrl = $mail['site_url'] . '/confirm.php?token=' . urlencode($token);
$ttl        = $mail['confirm_ttl'];
$safeUrl    = htmlspecialchars($confirmUrl, ENT_QUOTES, 'UTF-8');

$text = <<<TXT
Confirm your Mass Open subscription

Someone (hopefully you) asked to join the Mass Open mailing list —
open source AI across New England and the Acela Corridor.

Confirm your address by opening this link:

  {$confirmUrl}

The link expires in {$ttl} hours.

If you didn't request this, ignore this email. You won't be added to the
list, and we won't email you again.

— Mass Open
TXT;

$html = <<<HTML
<div style="background:#050816;padding:32px 16px;font-family:Helvetica,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;background:#070c23;border:1px solid rgba(90,169,255,0.25);border-radius:14px;padding:36px 32px;color:#eaf2ff;">
    <h1 style="margin:0 0 20px;font-size:24px;color:#ffffff;">
      Confirm your <span style="color:#5aa9ff;">Mass Open</span> subscription
    </h1>
    <p style="margin:0 0 18px;color:#b9cdf0;line-height:1.6;">
      Someone (hopefully you) asked to join the Mass Open mailing list — open
      source AI across New England and the Acela Corridor.
    </p>
    <p style="margin:0 0 28px;color:#b9cdf0;line-height:1.6;">
      Click below to confirm your address. The link expires in {$ttl} hours.
    </p>
    <p style="margin:0 0 28px;">
      <a href="{$safeUrl}"
         style="display:inline-block;padding:14px 28px;background:#2f6df0;color:#ffffff;
                font-weight:bold;text-decoration:none;border-radius:8px;">
        Confirm my subscription
      </a>
    </p>
    <p style="margin:0 0 18px;color:#6f86b8;font-size:13px;line-height:1.6;">
      Or paste this into your browser:<br>
      <span style="color:#5aa9ff;word-break:break-all;">{$safeUrl}</span>
    </p>
    <p style="margin:24px 0 0;padding-top:18px;border-top:1px solid rgba(90,169,255,0.2);
              color:#6f86b8;font-size:13px;line-height:1.6;">
      If you didn't request this, ignore this email. You won't be added to the
      list, and we won't email you again.
    </p>
  </div>
</div>
HTML;

$sent = mo_mailgun_send(
    $mail,
    $email,
    'Confirm your Mass Open subscription',
    $text,
    $html,
    'double-optin-confirm'
);

if (!$sent['ok']) {
    // The row stays pending, so a retry will re-send. This is our failure,
    // not the subscriber's, so it's safe to report plainly.
    mo_json(502, false, "We couldn't send the confirmation email. Please try again shortly.");
}

// Deliberately the same 200 the no-send branches return. A 202-vs-200 split
// would leak whether an address was already on the list.
mo_json(200, true, $GENERIC_OK);
