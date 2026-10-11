<?php

declare(strict_types=1);

namespace Automations\Support;

use InvalidArgumentException;

/**
 * The ordered step tree of an automation: validation, and walking it with a path such as [2, 'then', 0].
 */
final class Steps
{
    public const int MAX_STEPS = 50;

    public const int MAX_DEPTH = 3;

    public const array TYPES = ['command', 'power', 'backup', 'wait', 'wait_for', 'countdown', 'if', 'webhook'];

    public const array POWER = ['start', 'stop', 'restart', 'kill'];

    public const array STATES = ['running', 'offline', 'starting', 'stopping'];

    public const array COUNTDOWN_POINTS = [3600, 1800, 900, 600, 300, 180, 120, 60, 30, 15, 10, 5, 3, 2, 1];

    private const array LABELS = [
        'command' => 'Send command',
        'power' => 'Power action',
        'backup' => 'Create backup',
        'wait' => 'Wait',
        'wait_for' => 'Wait for state',
        'countdown' => 'Countdown',
        'if' => 'If / else',
        'webhook' => 'Notify',
    ];

    /**
     * @param  callable(string): ?string  $urlProblem  Why a webhook URL may not be used, or null.
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(mixed $steps, bool $players, callable $urlProblem): array
    {
        $count = 0;
        $normalized = self::list($steps, [], 1, $players, $urlProblem, $count);

        if ($normalized === []) {
            throw new InvalidArgumentException('Add at least one step.');
        }

        return $normalized;
    }

    /** Every step in the tree, including the ones inside if/else. */
    public static function count(array $steps): int
    {
        $count = 0;

        foreach ($steps as $step) {
            $count += 1 + ($step['type'] === 'if' ? self::count($step['then']) + self::count($step['else']) : 0);
        }

        return $count;
    }

    /**
     * Core permissions the person saving needs, so the automation cannot do more than they can.
     *
     * @return list<string>
     */
    public static function permissions(array $steps): array
    {
        $permissions = [];

        foreach ($steps as $step) {
            $permission = match ($step['type']) {
                'command', 'countdown' => 'control.console',
                'backup' => 'backup.create',
                'power' => match ($step['action']) {
                    'start' => 'control.start',
                    'restart' => 'control.restart',
                    default => 'control.stop',
                },
                default => null,
            };

            $nested = $step['type'] === 'if' ? self::permissions([...$step['then'], ...$step['else']]) : [];

            foreach ([$permission, ...$nested] as $name) {
                if ($name !== null) {
                    $permissions[$name] = $name;
                }
            }
        }

        return array_values($permissions);
    }

    /**
     * The tree without webhook URLs, for people who may see automations but not change them.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    public static function redact(array $steps): array
    {
        return array_map(static fn (array $step): array => match ($step['type']) {
            'webhook' => ['url' => ''] + $step,
            'if' => ['then' => self::redact($step['then']), 'else' => self::redact($step['else'])] + $step,
            default => $step,
        }, $steps);
    }

    /** @return list<int|string>|null */
    public static function first(array $steps): ?array
    {
        return $steps === [] ? null : [0];
    }

    /** @param list<int|string> $path */
    public static function at(array $steps, array $path): ?array
    {
        $list = $steps;
        $step = null;

        foreach (array_chunk($path, 2) as $pair) {
            $step = $list[$pair[0]] ?? null;

            if (! is_array($step)) {
                return null;
            }

            if (isset($pair[1])) {
                $list = $step[$pair[1]] ?? null;

                if (! is_array($list)) {
                    return null;
                }
            }
        }

        return $step;
    }

    /**
     * The step after the one at the path, leaving if/else branches when they end.
     *
     * @param  list<int|string>  $path
     * @return list<int|string>|null
     */
    public static function next(array $steps, array $path): ?array
    {
        $candidate = [...array_slice($path, 0, -1), (int) end($path) + 1];

        if (self::at($steps, $candidate) !== null) {
            return $candidate;
        }

        return count($path) > 1 ? self::next($steps, array_slice($path, 0, -2)) : null;
    }

