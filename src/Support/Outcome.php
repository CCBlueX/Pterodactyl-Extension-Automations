<?php

declare(strict_types=1);

namespace Automations\Support;

/**
 * Where a run stands after the engine worked on it.
 *
 * @phpstan-type Entry array{at: int, path: list<int|string>, step: string, result: string, message: string}
 */
final readonly class Outcome
{
    /**
     * @param  'running'|'success'|'failed'  $status
     * @param  array{path: list<int|string>, state: array<string, mixed>|null}|null  $cursor
     * @param  int|null  $wakeAt  Unix time the engine wants to continue at while running.
     * @param  list<Entry>  $log
     */
    public function __construct(
        public string $status,
        public ?array $cursor,
        public ?int $wakeAt,
        public array $log,
        public ?string $message = null,
    ) {}
}
