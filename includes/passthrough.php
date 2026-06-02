<?php

declare(strict_types=1);

/**
 * @return array{status:int, headers:array<string,string>, body:string, curl_error:?string}
 */
function forward_request(array $config, string $method, string $rawBody, array $forwardHeaders): array
{
    $targetUrl = trim((string) ($config['target_url'] ?? ''));
    if ($targetUrl === '' || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
        return [
            'status' => 500,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['success' => false, 'error' => 'target_url is not configured.']),
            'curl_error' => null,
        ];
    }

    $preserveMethod = (bool) ($config['preserve_http_method'] ?? true);
    $outboundMethod = $preserveMethod ? strtoupper($method) : 'POST';

    $timeout = max(1, (int) ($config['timeout_seconds'] ?? 30));
    $follow = (bool) ($config['follow_redirects'] ?? false);

    $ch = curl_init($targetUrl);
    if ($ch === false) {
        return [
            'status' => 500,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['success' => false, 'error' => 'Could not initialize outbound request.']),
            'curl_error' => 'curl_init failed',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $outboundMethod,
        CURLOPT_POSTFIELDS => $rawBody,
        CURLOPT_HTTPHEADER => $forwardHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => $follow ? 3 : 0,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false) {
        return [
            'status' => 502,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'success' => false,
                'error' => 'Upstream request failed.',
                'detail' => $curlError !== '' ? $curlError : 'unknown error',
            ]),
            'curl_error' => $curlError !== '' ? $curlError : 'curl_exec failed',
        ];
    }

    $rawHeader = substr((string) $response, 0, $headerSize);
    $body = substr((string) $response, $headerSize);
    $parsedHeaders = parse_response_headers($rawHeader);

    if ($status < 100) {
        $status = 502;
    }

    return [
        'status' => $status,
        'headers' => $parsedHeaders,
        'body' => $body,
        'curl_error' => null,
    ];
}

/**
 * @return array<string, string>
 */
function parse_response_headers(string $rawHeader): array
{
    $headers = [];
    $lines = preg_split("/\r\n|\n|\r/", $rawHeader) ?: [];

    foreach ($lines as $line) {
        if ($line === '' || str_contains($line, 'HTTP/')) {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($name !== '') {
            $headers[$name] = $value;
        }
    }

    return $headers;
}

function emit_upstream_response(array $result): void
{
    $status = (int) ($result['status'] ?? 502);
    http_response_code($status);

    $headers = $result['headers'] ?? [];
    $skip = ['transfer-encoding', 'connection', 'content-encoding'];

    foreach ($headers as $name => $value) {
        if (!is_string($name) || !is_scalar($value)) {
            continue;
        }
        if (in_array(strtolower($name), $skip, true)) {
            continue;
        }
        header($name . ': ' . (string) $value, false);
    }

    echo (string) ($result['body'] ?? '');
}