    /**
     * The first step of a branch of the if/else at the path, or the step after it when the branch is empty.
     *
     * @param  list<int|string>  $path
     * @return list<int|string>|null
     */
    public static function enter(array $steps, array $path, string $branch): ?array
    {
        return empty(self::at($steps, $path)[$branch]) ? self::next($steps, $path) : [...$path, $branch, 0];
    }

    public static function label(array $step): string
    {
        return self::LABELS[$step['type']] ?? 'Step';
    }

    /**
     * A person-readable position such as "2", "2 › then 1" or "2 › otherwise 3".
     *
     * @param  list<int|string>  $path
     */
    public static function position(array $path): string
    {
        $text = (string) ((int) ($path[0] ?? 0) + 1);

        for ($i = 1; $i + 1 < count($path); $i += 2) {
            $text .= ' › '.($path[$i] === 'else' ? 'otherwise' : 'then').' '.((int) $path[$i + 1] + 1);
        }

        return $text;
    }

    /**
     * @param  list<int|string>  $at
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $steps, array $at, int $depth, bool $players, callable $urlProblem, int &$count): array
    {
        if (! is_array($steps) || ! array_is_list($steps)) {
            throw new InvalidArgumentException(($at === [] ? 'Steps' : 'Step '.self::position($at)).' must be a list.');
        }

        $normalized = [];

        foreach ($steps as $index => $step) {
            if (++$count > self::MAX_STEPS) {
                throw new InvalidArgumentException('An automation can have up to '.self::MAX_STEPS.' steps.');
            }

            $path = [...$at, $index];

            try {
                $normalized[] = self::step($step, $path, $depth, $players, $urlProblem, $count);
            } catch (StepProblem $problem) {
                throw new InvalidArgumentException('Step '.self::position($path).': '.$problem->getMessage());
            }
        }

        return $normalized;
    }

    /**
     * @param  list<int|string>  $path
     * @return array<string, mixed>
     */
    private static function step(mixed $step, array $path, int $depth, bool $players, callable $urlProblem, int &$count): array
    {
        if (! is_array($step) || ! in_array($step['type'] ?? null, self::TYPES, true)) {
            throw new StepProblem('Choose what the step does.');
        }

        $continue = ['continue_on_failure' => (bool) ($step['continue_on_failure'] ?? false)];

        return match ($step['type']) {
            'command' => ['type' => 'command', 'command' => self::line($step['command'] ?? null, 500, 'Enter a command.')] + $continue,
            'power' => ['type' => 'power', 'action' => self::choice($step['action'] ?? null, self::POWER, 'Choose a power action.')] + $continue,
            'backup' => [
                'type' => 'backup',
                'name' => self::optional($step['name'] ?? null, 191),
                'ignored' => self::ignored($step['ignored'] ?? null),
                'wait' => (bool) ($step['wait'] ?? false),
            ] + $continue,
            'wait' => ['type' => 'wait', 'seconds' => self::integer($step['seconds'] ?? null, 1, 86400, 'Wait between 1 second and 24 hours.')],
            'wait_for' => [
                'type' => 'wait_for',
                'state' => self::choice($step['state'] ?? null, ['running', 'offline'], 'Choose the state to wait for.'),
                'timeout' => self::integer($step['timeout'] ?? null, 1, 60, 'Give up after 1 to 60 minutes.'),
            ],
            'countdown' => self::countdown($step),
            'if' => self::condition($step, $path, $depth, $players, $urlProblem, $count),
            'webhook' => self::webhook($step, $urlProblem) + $continue,
        };
    }

    /** @return array<string, mixed> */
    private static function countdown(array $step): array
    {
        $points = [];

        foreach (is_array($step['points'] ?? null) ? $step['points'] : [] as $point) {
            $point = filter_var($point, FILTER_VALIDATE_INT);

            if ($point === false || ! in_array($point, self::COUNTDOWN_POINTS, true)) {
                throw new StepProblem('Choose announcement times from the list.');
            }

            $points[$point] = $point;
        }

        if ($points === []) {
            throw new StepProblem('Choose at least one announcement time.');
        }

        krsort($points);

        return ['type' => 'countdown', 'points' => array_values($points), 'message' => self::line($step['message'] ?? null, 500, 'Enter the announcement, for example say Restarting in {TIME}.')];
    }

