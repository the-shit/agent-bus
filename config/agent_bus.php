<?php

return [

    'stream' => env('AGENT_BUS_STREAM', 'AGENT_BUS'),

    'subjects' => [
        'repo.>',
        'session.>',
        'spotify.>',
        'work.>',
        'bot.>',
    ],

    'stream_limits' => [
        'max_age_seconds' => (int) env('AGENT_BUS_MAX_AGE', 604800),
        'max_bytes' => (int) env('AGENT_BUS_MAX_BYTES', 1_073_741_824),
        'max_message_bytes' => (int) env('AGENT_BUS_MAX_MESSAGE_BYTES', 262_144),
        'duplicate_window_seconds' => (float) env('AGENT_BUS_DUPLICATE_WINDOW', 900),
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

    'judge' => [
        'api_key' => env('TYPESAFE_API_KEY'),
        'endpoint' => env('TYPESAFE_API_URL', 'https://api.typesafe.ai/v1/systemone'),
        'model' => env('TYPESAFE_MODEL', 'jev-latest'),
        'connect_timeout' => (float) env('TYPESAFE_CONNECT_TIMEOUT', 2.0),
        'timeout' => (float) env('TYPESAFE_TIMEOUT', 5.0),
        'consumer' => env('AGENT_BUS_JUDGE_CONSUMER', 'agent-bus-judge'),
        'capability' => env('AGENT_BUS_JUDGE_CAPABILITY', 'tool_failure_triage'),
        'confidence_threshold' => (float) env('AGENT_BUS_JUDGE_CONFIDENCE_THRESHOLD', 0.75),
    ],

    'worker' => [
        // Keep interactive work latency bounded: one slow provider request
        // must not make seven correlated callers time out behind it.
        'batch' => (int) env('AGENT_BUS_WORKER_BATCH', 1),
        'expires' => (float) env('AGENT_BUS_WORKER_EXPIRES', 0.5),
        'ack_wait_seconds' => (int) env('AGENT_BUS_WORKER_ACK_WAIT', 660),
    ],

    'specialist' => [
        'pi_binary' => env('AGENT_BUS_PI_BINARY', '/home/jordan/.local/share/mise/shims/pi'),
        'profiles' => [
            'qwen-reviewer' => [
                'capability' => 'code_review',
                'consumer' => 'agent-bus-qwen-reviewer',
                'model' => env('AGENT_BUS_QWEN_REVIEW_MODEL', 'ollama/qwen3-coder-next:48k'),
                'agent_file' => env('AGENT_BUS_QWEN_REVIEW_AGENT', '/home/jordan/.pi/agent/agents/reviewer.md'),
                'cwd' => env('AGENT_BUS_QWEN_REVIEW_CWD', base_path()),
                'tools' => ['read', 'grep', 'find', 'ls'],
                'timeout' => (int) env('AGENT_BUS_QWEN_REVIEW_TIMEOUT', 600),
            ],
        ],
    ],

];
