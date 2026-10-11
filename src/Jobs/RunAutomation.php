<?php

declare(strict_types=1);

namespace Automations\Jobs;

use Automations\Models\Run;
use Automations\Panel\Panel;
use Automations\Runner\ServerActions;
use Automations\Runner\Webhooks;
use Automations\Support\Engine;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Works on a run for a few seconds, stores its cursor and queues itself again for when the run wants to continue.
 */
final class RunAutomation implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    private const int GIVE_UP_AFTER_HOURS = 48;

    public int $tries = 1;

    public int $timeout = 50;

    public function __construct(public int $run)
    {
        $this->onQueue('standard');
    }

    public function handle(Panel $panel, Webhooks $webhooks): void
    {
        $run = Run::query()->with(['automation.server', 'user'])->find($this->run);

        if ($run === null || ! $run->isActive()) {
            return;
        }

        try {
            if ($run->status === 'queued' && ! $this->begin($run, $panel)) {
                return;
            }

            if ($run->started_at?->lt(CarbonImmutable::now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
                $run->finish('failed', 'The run took longer than '.self::GIVE_UP_AFTER_HOURS.' hours and was ended.');

                return;
            }

            $outcome = (new Engine(new ServerActions($panel, $webhooks, $run)))->advance($run->steps, $run->cursor);
        } catch (Throwable $exception) {
            report($exception);
            $run->finish('failed', 'Something went wrong. The panel log has the details.');

            return;
        }

        if (! $run->record($outcome) || $outcome->status !== 'running') {
            return;
        }

        try {
            self::dispatch($run->id)->delay(max(0, (int) $outcome->wakeAt - CarbonImmutable::now()->getTimestamp()));
        } catch (Throwable $exception) {
            report($exception);
            $run->finish('failed', 'The run could not be queued again.');
        }
    }

    /** Checks the server before the first step; false when the run ends here. */
    private function begin(Run $run, Panel $panel): bool
    {
        $server = $run->automation->server;

        if (($reason = $panel->unavailable($server)) !== null) {
            $run->finish('skipped', $reason);

            return false;
        }

        if ($run->automation->skip_offline && $panel->isOffline($server)) {
            $run->finish('skipped', 'The server was offline.');

            return false;
        }

        return $run->begin();
    }
}
