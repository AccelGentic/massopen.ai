<?php
/**
 * Mass Open — call for papers submission endpoint.
 *
 * Accepts a talk proposal and stores it in `cfp_submissions`. A proposal only
 * counts once it reaches status 'verified':
 *
 *   - address already a confirmed subscriber → verified immediately, because
 *     that address has already proved it owns a working mailbox;
 *   - anything else → verified by clicking the link we email, exactly like
 *     the newsletter double opt-in.
 *
 * Every submission, known address or not, must additionally clear the
 * honeypots, the single-use nonce, and the timing trap.
 *
 * Always responds with JSON: { ok: bool, message: string }.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mo_json(405, false, 'Method not allowed.');
}

$mail = mo_config()['mail'];

// Both branches send mail, so this one message fits either outcome and does
// not reveal whether the address is already known to us.
$GENERIC_OK = 'Thanks! Check your email — we just sent you a note about your proposal.';

const CFP_ABSTRACT_MAX = 2000;
const CFP_BIO_MAX      = 1500;

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

$field = static fn(string $k): string => trim((string) ($payload[$k] ?? ''));

// Honeypots. Two of them: a plausible text field and a plausible URL field,
// both hidden. Bots that fill every input they find trip at least one.
// Answer with success so they get no signal to adapt to.
if ($field('website') !== '' || $field('company_url') !== '') {
    mo_json(200, true, $GENERIC_OK);
}

$name     = $field('name');
$email    = $field('email');
$bio      = $field('bio');
$topic    = $field('topic');
$abstract = $field('abstract');
$nonce    = $field('nonce');

if ($name === '' || mb_strlen($name) > 120) {
    mo_json(422, false, 'Please give your name (120 characters or fewer).');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    mo_json(422, false, 'Please enter a valid email address.');
}
if ($topic === '' || mb_strlen($topic) > 200) {
    mo_json(422, false, 'Please give a speaking topic (200 characters or fewer).');
}
if ($bio === '' || mb_strlen($bio) > CFP_BIO_MAX) {
    mo_json(422, false, 'Please include a short speaker bio (' . CFP_BIO_MAX . ' characters or fewer).');
}
if ($abstract === '') {
    mo_json(422, false, 'Please include a talk abstract.');
}
// Counted in characters, not bytes, so the limit matches what the browser's
// character counter shows for non-ASCII text.
if (mb_strlen($abstract) > CFP_ABSTRACT_MAX) {
    mo_json(422, false, 'The abstract is limited to ' . CFP_ABSTRACT_MAX . ' characters.');
}

$canonical = mo_canonical($email);

/* ---------------------------------------------------------------------------
 * Anti-spam
 * ------------------------------------------------------------------------ */

// Link stuffing is the signature of the bulk junk these forms attract.
$linkCount = preg_match_all('~https?://|www\.~i', $bio . ' ' . $abstract . ' ' . $topic);
if ($linkCount > 5) {
    // Looks like link spam. Accept-and-drop rather than explain the rule.
    mo_json(200, true, $GENERIC_OK);
}

