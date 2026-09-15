<?php

namespace App\Bus;

final class GrokHook
{
    /**
     * @param  array<string, mixed>  $event
     * @return list<CaptureAction>
     */
    public static function actions(array $event): array
    {
        if (Capture::isSubagent($event)) {
            return [];
        }

        $sessionId = BusIdentity::resolve('grok', $event);

        if ($sessionId === null) {
            return [];
        }

        $model = Capture::model($event);

        $actions = match (Capture::eventName($event)) {
            'sessionstart' => [
                self::heartbeat($sessionId, $model),
                self::emit($sessionId, 'sessionStart', $event, $model),
            ],
            'sessionend' => [
                self::emit($sessionId, 'sessionEnd', $event, $model),
                self::leave($sessionId, $model),
            ],
            'posttooluse' => [
                self::emit($sessionId, 'toolCall', $event, $model, Capture::toolPayload($event)),
            ],
            'notification' => self::notification($event, $sessionId, $model),
            'stop' => [
                self::emit($sessionId, 'idle', $event, $model),
                self::heartbeat($sessionId, $model),
            ],
            'posttoolusefailure', 'stopfailure' => [
                self::emit($sessionId, 'errorRaised', $event, $model, Capture::toolPayload($event)),
            ],
            default => [],
        };

        if ($actions === []) {
            return [];
        }

        $aliases = BusIdentity::aliases($sessionId, $event);

        return array_map(fn (CaptureAction $action): CaptureAction => $action->withAliases($aliases), $actions);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<CaptureAction>
     */
    private static function notification(array $event, string $sessionId, string $model): array
    {
        $type = $event['notificationType'] ?? $event['notification_type'] ?? $event['matcher'] ?? '';

        if (! is_string($type) || $type !== 'idle_prompt') {
            return [];
        }

        return [
            self::emit($sessionId, 'idle', $event, $model),
            self::heartbeat($sessionId, $model),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $payload
     */
    private static function emit(string $sessionId, string $type, array $event, string $model, array $payload = []): CaptureAction
    {
        return new CaptureAction(
            verb: 'emit',
            sessionId: $sessionId,
            agentType: 'grok',
            type: $type,
            payload: $payload !== [] ? $payload : Capture::toolPayload($event),
            model: $model,
        );
    }

    private static function heartbeat(string $sessionId, string $model): CaptureAction
    {
        return new CaptureAction(
            verb: 'heartbeat',
            sessionId: $sessionId,
            agentType: 'grok',
            model: $model,
        );
    }

    private static function leave(string $sessionId, string $model): CaptureAction
    {
        return new CaptureAction(
            verb: 'sessionEnd',
            sessionId: $sessionId,
            agentType: 'grok',
            model: $model,
        );
    }
}
