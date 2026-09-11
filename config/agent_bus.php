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

];
