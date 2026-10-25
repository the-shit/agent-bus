<?php

namespace App\Bus;

final class MusicCapture
{
    /**
     * @param  array<string, mixed>  $event
     * @return list<CaptureAction>
     */
    public static function actions(array $event): array
    {
        $sessionId = BusIdentity::resolve('music', $event);

        if ($sessionId === null) {
            return [];
        }

        $model = Capture::model($event);

        $eventName = Capture::eventName($event);

        $actions = match ($eventName) {
            'playbackstart' => [
                self::emit($sessionId, 'playbackStart', $event, $model, self::musicPayload($event)),
                self::heartbeat($sessionId, $model),
            ],
            'playbackpause' => [
                self::emit($sessionId, 'playbackPause', $event, $model, self::musicPayload($event)),
                self::heartbeat($sessionId, $model),
            ],
            'playbackstop' => [
                self::emit($sessionId, 'playbackStop', $event, $model, self::musicPayload($event)),
                self::heartbeat($sessionId, $model),
            ],
            'trackchange' => [
                self::emit($sessionId, 'trackChange', $event, $model, self::musicPayload($event)),
                self::heartbeat($sessionId, $model),
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
     * @return array<string, mixed>
     */
    private static function musicPayload(array $event): array
    {
        $payload = [];

        // Pass through music-specific fields if present
        if (isset($event['track']) && is_array($event['track'])) {
            $payload['track'] = $event['track'];
        }
        if (isset($event['previousTrack']) && is_array($event['previousTrack'])) {
            $payload['previousTrack'] = $event['previousTrack'];
        }
        if (isset($event['positionMs']) && is_numeric($event['positionMs'])) {
            $payload['positionMs'] = (int) $event['positionMs'];
        }

        return $payload;
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
            agentType: 'music',
            type: $type,
            payload: $payload,
            model: $model,
        );
    }

    private static function heartbeat(string $sessionId, string $model): CaptureAction
    {
        return new CaptureAction(
            verb: 'heartbeat',
            sessionId: $sessionId,
            agentType: 'music',
            model: $model,
        );
    }
}
