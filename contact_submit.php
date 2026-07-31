<?php
/**
 * Mass Open — contact form submission endpoint.
 *
 * Accepts a message and emails it straight to the organisers
 * (mail_config.php's `contact_to`). There is no mailing list involved here,
 * so unlike submit.php / cfp_submit.php there is nothing to verify or store —
 * the message is simply relayed, with Reply-To set to the sender so a reply
 * goes straight back to them.
 *
 * Still needs the same anti-spam basics as the other forms: honeypots, a
 * single-use nonce, and a timing trap.
 *
 * Always responds with JSON: { ok: bool, message: string }.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mo_json(405, false, 'Method not allowed.');
}

$mail = mo_config()['mail'];

$GENERIC_OK = "Thanks! We'll be in touch.";

const CONTACT_MESSAGE_MAX = 5000;

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

$name    = $field('name');
$email   = $field('email');
$subject = $field('subject');
$message = $field('message');
$nonce   = $field('nonce');

if ($name === '' || mb_strlen($name) > 120) {
    mo_json(422, false, 'Please give your name (120 characters or fewer).');
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    mo_json(422, false, 'Please enter a valid email address.');
}
if ($subject === '' || mb_strlen($subject) > 200) {
    mo_json(422, false, 'Please give a subject (200 characters or fewer).');
}
if ($message === '') {
    mo_json(422, false, 'Please include a message.');
}
// Counted in characters, not bytes, so the limit matches what the browser's
// character counter shows for non-ASCII text.
if (mb_strlen($message) > CONTACT_MESSAGE_MAX) {
    mo_json(422, false, 'The message is limited to ' . CONTACT_MESSAGE_MAX . ' characters.');
}

/* ---------------------------------------------------------------------------
 * Anti-spam
 * ------------------------------------------------------------------------ */

// Link stuffing is the signature of the bulk junk these forms attract.
$linkCount = preg_match_all('~https?://|www\.~i', $subject . ' ' . $message);
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

    // Nobody types a name, subject and message in a couple of seconds.
    if ($elapsed < $mail['contact_min_seconds']) {
        mo_json(200, true, $GENERIC_OK); // Accept-and-drop.
    }
    if ($elapsed > $mail['contact_max_hours'] * 3600) {
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
} catch (PDOException $e) {
    error_log('Mass Open contact error: ' . $e->getMessage());
    mo_json(500, false, 'Something went wrong. Please try again later.');
}

/* ---------------------------------------------------------------------------
 * Mail
 * ------------------------------------------------------------------------ */

$body = "New contact form message\n\n"
      . "Name:    {$name}\n"
      . "Email:   {$email}\n"
      . "Subject: {$subject}\n\n"
      . "Message:\n{$message}\n";

$safeBody = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
$html     = '<pre style="font-family:monospace;white-space:pre-wrap;">' . $safeBody . '</pre>';

$sent = mo_mailgun_send(
    $mail,
    $mail['contact_to'],
    'Mass Open contact: ' . $subject,
    $body,
    $html,
    'contact',
    $email
);

if (!$sent['ok']) {
    mo_json(502, false, "We couldn't send your message. Please try again shortly.");
}

mo_json(200, true, $GENERIC_OK);
