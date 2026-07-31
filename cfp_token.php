<?php
/**
 * Issues a single-use form nonce for the CFP form.
 *
 * The CFP page is static HTML, so it cannot carry a server-generated token.
 * The page's JavaScript fetches one from here on load and drops it into a
 * hidden field. That gives three things at once:
 *
 *   1. a timing trap — the issue time is recorded server-side, so the client
 *      cannot lie about how long the form took to fill in;
 *   2. an invisible challenge — a bot that posts the form without executing
 *      the page's JavaScript never obtains a nonce at all;
 *   3. replay protection — the nonce is marked used on submission.
 *
 * Responds with JSON: { ok: bool, nonce: string }.
 */

declare(strict_types=1);

require __DIR__ . '/subscribe_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$ip = mo_client_ip();

try {
    $pdo = mo_pdo();

    // Cap nonce issuance so the endpoint cannot be used to flood the table.
    // Generous enough that a person reloading the page never notices.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM form_nonces
          WHERE issued_ip = :ip AND issued_at > NOW() - INTERVAL 1 HOUR'
    );
    $stmt->execute([':ip' => $ip]);

    if ((int) $stmt->fetchColumn() > 60) {
        http_response_code(429);
        echo json_encode(['ok' => false]);
        exit;
    }

    $nonce = mo_token();

    $stmt = $pdo->prepare(
        'INSERT INTO form_nonces (nonce, issued_at, issued_ip)
              VALUES (:nonce, NOW(), :ip)'
    );
    $stmt->execute([':nonce' => $nonce, ':ip' => $ip]);
} catch (PDOException $e) {
    error_log('Mass Open CFP nonce error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(['ok' => true, 'nonce' => $nonce]);
