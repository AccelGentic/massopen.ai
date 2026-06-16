<?php
/**
 * Mass Open email signup endpoint.
 *
 * Accepts a POST with an `email` field (form-encoded or JSON), validates it,
 * and stores it in the MariaDB `subscribers` table using a prepared statement.
 * Always responds with JSON: { ok: bool, message: string }.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/** Send a JSON response and stop. */
function respond(int $status, bool $ok, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, false, 'Method not allowed.');
}

// Accept either a posted form field or a JSON body.
$email = $_POST['email'] ?? null;
if ($email === null) {
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['email'])) {
            $email = $json['email'];
        }
    }
}

$email = is_string($email) ? trim($email) : '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(422, false, 'Please enter a valid email address.');
}
if (strlen($email) > 254) {
    respond(422, false, 'That email address is too long.');
}

$config = require __DIR__ . '/db_config.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['name'],
    $config['charset']
);

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $stmt = $pdo->prepare(
        'INSERT INTO subscribers (email, ip_address) VALUES (:email, :ip)'
    );
    $stmt->execute([
        ':email' => $email,
        ':ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
} catch (PDOException $e) {
    // 23000 = integrity constraint violation (duplicate email on UNIQUE key).
    if ($e->getCode() === '23000') {
        respond(200, true, "You're already on the list — thanks!");
    }
    error_log('Mass Open signup error: ' . $e->getMessage());
    respond(500, false, 'Something went wrong. Please try again later.');
}

respond(201, true, "Thanks! We'll keep you posted.");
