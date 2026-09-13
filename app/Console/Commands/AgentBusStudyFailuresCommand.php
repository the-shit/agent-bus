<?php

namespace App\Console\Commands;

use App\Models\BusEvent;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('agent-bus:study-failures')]
#[Description('Write a nightly markdown digest of failed .local tool calls. Agents should export AGENT_BUS_MODEL so toolCall envelopes carry the fine-tune model name.')]
class AgentBusStudyFailuresCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $since = now()->subDay();

        $events = BusEvent::query()
            ->where('type', 'toolCall')
            ->where('model', 'like', '%.local')
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('payload->exit_code')
            ->where('payload->exit_code', '<>', 0)
            ->orderByDesc('occurred_at')
            ->get(['id', 'model', 'repo', 'session_id', 'occurred_at', 'payload']);

        $groups = $this->groupByTool($events->all());

        $date = now()->format('Y-m-d');
        $directory = storage_path('app/studies');
        $path = $directory.'/failures-'.$date.'.md';

        $digest = $this->renderDigest($groups, count($events), $date);

        File::ensureDirectoryExists($directory);
        File::put($path, $digest."\n");

        $this->info("Wrote failed-.local study to {$path}");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, BusEvent>  $events
     * @return array<int, array{model: string, tool: string, repo: string|null, failures: int, last_seen: Carbon|null, samples: list<array{session_id: string, occurred_at: Carbon|null}>}>
     */
    private function groupByTool(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $tool = is_string($event->payload['tool'] ?? null) ? $event->payload['tool'] : '';
            $key = $event->model.'|'.$tool.'|'.$event->repo;

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'model' => $event->model,
                    'tool' => $tool,
                    'repo' => $event->repo,
                    'failures' => 0,
                    'last_seen' => null,
                    'samples' => [],
                ];
            }

            $groups[$key]['failures']++;

            if ($groups[$key]['last_seen'] === null || $event->occurred_at?->gt($groups[$key]['last_seen'])) {
                $groups[$key]['last_seen'] = $event->occurred_at;
            }

            if (count($groups[$key]['samples']) < 3) {
                $groups[$key]['samples'][] = [
                    'session_id' => $event->session_id,
                    'occurred_at' => $event->occurred_at,
                ];
            }
        }

        usort($groups, fn (array $a, array $b): int => $b['failures'] <=> $a['failures']);

        return $groups;
    }

    /**
     * @param  array<int, array{model: string, tool: string, repo: string|null, failures: int, last_seen: Carbon|null, samples: list<array{session_id: string, occurred_at: Carbon|null}>}>  $groups
     */
    private function renderDigest(array $groups, int $failures, string $date): string
    {
        $models = count(array_unique(array_column($groups, 'model')));

        $lines = [
            "# Failed .local tool calls - {$date}",
            '',
            sprintf('**%d failed tool call(s)** across **%d .local model(s)** in the last 24 hours.', $failures, $models),
            '',
        ];

        if ($groups === []) {
            $lines[] = 'No failed .local tool calls in the last 24 hours.';

            return implode("\n", $lines);
        }

        foreach ($groups as $group) {
            $lines[] = '## '.implode(' / ', [$group['model'], $group['tool'], $group['repo'] ?? '']);
            $lines[] = '';
            $lines[] = sprintf('- **%d failure(s)**, last seen %s', $group['failures'], $this->formatTimestamp($group['last_seen']));

            foreach ($group['samples'] as $sample) {
                $lines[] = sprintf('- sample session `%s` (%s)', $sample['session_id'], $this->formatTimestamp($sample['occurred_at']));
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function formatTimestamp(?Carbon $timestamp): string
    {
        return $timestamp?->toIso8601String() ?? 'unknown';
    }
}
