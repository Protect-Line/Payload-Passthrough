<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/allowlist.php';
require __DIR__ . '/includes/logging.php';
require __DIR__ . '/includes/passthrough.php';

$config = load_config();
$allowlist = load_allowlist();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestId = uniqid('req_', true);
$requestId = str_replace('.', '', $requestId);
$senderDomain = detect_sending_domain($allowlist);
$targetUrl = trim((string) ($config['target_url'] ?? ''));
$startedAt = microtime(true);
$responseSource = 'bridge';
$upstreamStatus = null;

register_shutdown_function(function () use (
    $config,
    $requestId,
    $method,
    $senderDomain,
    $targetUrl,
    $startedAt,
    &$responseSource,
    &$upstreamStatus
): void {
    $status = (int) http_response_code();
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

    log_event($config, 'request_completed', [
        'request_id' => $requestId,
        'method' => $method,
        'sending_domain' => $senderDomain !== '' ? $senderDomain : 'unknown',
        'target_endpoint' => $targetUrl,
        'response_source' => $responseSource,
        'response_status' => $status,
        'upstream_status' => $upstreamStatus,
        'duration_ms' => $durationMs,
    ]);
});

if ($method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'ok',
        'service' => 'payload-passthrough',
        'target_configured' => trim((string) ($config['target_url'] ?? '')) !== '',
        'allowed_methods' => array_values($config['allowed_methods'] ?? ['POST']),
        'domain_allowlist_enabled' => allowlist_is_enabled($allowlist),
        'logging_enabled' => (bool) ($config['logging_enabled'] ?? true),
    ]);
    exit;
}

log_event($config, 'request_received', [
    'request_id' => $requestId,
    'method' => $method,
    'sending_domain' => $senderDomain !== '' ? $senderDomain : 'unknown',
    'target_endpoint' => $targetUrl,
]);

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
$responseSource = 'upstream';
$upstreamStatus = (int) ($result['status'] ?? 502);

log_event($config, 'upstream_response', [
    'request_id' => $requestId,
    'target_endpoint' => $targetUrl,
    'upstream_status' => $upstreamStatus,
    'upstream_error' => (string) ($result['curl_error'] ?? ''),
]);

emit_upstream_response($result);
