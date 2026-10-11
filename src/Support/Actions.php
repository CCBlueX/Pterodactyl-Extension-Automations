<?php

declare(strict_types=1);

namespace Automations\Support;

/**
 * What the engine can do to a server. Every method throws StepFailed when it cannot.
 */
interface Actions
{
    /** Unix time in seconds. */
    public function now(): int;

    public function command(string $command): void;

    public function power(string $action): void;

    /**
     * @param  list<string>  $ignored
     * @return string The backup's identifier.
     */
    public function backup(?string $name, array $ignored): string;

    /** @return 'running'|'success'|'failed' */
    public function backupStatus(string $backup): string;

    /** @return array{state: string, cpu: float|null, memory: float|null} Usage in percent of the server's limits, null without a limit. */
    public function usage(): array;

    /** Players online, or null when the server does not answer. */
    public function players(): ?int;

    public function notify(string $kind, string $url, string $message): void;
}
