<?php

declare(strict_types=1);

return [
    'sidecar' => [
        'url' => env('PRISM_BROWSER_SIDECAR_URL', 'http://127.0.0.1:4319'),
        'host' => env('PRISM_BROWSER_HOST', '127.0.0.1'),
        'port' => (int) env('PRISM_BROWSER_PORT', 4319),
        'token' => env('PRISM_BROWSER_TOKEN'),
        'timeout' => (int) env('PRISM_BROWSER_TIMEOUT', 30),
        'egress_proxy' => env('PRISM_BROWSER_EGRESS_PROXY'),
        'allow_unverified_egress' => env('PRISM_BROWSER_ALLOW_UNVERIFIED_EGRESS', false),
    ],
    'policy' => [
        'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PRISM_BROWSER_ALLOWED_HOSTS', ''))))),
        'allowed_ports' => array_map('intval', array_filter(array_map('trim', explode(',', (string) env('PRISM_BROWSER_ALLOWED_PORTS', '443'))))),
        'require_https' => env('PRISM_BROWSER_REQUIRE_HTTPS', true),
        'max_observation_bytes' => (int) env('PRISM_BROWSER_MAX_OBSERVATION_BYTES', 65536),
    ],
];
