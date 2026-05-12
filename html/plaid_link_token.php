<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../plaid.php';

try { $pdo = get_mysql_connection(); auth_login_from_cookie($pdo); } catch (Throwable $e) { /* ignore */ }
header('Content-Type: application/json');

if (!isset($_SESSION['username']) || $_SESSION['username'] === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $environment = plaid_normalize_environment((string)($_POST['environment'] ?? 'production'));
    $owner = budget_canonical_user((string)$_SESSION['username']);
    $body = [
        'client_name' => 'Budget Lillard',
        'country_codes' => ['US'],
        'language' => 'en',
        'user' => [
            'client_user_id' => 'budget-' . sha1($owner),
        ],
        'products' => ['transactions'],
        'transactions' => [
            'days_requested' => 730,
        ],
    ];
    $webhook = plaid_current_webhook_url();
    if ($webhook !== null) {
        $body['webhook'] = $webhook;
    }
    $response = plaid_api_request('/link/token/create', $body, $environment);
    echo json_encode([
        'ok' => true,
        'environment' => $environment,
        'link_token' => $response['link_token'] ?? null,
        'expiration' => $response['expiration'] ?? null,
        'request_id' => $response['request_id'] ?? null,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