    /**
     * @param  list<int|string>  $path
     * @return array<string, mixed>
     */
    private static function condition(array $step, array $path, int $depth, bool $players, callable $urlProblem, int &$count): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new StepProblem('If/else can be nested up to '.(self::MAX_DEPTH - 1).' levels deep.');
        }

        $condition = is_array($step['condition'] ?? null) ? $step['condition'] : [];
        $kind = self::choice($condition['kind'] ?? null, ['state', 'cpu', 'memory', 'players'], 'Choose a condition.');

        if ($kind === 'players' && ! $players) {
            throw new StepProblem('Player counts are only available for Minecraft servers.');
        }

        $condition = $kind === 'state'
            ? ['kind' => 'state', 'state' => self::choice($condition['state'] ?? null, self::STATES, 'Choose a server state.')]
            : [
                'kind' => $kind,
                'op' => self::choice($condition['op'] ?? null, ['above', 'below'], 'Choose above or below.'),
                'value' => $kind === 'players'
                    ? self::integer($condition['value'] ?? null, 0, 100000, 'Enter a player count.')
                    : self::integer($condition['value'] ?? null, 0, $kind === 'cpu' ? 1000 : 100, $kind === 'cpu' ? 'Enter a CPU usage from 0 to 1000%.' : 'Enter a memory usage from 0 to 100%.'),
            ];

        return [
            'type' => 'if',
            'condition' => $condition,
            'then' => self::list($step['then'] ?? [], [...$path, 'then'], $depth + 1, $players, $urlProblem, $count),
            'else' => self::list($step['else'] ?? [], [...$path, 'else'], $depth + 1, $players, $urlProblem, $count),
        ];
    }

    /** @return array<string, mixed> */
    private static function webhook(array $step, callable $urlProblem): array
    {
        $kind = self::choice($step['kind'] ?? null, ['discord', 'webhook'], 'Choose Discord or webhook.');
        $url = self::line($step['url'] ?? null, 500, 'Enter the webhook URL.');

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new StepProblem('Enter a valid webhook URL.');
        }

        if ($kind === 'discord' && preg_match('#^https://(?:\w+\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+#', $url) !== 1) {
            throw new StepProblem('Enter a Discord webhook URL (https://discord.com/api/webhooks/...).');
        }

        if (($problem = $urlProblem($url)) !== null) {
            throw new StepProblem($problem);
        }

        $message = is_string($step['message'] ?? null) ? mb_trim($step['message']) : '';

        if ($message === '' || mb_strlen($message) > 2000) {
            throw new StepProblem('Enter a message of up to 2000 characters.');
        }

        return ['type' => 'webhook', 'kind' => $kind, 'url' => $url, 'message' => $message];
    }

    private static function line(mixed $value, int $max, string $problem): string
    {
        $value = is_string($value) ? mb_trim($value) : '';

        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\r\n]/', $value) === 1) {
            throw new StepProblem($problem);
        }

        return $value;
    }

    private static function optional(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? mb_trim($value) : '';

        if (mb_strlen($value) > $max || preg_match('/[\r\n]/', $value) === 1) {
            throw new StepProblem("Use up to {$max} characters on one line.");
        }

        return $value === '' ? null : $value;
    }

    private static function ignored(mixed $value): string
    {
        $value = is_string($value) ? $value : '';

        if (mb_strlen($value) > 5000) {
            throw new StepProblem('Ignored files can be up to 5000 characters.');
        }

        return implode("\n", array_values(array_filter(array_map(mb_trim(...), preg_split('/\R/', $value) ?: []), static fn (string $line): bool => $line !== '')));
    }

    /** @param list<string> $choices */
    private static function choice(mixed $value, array $choices, string $problem): string
    {
        if (! is_string($value) || ! in_array($value, $choices, true)) {
            throw new StepProblem($problem);
        }

        return $value;
    }

    private static function integer(mixed $value, int $min, int $max, string $problem): int
    {
        $value = is_int($value) || is_string($value) || is_float($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;

        if ($value === false || $value < $min || $value > $max) {
            throw new StepProblem($problem);
        }

        return $value;
    }
}
