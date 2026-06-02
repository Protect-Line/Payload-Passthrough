<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/allowlist.php';
require __DIR__ . '/includes/passthrough.php';

$config = load_config();
$allowlist = load_allowlist();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ok',
        'service' => 'payload-passthrough',
        'target_configured' => trim((string) ($config['target_url'] ?? '')) !== '',
        'allowed_methods' => array_values($config['allowed_methods'] ?? ['POST']),
        'domain_allowlist_enabled' => allowlist_is_enabled($allowlist),
    ]);
    exit;
}

if (!is_allowed_method($method, $config)) {
    json_error('Method not allowed.', 405);
}

verify_bridge_secret($config);
verify_sending_domain($allowlist);

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    json_error('Could not read request body.', 400);
}

$forwardHeaders = headers_to_forward($config);
$result = forward_request($config, $method, $rawBody, $forwardHeaders);
emit_upstream_response($result);
