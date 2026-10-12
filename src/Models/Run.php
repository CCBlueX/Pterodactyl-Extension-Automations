<?php

declare(strict_types=1);

namespace Automations\Models;

use Automations\Support\Outcome;
use Automations\Support\Steps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\User;

/**
 * One execution of an automation, with the cursor the queued job continues from.
 *
 * @property int $id
 * @property int $automation_id
 * @property int|null $user_id
 * @property string $status queued, running, success, failed, skipped or cancelled
 * @property string $source schedule or manual
 * @property list<array<string, mixed>> $steps
 * @property array{path: list<int|string>, state: array<string, mixed>|null}|null $cursor
 * @property list<array{at: int, path: list<int|string>, step: string, result: string, message: string}>|null $log
 * @property string|null $message
 * @property CarbonImmutable|null $wake_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable $created_at
 * @property-read Automation $automation
 * @property-read User|null $user
 */
final class Run extends Model
{
    public const array ACTIVE = ['queued', 'running'];

    public const int KEEP = 50;

    private const int LOG_LIMIT = 300;

    protected $table = 'ext_automations_runs';

    protected $fillable = ['automation_id', 'user_id', 'status', 'source', 'steps', 'cursor', 'log', 'message', 'wake_at', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'cursor' => 'array',
            'log' => 'array',
            'wake_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Automation, $this> */
    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Moves a queued run to running; false when it was cancelled or picked up meanwhile. */
    public function begin(): bool
    {
        return $this->change(['queued'], ['status' => 'running', 'started_at' => CarbonImmutable::now(), 'wake_at' => CarbonImmutable::now()]);
    }

    /** Stores what the engine did; false when the run was cancelled while it worked. */
    public function record(Outcome $outcome): bool
    {
        $running = $outcome->status === 'running';

        return $this->change(['running'], [
            'status' => $outcome->status,
            'cursor' => $outcome->cursor,
            'log' => array_slice([...$this->log ?? [], ...$outcome->log], -self::LOG_LIMIT),
            'message' => $outcome->message === null ? null : mb_substr($outcome->message, 0, 500),
            'wake_at' => $running ? CarbonImmutable::createFromTimestamp((int) $outcome->wakeAt, config('app.timezone')) : null,
            'finished_at' => $running ? null : CarbonImmutable::now(),
        ]);
    }

    public function finish(string $status, string $message): bool
    {
        return $this->change(self::ACTIVE, ['status' => $status, 'message' => mb_substr($message, 0, 500), 'wake_at' => null, 'finished_at' => CarbonImmutable::now()]);
    }

    /** @return array<string, mixed> */
    public function present(bool $withLog = false): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'source' => $this->source,
            'user' => $this->user?->username,
            'message' => $this->message,
            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'progress' => $this->isActive() ? $this->progress() : null,
        ] + ($withLog ? ['log' => array_map(static fn (array $entry): array => [
            'at' => CarbonImmutable::createFromTimestamp($entry['at'])->toIso8601String(),
            'position' => Steps::position($entry['path']),
            'step' => $entry['step'],
            'result' => $entry['result'],
            'message' => $entry['message'],
        ], $this->log ?? [])] : []);
    }

    /** @return array{current: int, total: int, step: string, percent: int, until: string|null} */
    private function progress(): array
    {
        $total = max(1, count($this->steps));
        $path = $this->cursor['path'] ?? [0];
        $state = $this->cursor['state'] ?? null;
        $step = Steps::at($this->steps, $path);
        $fraction = 0.0;
        $until = null;

        if ($step !== null && in_array($step['type'], ['wait', 'countdown'], true) && isset($state['from'], $state['until'])) {
            $until = CarbonImmutable::createFromTimestamp($state['until']);
            $fraction = min(1, max(0, (CarbonImmutable::now()->getTimestamp() - $state['from']) / max(1, $state['until'] - $state['from'])));
        }

        return [
            'current' => (int) $path[0] + 1,
            'total' => $total,
            'step' => $this->status === 'queued' ? 'Starting' : ($step === null ? 'Working' : Steps::label($step)),
            'percent' => (int) round(((int) $path[0] + $fraction) / $total * 100),
            'until' => $until?->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function change(array $from, array $attributes): bool
    {
        return DB::transaction(function () use ($from, $attributes): bool {
            $current = self::query()->lockForUpdate()->find($this->id);

            if ($current === null || ! in_array($current->status, $from, true)) {
                return false;
            }

            $current->fill($attributes)->save();
            $this->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
