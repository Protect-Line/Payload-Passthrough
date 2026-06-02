<?php

declare(strict_types=1);

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $needleLength = strlen($needle);

        return $needleLength <= strlen($haystack) && substr($haystack, -$needleLength) === $needle;
    }
}

function load_config(): array
{
    $configPath = dirname(__DIR__) . '/config.php';

    if (!is_file($configPath)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'Configuration file not found. Copy config.example.php to config.php.',
        ]);
        exit;
    }

    $config = require $configPath;

    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array.');
    }

    return $config;
}

function json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function incoming_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
        $headers[$name] = is_string($value) ? $value : (string) $value;
    }

    if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
        $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
    }

    return $headers;
}

function header_name_matches(string $name, array $exact, array $prefixes): bool
{
    $lower = strtolower($name);

    foreach ($exact as $allowed) {
        if (strtolower((string) $allowed) === $lower) {
            return true;
        }
    }

    foreach ($prefixes as $prefix) {
        $prefix = (string) $prefix;
        if ($prefix !== '' && stripos($name, $prefix) === 0) {
            return true;
        }
    }

    return false;
}

function headers_to_forward(array $config): array
{
    $incoming = incoming_headers();
    $exact = array_values($config['forward_headers'] ?? []);
    $prefixes = array_values($config['forward_header_prefixes'] ?? []);

    $hopByHop = [
        'connection',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'te',
        'trailers',
        'transfer-encoding',
        'upgrade',
        'host',
        'content-length',
    ];

    $out = [];
    foreach ($incoming as $name => $value) {
        if (!is_string($name) || !is_scalar($value)) {
            continue;
        }
        if (in_array(strtolower($name), $hopByHop, true)) {
            continue;
        }
        if (!header_name_matches($name, $exact, $prefixes)) {
            continue;
        }
        $out[] = $name . ': ' . trim((string) $value);
    }

    return $out;
}

function verify_bridge_secret(array $config): void
{
    $secret = (string) ($config['bridge_secret'] ?? '');
    if ($secret === '') {
        return;
    }

    $headerName = (string) ($config['bridge_secret_header'] ?? 'X-Passthrough-Secret');
    $incoming = incoming_headers();
    $provided = '';

    foreach ($incoming as $name => $value) {
        if (strcasecmp((string) $name, $headerName) === 0) {
            $provided = (string) $value;
            break;
        }
    }

    if ($provided === '' || !hash_equals($secret, $provided)) {
        json_error('Invalid or missing bridge secret.', 403);
    }
}

function is_allowed_method(string $method, array $config): bool
{
    $allowed = $config['allowed_methods'] ?? ['POST'];
    if (!is_array($allowed) || $allowed === []) {
        $allowed = ['POST'];
    }

    $method = strtoupper($method);
    foreach ($allowed as $item) {
        if (strtoupper((string) $item) === $method) {
            return true;
        }
    }

    return false;
}