try {
    $pdo = mo_pdo();

    /* --- Nonce: invisible challenge, timing trap and replay guard --------- */

    if ($nonce === '' || !preg_match('/^[a-f0-9]{64}$/', $nonce)) {
        // No nonce means the form was posted without running the page's
        // JavaScript, which no real submission does.
        mo_json(400, false, 'Your session expired. Please reload the page and try again.');
    }

    $stmt = $pdo->prepare(
        'SELECT used_at, TIMESTAMPDIFF(SECOND, issued_at, NOW()) AS elapsed
           FROM form_nonces
          WHERE nonce = :nonce'
    );
    $stmt->execute([':nonce' => $nonce]);
    $nonceRow = $stmt->fetch();

    if (!$nonceRow || $nonceRow['used_at'] !== null) {
        mo_json(400, false, 'Your session expired. Please reload the page and try again.');
    }

    $elapsed = (int) $nonceRow['elapsed'];

    // Nobody types a name, bio, topic and abstract in a handful of seconds.
    if ($elapsed < $mail['cfp_min_seconds']) {
        mo_json(200, true, $GENERIC_OK); // Accept-and-drop.
    }
    if ($elapsed > $mail['cfp_max_hours'] * 3600) {
        mo_json(400, false, 'This form has been open too long. Please reload the page and try again.');
    }

    // Atomically spend the nonce — two concurrent posts cannot both win.
    $stmt = $pdo->prepare(
        'UPDATE form_nonces SET used_at = NOW() WHERE nonce = :nonce AND used_at IS NULL'
    );
    $stmt->execute([':nonce' => $nonce]);
    if ($stmt->rowCount() !== 1) {
        mo_json(400, false, 'Your session expired. Please reload the page and try again.');
    }

    /* --- Volume caps ------------------------------------------------------ */

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM cfp_submissions
          WHERE submit_ip = :ip AND created_at > NOW() - INTERVAL 1 DAY'
    );
    $stmt->execute([':ip' => mo_client_ip()]);
    if ((int) $stmt->fetchColumn() >= $mail['cfp_daily_ip']) {
        mo_json(429, false, 'That is a lot of proposals from one place today. Please email us instead.');
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM cfp_submissions
          WHERE email_canonical = :canonical AND created_at > NOW() - INTERVAL 1 DAY'
    );
    $stmt->execute([':canonical' => $canonical]);
    if ((int) $stmt->fetchColumn() >= $mail['cfp_daily_email']) {
        mo_json(429, false, 'You have already sent us several proposals today. Please email us instead.');
    }

    /* --- Is this a known, confirmed subscriber? --------------------------- */

    $stmt = $pdo->prepare(
        'SELECT id FROM subscribers
          WHERE email_canonical = :canonical AND status = "confirmed"'
    );
    $stmt->execute([':canonical' => $canonical]);
    $isKnown = (bool) $stmt->fetchColumn();

    $token = $isKnown ? null : mo_token();

    $stmt = $pdo->prepare(
        'INSERT INTO cfp_submissions
             (name, email, email_canonical, bio, topic, abstract,
              status, verified_via, verify_token_hash, verify_expires_at,
              verify_sent_at, verified_at, spam_score, fill_seconds,
              submit_ip, submit_user_agent)
         VALUES
             (:name, :email, :canonical, :bio, :topic, :abstract,
              :status, :via, :hash, :expires,
              NOW(), :verified_at, :score, :fill,
              :ip, :ua)'
    );
    $stmt->execute([
        ':name'         => $name,
        ':email'        => $email,
        ':canonical'    => $canonical,
        ':bio'          => $bio,
        ':topic'        => $topic,
        ':abstract'     => $abstract,
        ':status'       => $isKnown ? 'verified' : 'pending',
        ':via'          => $isKnown ? 'known_subscriber' : null,
        ':hash'         => $token === null ? null : mo_token_hash($token),
        ':expires'      => $isKnown ? null : date('Y-m-d H:i:s', time() + $mail['confirm_ttl'] * 3600),
        ':verified_at'  => $isKnown ? date('Y-m-d H:i:s') : null,
        ':score'        => min(255, $linkCount),
        ':fill'         => $elapsed,
        ':ip'           => mo_client_ip(),
        ':ua'           => mo_user_agent(),
    ]);

    $submissionId = (int) $pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('Mass Open CFP error: ' . $e->getMessage());
    mo_json(500, false, 'Something went wrong. Please try again later.');
}

/* ---------------------------------------------------------------------------
 * Mail
 * ------------------------------------------------------------------------ */

$safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeTopic = htmlspecialchars($topic, ENT_QUOTES, 'UTF-8');

