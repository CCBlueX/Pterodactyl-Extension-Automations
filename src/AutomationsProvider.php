<?php

declare(strict_types=1);

namespace Automations;

use Automations\Console\TickCommand;
use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class AutomationsProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();

        $this->registerPermissions('Automations.', [
            'read' => 'See automations and their history.',
            'manage' => 'Create, edit and delete automations. Steps also need the matching console, power or backup permission.',
            'run' => 'Run and cancel automations now, whatever steps they contain.',
        ]);

        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('allow_private_targets', 'allow_private_targets', false, ['boolean'])
                ->label('Allow private webhook targets')
                ->help('Lets notify steps post to private networks, for example a bot on the same host.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('ping_host', 'ping_host', '', ['nullable', 'string', 'max:255'])
                ->label('Player count host')
                ->help('Host the panel uses to reach Minecraft servers for player conditions. Leave empty to use the allocation address.'),
        ]));

        $this->registerCommands([TickCommand::class]);
        $this->registerSchedule(static function (Schedule $schedule): void {
            $schedule->command('automations:tick')->everyMinute()->withoutOverlapping(5)->runInBackground();
        });
    }
}
