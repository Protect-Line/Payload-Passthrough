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

function allowlist_is_enabled(array $allowlist): bool
{
    if (!($allowlist['enabled'] ?? false)) {
        return false;
    }

    return is_file(dirname(__DIR__) . '/allowlist.php');
}

function detect_sending_domain(array $allowlist): string
{
    $incoming = incoming_headers();

    $domainHeader = trim((string) ($allowlist['domain_header'] ?? ''));
    if ($domainHeader !== '') {
        foreach ($incoming as $name => $value) {
            if (strcasecmp((string) $name, $domainHeader) === 0) {
                $host = normalize_hostname((string) $value);
                if ($host !== '') {
                    return $host;
                }
            }
        }
    }

    $sources = $allowlist['domain_sources'] ?? ['Origin', 'Referer'];
    if (!is_array($sources)) {
        $sources = ['Origin', 'Referer'];
    }

    foreach ($sources as $headerName) {
        foreach ($incoming as $name => $value) {
            if (strcasecmp((string) $name, (string) $headerName) !== 0) {
                continue;
            }

            $host = hostname_from_header_value((string) $value);
            if ($host !== '') {
                return $host;
            }
        }
    }

    return '';
}

function verify_sending_domain(array $allowlist): void
{
    if (!($allowlist['enabled'] ?? false)) {
        return;
    }

    if (!is_file(dirname(__DIR__) . '/allowlist.php')) {
        return;
    }

    $domains = $allowlist['domains'] ?? [];
    if (!is_array($domains) || $domains === []) {
        json_error('Domain allowlist is enabled but no domains are configured.', 500);
    }

    $sender = detect_sending_domain($allowlist);
    if ($sender === '') {
        json_error('Could not determine sending domain.', 403);
    }

    if (!domain_matches_allowlist($sender, $domains)) {
        json_error('Sending domain is not allowed.', 403);
    }
}

function normalize_hostname(string $value): string
{
    $value = trim(strtolower($value));
    if ($value === '') {
        return '';
    }

    if (str_contains($value, '://')) {
        return hostname_from_header_value($value);
    }

    if (str_contains($value, ':') && !str_starts_with($value, '[')) {
        $value = explode(':', $value)[0];
    }

    return $value;
}

function hostname_from_header_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (str_contains($value, '://')) {
        $host = parse_url($value, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return '';
    }

    return normalize_hostname($value);
}

function domain_matches_allowlist(string $host, array $domains): bool
{
    $host = normalize_hostname($host);
    if ($host === '') {
        return false;
    }

    foreach ($domains as $allowed) {
        $allowed = normalize_hostname((string) $allowed);
        if ($allowed === '') {
            continue;
        }

        if ($allowed === $host) {
            return true;
        }

        if (str_starts_with($allowed, '*.')) {
            $suffix = substr($allowed, 2);
            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.' . $suffix))) {
                return true;
            }
        }
    }

    return false;
}
