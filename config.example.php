<?php

/**
 * Copy this file to config.php and set your upstream API endpoint.
 *
 * Sending-domain restrictions live in a separate allowlist.php
 * (copy allowlist.example.php).
 */

return [
    // Final API URL (webhook handler on the locked service)
    'target_url' => 'https://api.example.com/v1/webhooks/receive',

    // Outbound request timeout in seconds
    'timeout_seconds' => 30,

    // Follow HTTP redirects when forwarding (usually leave false)
    'follow_redirects' => false,

    // Incoming methods allowed on this bridge
    'allowed_methods' => ['POST'],

    // When true, use the same HTTP method as the incoming request; when false, always POST
    'preserve_http_method' => true,

    // Optional shared secret callers must send (empty string = disabled)
    'bridge_secret' => '',
    'bridge_secret_header' => 'X-Passthrough-Secret',

    // Incoming header names to forward (case-insensitive)
    'forward_headers' => [
        'Content-Type',
        'Authorization',
        'Accept',
        'User-Agent',
    ],

    // Also forward headers whose names start with any of these prefixes (e.g. X-*, Stripe-*)
    'forward_header_prefixes' => ['X-', 'Stripe-'],
];
