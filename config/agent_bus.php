<?php

return [

    'stream' => env('AGENT_BUS_STREAM', 'AGENT_BUS'),

    'subjects' => [
        'repo.>',
        'session.>',
    ],

    'kv' => [
        'bucket' => env('AGENT_BUS_KV_BUCKET', 'sessions'),
        'history' => 1,
        'ttl_seconds' => (int) env('AGENT_BUS_KV_TTL', 90),
    ],

    'herdr' => [
        'binary' => env('AGENT_BUS_HERDR', 'herdr'),
    ],

    'sidecar' => [
        'consumer' => env('AGENT_BUS_SIDECAR_CONSUMER'),
        'batch' => (int) env('AGENT_BUS_SIDECAR_BATCH', 8),
        'expires' => (float) env('AGENT_BUS_SIDECAR_EXPIRES', 0.5),
    ],

    'monitor' => [
        'consumer' => env('AGENT_BUS_MONITOR_CONSUMER'),
        'batch' => (int) env('AGENT_BUS_MONITOR_BATCH', 8),
        'expires' => (float) env('AGENT_BUS_MONITOR_EXPIRES', 0.5),
    ],

];
