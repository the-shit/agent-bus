<?php

namespace App\Bus;

use Closure;
use JsonException;
use RuntimeException;

class TypeSafeJudgeGateway implements JudgeGateway
{
    public const QUESTION_VERSION = 'tool-failure.v1';

    private const FAILURE_TYPES = [
        'model_misunderstanding',
        'transient_external',
        'permission_denied',
        'missing_dependency',
        'policy_blocked',
        'unknown',
    ];

    /**
     * @param  null|Closure(string, array<string, string>, array<string, mixed>, float, float): array<string, mixed>  $transport
     */
    public function __construct(
        private readonly ?Closure $transport = null,
        private readonly ?string $apiKey = null,
        private readonly ?string $endpoint = null,
        private readonly ?string $model = null,
        private readonly ?float $connectTimeout = null,
        private readonly ?float $timeout = null,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function judgeToolFailure(array $state): array
    {
        $apiKey = $this->apiKey ?? (string) config('agent_bus.judge.api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('TYPESAFE_API_KEY is not configured.');
        }

        $endpoint = $this->endpoint ?? (string) config('agent_bus.judge.endpoint');
        $model = $this->model ?? (string) config('agent_bus.judge.model', 'jev-latest');
        $connectTimeout = $this->connectTimeout ?? (float) config('agent_bus.judge.connect_timeout', 2.0);
        $timeout = $this->timeout ?? (float) config('agent_bus.judge.timeout', 5.0);

        $payload = [
            'state' => ToolFailureSignals::from($state),
            'model' => $model,
            'questions' => [
                'failure_type' => [
                    'type' => 'choice',
                    'instructions' => 'Classify the primary reason this coding-agent tool call failed.',
                    'criteria' => [
                        'model_misunderstanding' => 'The agent chose the wrong tool, arguments, path, syntax, or interpretation.',
                        'transient_external' => 'A service, process, network dependency, lock, or temporary resource failed and may recover.',
                        'permission_denied' => 'The operation was blocked by filesystem, sandbox, authentication, authorization, or approval boundaries.',
                        'missing_dependency' => 'A required executable, package, service, file, or configuration is absent.',
                        'policy_blocked' => 'The requested operation conflicts with an explicit project or operator rule.',
                        'unknown' => 'The available evidence does not support one of the other categories.',
                    ],
                ],
            ],
        ];

        $headers = [
            'Authorization' => 'Bearer '.$apiKey,
            'Content-Type' => 'application/json',
        ];

        $response = $this->transport !== null
            ? ($this->transport)($endpoint, $headers, $payload, $connectTimeout, $timeout)
            : $this->post($endpoint, $headers, $payload, $connectTimeout, $timeout);

        $failureType = $this->choice($response, 'failure_type');

        if (! in_array($failureType['value'], self::FAILURE_TYPES, true)) {
            throw new RuntimeException('TypeSafe returned an unknown tool-failure choice.');
        }

        return [
            'model' => is_string($response['model'] ?? null) ? $response['model'] : $model,
            'questionVersion' => self::QUESTION_VERSION,
            'confidence' => $failureType['confidence'],
            'failureType' => $failureType,
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{value: string, confidence: float, probabilities: array<string, float>}
     */
    private function choice(array $response, string $question): array
    {
        $answer = $response['answers'][$question] ?? null;

        if (! is_array($answer) || ($answer['type'] ?? null) !== 'choice') {
            throw new RuntimeException("TypeSafe response did not contain a choice answer for {$question}.");
        }

        $value = $answer['choice'] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("TypeSafe response did not choose a value for {$question}.");
        }

        $probabilities = [];

        foreach ((array) ($answer['probabilities'] ?? []) as $key => $probability) {
            if (is_string($key) && is_numeric($probability)) {
                $probabilities[$key] = max(0.0, min(1.0, (float) $probability));
            }
        }

        $confidence = is_numeric($answer['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $answer['confidence']))
            : ($probabilities[$value] ?? 0.0);

        return [
            'value' => $value,
            'confidence' => $confidence,
            'probabilities' => $probabilities,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $endpoint, array $headers, array $payload, float $connectTimeout, float $timeout): array
    {
        $handle = curl_init($endpoint);

        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the TypeSafe HTTP client.');
        }

        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
                CURLOPT_HTTPHEADER => array_map(
                    fn (string $value, string $name): string => $name.': '.$value,
                    $headers,
                    array_keys($headers),
                ),
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

            $body = curl_exec($handle);

            if ($body === false) {
                throw new RuntimeException('TypeSafe request failed: '.curl_error($handle));
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("TypeSafe request returned HTTP {$status}.");
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('TypeSafe returned invalid JSON.', 0, $exception);
            }

            if (! is_array($decoded)) {
                throw new RuntimeException('TypeSafe returned an invalid response body.');
            }

            return $decoded;
        } finally {
            curl_close($handle);
        }
    }
}
