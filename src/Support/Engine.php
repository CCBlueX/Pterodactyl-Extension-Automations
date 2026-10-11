<?php

declare(strict_types=1);

namespace Automations\Support;

/**
 * Carries out the steps of a run from a cursor until it has to wait, runs out of time or is done.
 * It never sleeps: waiting steps hand back the time they want to continue at.
 *
 * @phpstan-import-type Entry from Outcome
 *
 * @phpstan-type Result array{log: list<Entry>, pause: int|null, state: array<string, mixed>|null, failed: bool, message: string|null, branch: string|null}
 */
final readonly class Engine
{
    public const int POLL_SECONDS = 15;

    public const int BACKUP_TIMEOUT = 3600;

    /** @param int $budget Seconds after which no new step is started in this pass. */
    public function __construct(private Actions $actions, private int $budget = 20) {}

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array{path: list<int|string>, state: array<string, mixed>|null}|null  $cursor
     */
    public function advance(array $steps, ?array $cursor): Outcome
    {
        $started = $this->actions->now();
        $path = $cursor === null ? Steps::first($steps) : $cursor['path'];
        $state = $cursor['state'] ?? null;
        $log = [];
        $fresh = true;

        while ($path !== null) {
            $step = Steps::at($steps, $path);

            if ($step === null) {
                return new Outcome('failed', null, null, $log, 'The run lost track of its steps.');
            }

            if (! $fresh && $this->actions->now() - $started >= $this->budget) {
                return new Outcome('running', ['path' => $path, 'state' => $state], $this->actions->now(), $log);
            }

            $result = $this->execute($step, $path, $state);
            $fresh = false;
            array_push($log, ...$result['log']);

            if ($result['pause'] !== null) {
                return new Outcome('running', ['path' => $path, 'state' => $result['state']], $result['pause'], $log);
            }

            if ($result['failed'] && ! ($step['continue_on_failure'] ?? false)) {
                return new Outcome('failed', ['path' => $path, 'state' => null], null, $log, $result['message']);
            }

            $path = $result['branch'] === null ? Steps::next($steps, $path) : Steps::enter($steps, $path, $result['branch']);
            $state = null;
        }

        return new Outcome('success', null, null, $log);
    }

    private static function matches(string $current, string $wanted): bool
    {
        return $wanted === 'offline' ? in_array($current, ['offline', 'stopped'], true) : $current === $wanted;
    }

    private static function compare(float|int $value, string $op, int $threshold): bool
    {
        return $op === 'above' ? $value > $threshold : $value < $threshold;
    }

    /**
     * @param  list<int|string>  $path
     * @param  array<string, mixed>|null  $state
     * @return Result
     */
    private function execute(array $step, array $path, ?array $state): array
    {
        try {
            return match ($step['type']) {
                'command' => $this->command($step, $path),
                'power' => $this->power($step, $path),
                'backup' => $this->backup($step, $path, $state),
                'wait' => $this->wait($step, $path, $state),
                'wait_for' => $this->waitFor($step, $path, $state),
                'countdown' => $this->countdown($step, $path, $state),
                'if' => $this->condition($step, $path),
                'webhook' => $this->webhook($step, $path),
                default => $this->done($step, $path, 'failed', 'This step type is not known.'),
            };
        } catch (StepFailed $failure) {
            return $this->done($step, $path, 'failed', $failure->getMessage());
        }
    }

    /** @return Result */
    private function command(array $step, array $path): array
    {
        $this->actions->command($step['command']);

        return $this->done($step, $path, 'ok', "Sent: {$step['command']}");
    }

    /** @return Result */
    private function power(array $step, array $path): array
    {
        $this->actions->power($step['action']);

        return $this->done($step, $path, 'ok', "Sent the {$step['action']} signal.");
    }

    /** @return Result */
    private function backup(array $step, array $path, ?array $state): array
    {
        $now = $this->actions->now();

        if ($state === null) {
            $backup = $this->actions->backup($step['name'], $step['ignored'] === '' ? [] : explode("\n", $step['ignored']));

            if (! $step['wait']) {
                return $this->done($step, $path, 'ok', 'Backup started.');
            }

            return $this->pause(['backup' => $backup, 'from' => $now, 'until' => $now + self::BACKUP_TIMEOUT], $now + self::POLL_SECONDS, [$this->entry($step, $path, 'info', 'Backup started, waiting for it to finish.')]);
        }

        return match ($this->actions->backupStatus($state['backup'])) {
            'success' => $this->done($step, $path, 'ok', 'The backup finished.'),
            'failed' => $this->done($step, $path, 'failed', 'The backup failed.'),
            default => $now >= $state['until']
                ? $this->done($step, $path, 'failed', 'The backup did not finish within '.Duration::words(self::BACKUP_TIMEOUT).'.')
                : $this->pause($state, min($now + self::POLL_SECONDS, $state['until'])),
        };
    }

    /** @return Result */
    private function wait(array $step, array $path, ?array $state): array
    {
        $now = $this->actions->now();
        $state ??= ['from' => $now, 'until' => $now + $step['seconds']];

        return $now >= $state['until'] ? $this->done($step, $path, 'ok', 'Waited '.Duration::words($step['seconds']).'.') : $this->pause($state, $state['until']);
    }

    /** @return Result */
    private function waitFor(array $step, array $path, ?array $state): array
    {
        $now = $this->actions->now();
        $state ??= ['from' => $now, 'until' => $now + $step['timeout'] * 60];
        $current = $this->state();

        if ($current !== null && self::matches($current, $step['state'])) {
            return $this->done($step, $path, 'ok', "The server is {$step['state']}.");
        }

        if ($now >= $state['until']) {
            return $this->done($step, $path, 'failed', 'Gave up after '.Duration::words($step['timeout'] * 60).': the server is '.($current ?? 'not reachable').'.');
        }

        return $this->pause($state, min($now + self::POLL_SECONDS, $state['until']));
    }

    /** @return Result */
    private function countdown(array $step, array $path, ?array $state): array
    {
        $now = $this->actions->now();
        $points = $step['points'];
        $total = $points[0];
        $state ??= ['from' => $now, 'until' => $now + $total, 'next' => 0];
        $elapsed = $now - $state['from'];
        $log = [];

        // After a late pass only the latest due announcement is sent, never a burst of stale ones.
        $due = null;
        for ($i = $state['next']; $i < count($points) && $total - $points[$i] <= $elapsed; $i++) {
            $due = $i;
        }

        if ($due !== null) {
            $log[] = $this->announce($step, $path, $points[$due]);
            $state['next'] = $due + 1;
        }

        if ($state['next'] < count($points)) {
            return $this->pause($state, $state['from'] + $total - $points[$state['next']], $log);
        }

        return $elapsed >= $total ? $this->done($step, $path, 'ok', 'Countdown finished.', $log) : $this->pause($state, $state['until'], $log);
    }

    /** @return Entry */
    private function announce(array $step, array $path, int $point): array
    {
        $message = str_replace('{TIME}', Duration::words($point), $step['message']);

        try {
            $this->actions->command($message);

            return $this->entry($step, $path, 'ok', "Announced: {$message}");
        } catch (StepFailed $failure) {
            return $this->entry($step, $path, 'skipped', 'Could not announce '.Duration::words($point).': '.$failure->getMessage());
        }
    }

    /** @return Result */
    private function condition(array $step, array $path): array
    {
        $condition = $step['condition'];

        if ($condition['kind'] === 'state') {
            $current = $this->state() ?? 'offline';
            $matched = self::matches($current, $condition['state']);
            $message = "The server is {$current}.";
        } elseif ($condition['kind'] === 'players') {
            $players = $this->actions->players();
            $matched = self::compare($players ?? 0, $condition['op'], $condition['value']);
            $message = $players === null
                ? 'The server did not answer, so 0 players were counted.'
                : sprintf('%d %s online, %s%s %d.', $players, $players === 1 ? 'player' : 'players', $matched ? '' : 'not ', $condition['op'], $condition['value']);
        } else {
            $usage = $this->actions->usage()[$condition['kind']];
            $name = $condition['kind'] === 'cpu' ? 'CPU' : 'Memory';
            $matched = $usage !== null && self::compare($usage, $condition['op'], $condition['value']);
            $message = $usage === null
                ? "The server has no {$condition['kind']} limit, so the condition is false."
                : sprintf('%s is at %s%%, %s%s %d%%.', $name, round($usage, 1), $matched ? '' : 'not ', $condition['op'], $condition['value']);
        }

        return $this->done($step, $path, 'ok', $message.($matched ? ' Running then.' : ' Running otherwise.'), branch: $matched ? 'then' : 'else');
    }

    /** @return Result */
    private function webhook(array $step, array $path): array
    {
        $this->actions->notify($step['kind'], $step['url'], $step['message']);

        return $this->done($step, $path, 'ok', $step['kind'] === 'discord' ? 'Sent to Discord.' : 'Sent the webhook.');
    }

    private function state(): ?string
    {
        try {
            return $this->actions->usage()['state'];
        } catch (StepFailed) {
            return null;
        }
    }

    /**
     * @param  list<Entry>  $log
     * @return Result
     */
    private function done(array $step, array $path, string $result, string $message, array $log = [], ?string $branch = null): array
    {
        return ['log' => [...$log, $this->entry($step, $path, $result, $message)], 'pause' => null, 'state' => null, 'failed' => $result === 'failed', 'message' => $message, 'branch' => $branch];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<Entry>  $log
     * @return Result
     */
    private function pause(array $state, int $until, array $log = []): array
    {
        return ['log' => $log, 'pause' => $until, 'state' => $state, 'failed' => false, 'message' => null, 'branch' => null];
    }

    /** @return Entry */
    private function entry(array $step, array $path, string $result, string $message): array
    {
        return ['at' => $this->actions->now(), 'path' => $path, 'step' => Steps::label($step), 'result' => $result, 'message' => $message];
    }
}
