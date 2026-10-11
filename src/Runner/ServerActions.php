<?php

declare(strict_types=1);

namespace Automations\Runner;

use Automations\Models\Run;
use Automations\Panel\Panel;
use Automations\Support\Actions;
use Automations\Support\StepFailed;
use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * The engine's actions for one run, carried out through the panel's contracts.
 */
final readonly class ServerActions implements Actions
{
    public function __construct(private Panel $panel, private Webhooks $webhooks, private Run $run) {}

    public function now(): int
    {
        return CarbonImmutable::now()->getTimestamp();
    }

    public function command(string $command): void
    {
        $this->attempt(fn () => $this->panel->sendCommand($this->run->automation->server, $command, $this->run->user_id, $this->run->automation->name));
    }

    public function power(string $action): void
    {
        $this->attempt(fn () => $this->panel->sendPower($this->run->automation->server, $action, $this->run->user_id, $this->run->automation->name));
    }

    public function backup(?string $name, array $ignored): string
    {
        return $this->attempt(fn (): string => $this->panel->startBackup($this->run->automation->server, $name ?? "{$this->run->automation->name} (automation)", $ignored, $this->run->user_id, $this->run->automation->name));
    }

    public function backupStatus(string $backup): string
    {
        return $this->panel->backupStatus($backup);
    }

    public function usage(): array
    {
        return $this->attempt(fn (): array => $this->panel->usage($this->run->automation->server));
    }

    public function players(): ?int
    {
        return $this->panel->players($this->run->automation->server);
    }

    public function notify(string $kind, string $url, string $message): void
    {
        $server = $this->run->automation->server;

        $this->webhooks->send($kind, $url, $message, ['automation' => $this->run->automation->name, 'server' => $server->name, 'identifier' => $server->uuidShort, 'run' => $this->run->id]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function attempt(Closure $call): mixed
    {
        try {
            return $call();
        } catch (StepFailed $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            throw new StepFailed($this->panel->message($exception), previous: $exception);
        }
    }
}
