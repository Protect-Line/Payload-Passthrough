<?php

declare(strict_types=1);

function load_allowlist(): array
{
    $path = dirname(__DIR__) . '/allowlist.php';

    if (!is_file($path)) {
        return [
            'enabled' => false,
            'domains' => [],
            'domain_sources' => ['Origin', 'Referer'],
            'domain_header' => '',
        ];
    }

    $allowlist = require $path;

    if (!is_array($allowlist)) {
        throw new RuntimeException('allowlist.php must return an array.');
    }

    return $allowlist;
}

function host_from_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (!str_contains($value, '://')) {
        $host = explode('/', $value, 2)[0];

        return normalize_domain($host);
    }

    $parsed = parse_url($value);

    return normalize_domain((string) ($parsed['host'] ?? ''));
}

function normalize_domain(string $host): string
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }

    if (str_contains($host, '://')) {
        return host_from_value($host);
    }

    if (str_contains($host, ':')) {
        $withoutPort = explode(':', $host, 2)[0];

        return normalize_domain($withoutPort);
    }

    return $host;
}

function header_value(array $headers, string $wanted): string
{
    foreach ($headers as $name => $value) {
        if (strcasecmp((string) $name, $wanted) === 0) {
            return trim((string) $value);
        }
    }

    return '';
}

function detect_sending_domain(array $allowlist): string
{
    $headers = incoming_headers();
    $candidates = [];

    $custom = trim((string) ($allowlist['domain_header'] ?? ''));
    if ($custom !== '') {
        $candidates[] = $custom;
    }

    $sources = $allowlist['domain_sources'] ?? ['Origin', 'Referer'];
    if (is_array($sources)) {
        foreach ($sources as $source) {
            $source = trim((string) $source);
            if ($source !== '') {
                $candidates[] = $source;
            }
        }
    }

    foreach ($candidates as $headerName) {
        $raw = header_value($headers, $headerName);
        $host = host_from_value($raw);
        if ($host !== '') {
            return $host;
        }
    }

    return '';
}

function domain_matches_allowlist(string $domain, array $allowed): bool
{
    $domain = normalize_domain($domain);
    if ($domain === '') {
        return false;
    }

    foreach ($allowed as $entry) {
        $entry = strtolower(trim((string) $entry));
        if ($entry === '') {
            continue;
        }

        if ($entry === $domain) {
            return true;
        }

        if (str_starts_with($entry, '*.')) {
            $base = substr($entry, 2);
            if ($base !== '' && ($domain === $base || str_ends_with($domain, '.' . $base))) {
                return true;
            }
            continue;
        }

        if (str_starts_with($entry, '.')) {
            $base = ltrim($entry, '.');
            if ($domain === $base || str_ends_with($domain, $entry)) {
                return true;
            }
        }
    }

    return false;
}

function verify_sending_domain(array $allowlist): void
{
    if (!($allowlist['enabled'] ?? false)) {
        return;
    }

    $domains = $allowlist['domains'] ?? [];
    if (!is_array($domains) || $domains === []) {
        json_error('Domain allowlist is enabled but no domains are configured.', 500);
    }

    $sender = detect_sending_domain($allowlist);
    if ($sender === '') {
        json_error('Could not determine sending domain from request headers.', 403);
    }

    if (!domain_matches_allowlist($sender, $domains)) {
        json_error('Sending domain is not allowed.', 403);
    }
}

function allowlist_is_enabled(array $allowlist): bool
{
    if (!($allowlist['enabled'] ?? false)) {
        return false;
    }

    $domains = $allowlist['domains'] ?? [];

    return is_array($domains) && $domains !== [];
}