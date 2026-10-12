<?php

declare(strict_types=1);

namespace Automations\Models;

use Automations\Support\Steps;
use Automations\Support\Trigger;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Pterodactyl\Models\Server;

/**
 * @property int $id
 * @property int $server_id
 * @property string $name
 * @property bool $enabled
 * @property bool $skip_offline
 * @property array<string, mixed> $trigger
 * @property list<array<string, mixed>> $steps
 * @property CarbonImmutable|null $next_run_at
 * @property-read Server $server
 * @property-read Run|null $lastRun
 */
final class Automation extends Model
{
    public const int LIMIT = 25;

    protected $table = 'ext_automations_automations';

    protected $fillable = ['server_id', 'name', 'enabled', 'skip_offline', 'trigger', 'steps', 'next_run_at'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'skip_offline' => 'boolean', 'trigger' => 'array', 'steps' => 'array', 'next_run_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /** The newest run, which is the active one while it runs. @return HasOne<Run, $this> */
    public function lastRun(): HasOne
    {
        return $this->hasOne(Run::class)->latestOfMany();
    }

    /** In the panel's time zone, because datetime columns store wall-clock time. */
    public function nextRun(?DateTimeInterface $after = null): ?CarbonImmutable
    {
        return $this->enabled ? CarbonImmutable::instance(Trigger::fromArray($this->trigger)->next($after ?? CarbonImmutable::now()))->setTimezone(config('app.timezone')) : null;
    }

    /** @return array<string, mixed> */
    public function present(bool $secrets = true): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'skip_offline' => $this->skip_offline,
            'trigger' => $this->trigger,
            'summary' => Trigger::fromArray($this->trigger)->summary(),
            'steps' => $secrets ? $this->steps : Steps::redact($this->steps),
            'step_count' => Steps::count($this->steps),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run' => $this->lastRun?->present(),
        ];
    }
}
