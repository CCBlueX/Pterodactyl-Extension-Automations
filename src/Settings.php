<?php

declare(strict_types=1);

namespace Automations;

use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final readonly class Settings
{
    public function __construct(private ExtensionSettingsRegistry $registry) {}

    public function allowPrivateTargets(): bool
    {
        return (bool) $this->get('allow_private_targets', false);
    }

    /** Host the panel uses to reach game servers for player counts, or an empty string for the allocation address. */
    public function pingHost(): string
    {
        return mb_trim((string) $this->get('ping_host', ''));
    }

    private function get(string $key, mixed $default): mixed
    {
        return $this->registry->get('automations')?->get($key) ?? $default;
    }
}
