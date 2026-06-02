<?php

declare(strict_types=1);

function log_event(array $config, string $event, array $context = []): void
{
    if (!($config['logging_enabled'] ?? true)) {
        return;
    }

    $directory = (string) ($config['log_directory'] ?? dirname(__DIR__) . '/.log');
    $fileName = trim((string) ($config['log_file'] ?? 'events.log'));
    $maxSize = max(1, (int) ($config['log_max_size_bytes'] ?? (2 * 1024 * 1024)));
    $maxAgeDays = max(1, (int) ($config['log_max_age_days'] ?? 30));

    if ($fileName === '') {
        $fileName = 'events.log';
    }

    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return;
    }

    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    rotate_log_if_needed($path, $maxSize, $maxAgeDays);

    $record = [
        'timestamp' => gmdate('c'),
        'event' => $event,
        'context' => sanitize_log_context($context),
    ];

    $line = json_encode($record, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function rotate_log_if_needed(string $path, int $maxSizeBytes, int $maxAgeDays): void
{
    if (!is_file($path)) {
        return;
    }

    $size = @filesize($path);
    $mtime = @filemtime($path);
    $isOversize = is_int($size) && $size >= $maxSizeBytes;
    $isExpired = is_int($mtime) && (time() - $mtime) >= ($maxAgeDays * 86400);

    if (!$isOversize && !$isExpired) {
        return;
    }

    // Single active file behavior: clear content when a limit is reached.
    @file_put_contents($path, '', LOCK_EX);
}

/**
 * @return array<string, scalar|null>
 */
function sanitize_log_context(array $context): array
{
    $safe = [];

    foreach ($context as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $safe[$key] = $value;
            continue;
        }

        if (is_array($value)) {
            $safe[$key] = '[array]';
            continue;
        }

        $safe[$key] = '[non-scalar]';
    }

    return $safe;
}
