<?php

namespace App\Bus;

final readonly class CaptureAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $verb,
        public string $sessionId,
        public string $agentType,
        public string $type = '',
        public array $payload = [],
        public string $model = '',
    ) {}
}
