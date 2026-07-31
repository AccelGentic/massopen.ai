<?php
/**
 * Shared helpers for the Mass Open double opt-in flow.
 *
 * Included by submit.php, confirm.php and unsubscribe.php. Requesting this
 * file directly does nothing.
 */

declare(strict_types=1);

// Guard against direct web access — this file only defines functions.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

/** Load both config files once. */
function mo_config(): array
{
    static $config = null;
    if ($config === null) {
        $config = [
            'db'   => require __DIR__ . '/db_config.php',
            'mail' => require __DIR__ . '/mail_config.php',
        ];
    }
    return $config;
}

/** Open a PDO connection with exceptions and real prepared statements. */
function mo_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $db  = mo_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/** Send a JSON response and stop. */
function mo_json(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

/** 256 bits of entropy, hex encoded — safe to put in a URL. */
function mo_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Tokens are stored hashed. Lookup is an indexed exact match on the hash of a
 * high-entropy secret, so there is no value to compare in variable time.
 */
function mo_token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Lowercase for uniqueness; the display copy keeps the original casing. */
function mo_canonical(string $email): string
{
    return strtolower(trim($email));
}

function mo_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function mo_user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/**
 * Per-IP hourly cap on signups.
 *
 * Without this, the form is a free way to send confirmation mail to arbitrary
 * addresses ("list bombing"), which burns your Mailgun reputation.
 *
 * @return bool true when the request is allowed.
 */
function mo_throttle_ok(PDO $pdo, string $ip, int $limit): bool
{
    if ($ip === '' || $limit <= 0) {
        return true;
    }

    // Reset the window if the existing one is over an hour old, then count.
    $stmt = $pdo->prepare(
        'INSERT INTO signup_throttle (ip, attempts, window_started_at)
              VALUES (:ip, 1, NOW())
         ON DUPLICATE KEY UPDATE
              attempts = IF(window_started_at < NOW() - INTERVAL 1 HOUR, 1, attempts + 1),
              window_started_at = IF(window_started_at < NOW() - INTERVAL 1 HOUR, NOW(), window_started_at)'
    );
    $stmt->execute([':ip' => $ip]);

    $stmt = $pdo->prepare('SELECT attempts FROM signup_throttle WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);
    $attempts = (int) ($stmt->fetchColumn() ?: 0);

    return $attempts <= $limit;
}

/**
 * Send a message through the Mailgun Messages API.
 *
 * Uses the HTTP API rather than SMTP: no mail library or persistent
 * connection, it works over 443, and it returns a message id we can log.
 *
 * @return array{ok: bool, status: int, body: string}
 */
function mo_mailgun_send(
    array $mail,
    string $to,
    string $subject,
    string $text,
    string $html,
    string $tag,
    ?string $replyTo = null
): array {
    $fields = [
        'from'    => $mail['from'],
        'to'      => $to,
        'subject' => $subject,
        'text'    => $text,
        'html'    => $html,
        'o:tag'   => $tag,
        // Click tracking rewrites URLs through Mailgun. For a confirmation
        // link that is actively harmful: it obscures the destination and adds
        // another party that can fetch the link. Keep the real URL.
        'o:tracking-clicks' => 'no',
        'o:tracking-opens'  => 'no',
    ];

    $replyTo = $replyTo ?? $mail['reply_to'];
    if ($replyTo !== '') {
        $fields['h:Reply-To'] = $replyTo;
    }

    $ch = curl_init($mail['api_base'] . '/v3/' . rawurlencode($mail['domain']) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => 'api:' . $mail['api_key'],
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err !== '') {
        error_log('Mass Open: Mailgun transport error: ' . $err);
        return ['ok' => false, 'status' => 0, 'body' => $err];
    }
    if ($status !== 200) {
        error_log('Mass Open: Mailgun returned HTTP ' . $status . ': ' . $body);
    }

    return ['ok' => $status === 200, 'status' => $status, 'body' => $body];
}

/**
 * Optional Mailgun address validation, gated behind MASSOPEN_VALIDATE_EMAILS
 * because it is metered per call.
 *
 * This is NOT the opt-in check — it cannot prove the person controls the
 * mailbox. It only filters typos and disposable domains before we spend a
 * send. Any failure here is treated as "allow", so an outage can never block
 * a legitimate signup.
 */
function mo_mailgun_address_ok(array $mail, string $email): bool
{
    $url = $mail['api_base'] . '/v4/address/validate?address=' . rawurlencode($email);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => 'api:' . $mail['api_key'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 6,
    ]);
    $body   = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        return true; // Fail open.
    }

    $json = json_decode($body, true);
    if (!is_array($json) || !isset($json['result'])) {
        return true;
    }

    // Mailgun v4 results: deliverable | undeliverable | do_not_send | unknown.
    return !in_array($json['result'], ['undeliverable', 'do_not_send'], true);
}

/**
 * Email a verified CFP proposal to the organisers, if MASSOPEN_CFP_NOTIFY is
 * set. Plain text only — this is an internal notification, and the submitted
 * text is untrusted, so it is never interpolated into markup.
 */
function mo_cfp_notify_organisers(
    array $mail,
    int $id,
    string $name,
    string $email,
    string $topic,
    string $bio,
    string $abstract
): void {
    $body = "New verified talk proposal (#{$id})\n\n"
          . "Name:  {$name}\n"
          . "Email: {$email}\n"
          . "Topic: {$topic}\n\n"
          . "Bio:\n{$bio}\n\n"
          . "Abstract:\n{$abstract}\n";

    mo_mailgun_send(
        $mail,
        $mail['cfp_notify'],
        'Mass Open CFP: ' . $topic,
        $body,
        '<pre style="font-family:monospace;white-space:pre-wrap;">'
            . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</pre>',
        'cfp-notify'
    );
}

/**
 * Render a minimal standalone page that borrows the site stylesheet, used for
 * the confirmation interstitial and error states served by PHP.
 */
function mo_render_page(string $title, string $bodyHtml, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    // These pages hang off one-time tokens; keep them out of caches.
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Referrer-Policy: no-referrer');

    $site = mo_config()['mail']['site_url'];
    $t    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>{$t} — Mass Open</title>
  <link rel="icon" href="{$site}/assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="{$site}/assets/css/style.css">
</head>
<body>
  <header class="site-header" id="top">
    <nav class="nav" aria-label="Primary">
      <a class="nav__brand" href="{$site}/#home"><span class="accent">Mass</span> Open</a>
    </nav>
  </header>
  <main>
    <section class="section section--join">
      <div class="container">
        {$bodyHtml}
      </div>
    </section>
  </main>
</body>
</html>
HTML;
    exit;
}
