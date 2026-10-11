<?php

declare(strict_types=1);

namespace Automations\Http;

use Automations\Settings;
use Automations\Support\Steps;
use Automations\Support\Targets;
use Automations\Support\Trigger;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Pterodactyl\Models\Server;

final readonly class AutomationInput
{
    private const array PERMISSION_NAMES = [
        'control.console' => 'send console commands',
        'control.start' => 'start the server',
        'control.stop' => 'stop the server',
        'control.restart' => 'restart the server',
        'backup.create' => 'create backups',
    ];

    public function __construct(private Settings $settings) {}

    public static function trigger(mixed $data): Trigger
    {
        try {
            return Trigger::fromArray($data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['trigger' => $exception->getMessage()]);
        }
    }

    /** @return array{name: string, enabled: bool, skip_offline: bool, trigger: array<string, mixed>, steps: list<array<string, mixed>>} */
    public function validate(Request $request, Server $server, bool $players): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'enabled' => ['boolean'],
            'skip_offline' => ['boolean'],
            'trigger' => ['required', 'array'],
            'steps' => ['required', 'array'],
        ]);

        try {
            $steps = Steps::normalize($data['steps'], $players, fn (string $url): ?string => Targets::problem($url, $this->settings->allowPrivateTargets()));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['steps' => $exception->getMessage()]);
        }

        foreach (Steps::permissions($steps) as $permission) {
            abort_unless((bool) $request->user()?->can($permission, $server), 403, 'You need permission to '.(self::PERMISSION_NAMES[$permission] ?? $permission).' for these steps.');
        }

        return [
            'name' => mb_trim($data['name']),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'skip_offline' => (bool) ($data['skip_offline'] ?? false),
            'trigger' => self::trigger($data['trigger'])->toArray(),
            'steps' => $steps,
        ];
    }
}
