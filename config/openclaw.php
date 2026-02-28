<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Gateway WebSocket CA Bundle Path
    |--------------------------------------------------------------------------
    |
    | Path to the CA certificate bundle for verifying gateway TLS connections.
    | Used by GatewayWebSocketClient for WSS health checks and GatewayWakeClient
    | for HTTPS wake requests.
    |
    */
    'gateway_ca_bundle' => env(
        'OPENCLAW_GATEWAY_CA_BUNDLE',
        (getenv('HOME') ?: '/home/nckrtl').'/.config/certs/caddy-cas.pem'
    ),

    /*
    |--------------------------------------------------------------------------
    | Health Check Configuration
    |--------------------------------------------------------------------------
    */
    'health' => [
        // WebSocket connect timeout in milliseconds
        'connect_timeout_ms' => (int) env('OPENCLAW_CONNECT_TIMEOUT_MS', 10000),

        // WebSocket request timeout in milliseconds
        'request_timeout_ms' => (int) env('OPENCLAW_REQUEST_TIMEOUT_MS', 5000),

        // Session age threshold in milliseconds — below this = agent is awake
        'awake_threshold_ms' => (int) env('OPENCLAW_AWAKE_THRESHOLD_MS', 60000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wake Configuration
    |--------------------------------------------------------------------------
    */
    'wake' => [
        // HTTP timeout for wake requests in seconds
        'http_timeout' => (int) env('OPENCLAW_WAKE_HTTP_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limit Configuration
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        // Maximum backoff time in seconds (10 minutes)
        'max_backoff_seconds' => (int) env('OPENCLAW_MAX_BACKOFF_SECONDS', 600),

        // Base backoff time in seconds (2 minutes)
        'base_backoff_seconds' => (int) env('OPENCLAW_BASE_BACKOFF_SECONDS', 120),

        // Default requests per minute per model
        'default_rpm' => (int) env('OPENCLAW_DEFAULT_RPM', 50),

        // Per-model rate limits (requests per minute)
        'model_limits' => [
            'claude-sonnet-4' => 50,
            'claude-sonnet-4.5' => 50,
            'claude-haiku-4.5' => 50,
            'claude-opus-4' => 50,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which Eloquent models implement the OpenClaw contracts.
    | Used by the CheckAgentStatusCommand to resolve agents and gateways.
    |
    */
    'agent_model' => env('OPENCLAW_AGENT_MODEL'),

    'gateway_model' => env('OPENCLAW_GATEWAY_MODEL'),

    // The relationship name on the agent model that returns the gateway
    'agent_gateway_relation' => env('OPENCLAW_AGENT_GATEWAY_RELATION', 'instance'),
];
