<?php

namespace App\Bus;

final class OpenCodeCapture
{
    /**
     * @param  array<string, mixed>  $event
     * @return list<CaptureAction>
     */
    public static function actions(array $event): array
    {
        $sessionId = Capture::sessionId($event, 'opencode');
        $model = Capture::model($event);

        return match (Capture::eventName($event)) {
            'toolexecuteafter' => [
                self::emit($sessionId, 'toolCall', $event, $model, Capture::toolPayload($event)),
            ],
            'sessioncreated' => [
                self::heartbeat($sessionId, $model),
                self::emit($sessionId, 'sessionStart', $event, $model),
            ],
            'sessionidle' => [
                self::emit($sessionId, 'idle', $event, $model),
                self::heartbeat($sessionId, $model),
            ],
            'sessiondeleted' => [
                self::emit($sessionId, 'sessionEnd', $event, $model),
                self::leave($sessionId, $model),
            ],
            'sessionerror' => [
                self::emit($sessionId, 'errorRaised', $event, $model),
            ],
            default => [],
        };
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
            agentType: 'opencode',
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
            agentType: 'opencode',
            model: $model,
        );
    }

    private static function leave(string $sessionId, string $model): CaptureAction
    {
        return new CaptureAction(
            verb: 'sessionEnd',
            sessionId: $sessionId,
            agentType: 'opencode',
            model: $model,
        );
    }
}