if ($isKnown) {
    // Already a confirmed subscriber: nothing to verify, just a receipt.
    $text = <<<TXT
Thanks for your Mass Open talk proposal

We received your proposal:

  {$topic}

Because you're already on the Mass Open list, there's nothing else for you
to do — we'll be in touch once the programme comes together.

— Mass Open
TXT;

    $html = <<<HTML
<div style="background:#050816;padding:32px 16px;font-family:Helvetica,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;background:#070c23;border:1px solid rgba(90,169,255,0.25);border-radius:14px;padding:36px 32px;color:#eaf2ff;">
    <h1 style="margin:0 0 20px;font-size:24px;color:#ffffff;">Proposal received</h1>
    <p style="margin:0 0 18px;color:#b9cdf0;line-height:1.6;">
      Thanks {$safeName} — we have your talk proposal:
    </p>
    <p style="margin:0 0 24px;padding:14px 18px;background:rgba(47,109,240,0.14);
              border-radius:8px;color:#ffffff;font-weight:bold;">{$safeTopic}</p>
    <p style="margin:0;color:#b9cdf0;line-height:1.6;">
      You're already on the Mass Open list, so there's nothing else to do.
      We'll be in touch once the programme comes together.
    </p>
  </div>
</div>
HTML;

    mo_mailgun_send($mail, $email, 'We received your Mass Open talk proposal', $text, $html, 'cfp-receipt');
} else {
    $verifyUrl = $mail['site_url'] . '/cfp_confirm.php?token=' . urlencode((string) $token);
    $safeUrl   = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
    $ttl       = $mail['confirm_ttl'];

    $text = <<<TXT
Verify your Mass Open talk proposal

We received a talk proposal for Mass Open in your name:

  {$topic}

Confirm it really came from you by opening this link:

  {$verifyUrl}

The link expires in {$ttl} hours. Until then the proposal is on hold.

If you didn't submit this, ignore this email and nothing will happen.

— Mass Open
TXT;

    $html = <<<HTML
<div style="background:#050816;padding:32px 16px;font-family:Helvetica,Arial,sans-serif;">
  <div style="max-width:520px;margin:0 auto;background:#070c23;border:1px solid rgba(90,169,255,0.25);border-radius:14px;padding:36px 32px;color:#eaf2ff;">
    <h1 style="margin:0 0 20px;font-size:24px;color:#ffffff;">
      Verify your <span style="color:#5aa9ff;">Mass Open</span> proposal
    </h1>
    <p style="margin:0 0 18px;color:#b9cdf0;line-height:1.6;">
      We received a talk proposal in your name:
    </p>
    <p style="margin:0 0 24px;padding:14px 18px;background:rgba(47,109,240,0.14);
              border-radius:8px;color:#ffffff;font-weight:bold;">{$safeTopic}</p>
    <p style="margin:0 0 28px;color:#b9cdf0;line-height:1.6;">
      Confirm it came from you. The link expires in {$ttl} hours, and until
      then the proposal is on hold.
    </p>
    <p style="margin:0 0 28px;">
      <a href="{$safeUrl}"
         style="display:inline-block;padding:14px 28px;background:#2f6df0;color:#ffffff;
                font-weight:bold;text-decoration:none;border-radius:8px;">
        Verify my proposal
      </a>
    </p>
    <p style="margin:0 0 18px;color:#6f86b8;font-size:13px;line-height:1.6;">
      Or paste this into your browser:<br>
      <span style="color:#5aa9ff;word-break:break-all;">{$safeUrl}</span>
    </p>
    <p style="margin:24px 0 0;padding-top:18px;border-top:1px solid rgba(90,169,255,0.2);
              color:#6f86b8;font-size:13px;line-height:1.6;">
      If you didn't submit this, ignore this email and nothing will happen.
    </p>
  </div>
</div>
HTML;

    $sent = mo_mailgun_send($mail, $email, 'Verify your Mass Open talk proposal', $text, $html, 'cfp-verify');

    if (!$sent['ok']) {
        mo_json(502, false, "We couldn't send the verification email. Please try again shortly.");
    }
}

// Optional heads-up to the organisers, for proposals that are already good.
if ($isKnown && $mail['cfp_notify'] !== '') {
    mo_cfp_notify_organisers($mail, $submissionId, $name, $email, $topic, $bio, $abstract);
}

mo_json(200, true, $GENERIC_OK);
