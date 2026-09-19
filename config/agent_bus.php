<?php

return [

    'stream' => env('AGENT_BUS_STREAM', 'AGENT_BUS'),

    'subjects' => [
        'repo.>',
        'session.>',
        'spotify.>',
    ],

    'kv' => [
        'bucket' => env('AGENT_BUS_KV_BUCKET', 'sessions'),
        'history' => 1,
        'ttl_seconds' => (int) env('AGENT_BUS_KV_TTL', 90),
    ],

    'aliases' => [
        'bucket' => env('AGENT_BUS_ALIAS_BUCKET', 'session_aliases'),
    ],

    'herdr' => [
        'binary' => env('AGENT_BUS_HERDR', 'herdr'),
    ],

    'sidecar' => [
        'consumer' => env('AGENT_BUS_SIDECAR_CONSUMER'),
        'max_attempts' => (int) env('AGENT_BUS_SIDECAR_MAX_ATTEMPTS', 5),
        'retry_seconds' => (float) env('AGENT_BUS_SIDECAR_RETRY_SECONDS', 5),
        'expires' => (float) env('AGENT_BUS_SIDECAR_EXPIRES', 0.5),
    ],

];
