<?php

declare(strict_types=1);

namespace Automations\Panel;

use Automations\Settings;
use Automations\Support\ServerListPing;
use Illuminate\Contracts\Container\Container;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Contracts\Servers\ReadsServerState;
use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Exceptions\Http\Server\ServerStateConflictException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The only place this extension touches Panel internals.
 */
final readonly class Panel
{
    public function __construct(
        private Container $container,
        private SendsServerCommands $commands,
        private SendsServerPower $power,
        private ReadsServerState $state,
        private Settings $settings,
    ) {}

    public function sendCommand(Server $server, string $command, ?int $userId, string $automation): void
    {
        $this->commands->send($server, $command);
        $this->activity('server:console.command', $server, $userId, ['command' => $command, 'automation' => $automation]);
    }

    public function sendPower(Server $server, string $signal, ?int $userId, string $automation): void
    {
        $this->power->send($server, $signal);
        $this->activity("server:power.{$signal}", $server, $userId, ['automation' => $automation]);
    }

    /**
     * Starts a backup like a core schedule does, rotating out the oldest unlocked backup at the limit.
     *
     * @param  list<string>  $ignored
     */
    public function startBackup(Server $server, ?string $name, array $ignored, ?int $userId, string $automation): string
    {
        $backup = $this->container->make(InitiatesBackups::class)->setIgnoredFiles($ignored)->initiate($server, $name, true);
        $this->activity('server:backup.start', $server, $userId, ['name' => $backup->name, 'locked' => $backup->is_locked, 'automation' => $automation], $backup);

        return $backup->uuid;
    }

    /** @return 'running'|'success'|'failed' */
    public function backupStatus(string $uuid): string
    {
        $backup = Backup::query()->where('uuid', $uuid)->first();

        return match (true) {
            $backup === null => 'failed',
            $backup->completed_at === null => 'running',
            default => $backup->is_successful ? 'success' : 'failed',
        };
    }

    /** @return array{state: string, cpu: float|null, memory: float|null} */
    public function usage(Server $server): array
    {
        $state = $this->state->read($server);

        return [
            'state' => $state->state,
            'cpu' => $server->cpu > 0 ? $state->cpuAbsolute / $server->cpu * 100 : (float) $state->cpuAbsolute,
            'memory' => $server->memory > 0 ? $state->memoryBytes / ($server->memory * 1024 * 1024) * 100 : null,
        ];
    }

    public function isOffline(Server $server): bool
    {
        try {
            return in_array($this->state->read($server)->state, ['offline', 'stopping', 'stopped'], true);
        } catch (Throwable) {
            return true;
        }
    }

    public function players(Server $server): ?int
    {
        try {
            return ServerListPing::players($this->pingHost($server), (int) $server->allocation->port);
        } catch (Throwable) {
            return null;
        }
    }

    /** Whether the server's egg is a Minecraft Java egg, which can answer a server list ping. */
    public function supportsPlayers(Server $server): bool
    {
        return in_array('eula', array_map(mb_strtolower(...), $server->egg->inherit_features ?? []), true);
    }

    /** Why nothing should run on the server right now, or null. */
    public function unavailable(Server $server): ?string
    {
        try {
            $server->validateCurrentState();

            return null;
        } catch (ServerStateConflictException) {
            return 'The server is suspended, installing, restoring a backup or being transferred.';
        }
    }

    /** A message that is safe to show to the server's users. */
    public function message(Throwable $exception): string
    {
        if ($exception instanceof DisplayException || $exception instanceof HttpExceptionInterface) {
            return $exception->getMessage();
        }

        report($exception);

        return 'Something went wrong. The panel log has the details.';
    }

    /** @param array<string, string|bool> $properties */
    private function activity(string $event, Server $server, ?int $userId, array $properties, ?Backup $backup = null): void
    {
        $log = Activity::event($event)->subject($server, $backup)->property($properties + ['via' => 'automations']);
        $user = $userId === null ? null : User::query()->find($userId);

        if ($user !== null) {
            $log->actor($user);
        }

        $log->log();
    }

    private function pingHost(Server $server): string
    {
        if ($this->settings->pingHost() !== '') {
            return $this->settings->pingHost();
        }

        $allocation = $server->allocation;
        $ip = $allocation->ip_alias ?: $allocation->ip;

        return in_array($ip, ['0.0.0.0', '::'], true) ? $server->node->fqdn : $ip;
    }
}
