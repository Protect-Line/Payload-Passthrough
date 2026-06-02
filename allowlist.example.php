<?php

/**
 * Copy this file to allowlist.php and list domains that may call the bridge.
 *
 * The sender is resolved from HTTP headers (see domain_sources / domain_header).
 * Server-to-server webhooks often omit Origin/Referer — use domain_header if needed.
 */

return [
    // When false, all domains are accepted (allowlist ignored)
    'enabled' => true,

    // Hostnames allowed to send webhooks (lowercase, no scheme)
    // Supports exact match (hooks.example.com) and wildcard subdomains (*.example.com)
    'domains' => [
        'hooks.stripe.com',
        'api.github.com',
        '*.your-app.example',
    ],

    // Headers checked in order for a URL or hostname (first non-empty wins)
    'domain_sources' => [
        'Origin',
        'Referer',
    ],

    // Optional: bare hostname in this header (e.g. from your own proxy)
    // Checked before domain_sources when set
    'domain_header' => 'X-Sender-Domain',
];
