<?php

namespace App\Bus;

final readonly class CaptureAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $aliases
     */
    public function __construct(
        public string $verb,
        public string $sessionId,
        public string $agentType,
        public string $type = '',
        public array $payload = [],
        public string $model = '',
        public array $aliases = [],
    ) {}

    /**
     * @param  list<string>  $aliases
     */
    public function withAliases(array $aliases): self
    {
        return new self(
            verb: $this->verb,
            sessionId: $this->sessionId,
            agentType: $this->agentType,
            type: $this->type,
            payload: $this->payload,
            model: $this->model,
            aliases: $aliases,
        );
    }
}
