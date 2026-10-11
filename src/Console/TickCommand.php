<?php

declare(strict_types=1);

namespace Automations\Console;

use Automations\Models\Automation;
use Automations\Models\Run;
use Automations\Runner\Launcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final class TickCommand extends Command
{
    /** A run that is this late for its next pass lost its queued job. */
    private const int STALLED_MINUTES = 15;

    /** Runs missed by more than this, for example while the panel was down, are not caught up. */
    private const int LATE_MINUTES = 15;

    protected $signature = 'automations:tick';

    protected $description = 'Queue the automations that are due.';

    public function handle(Launcher $launcher): int
    {
        $now = CarbonImmutable::now();

        Run::query()->whereIn('status', Run::ACTIVE)->where('wake_at', '<', $now->subMinutes(self::STALLED_MINUTES))->get()
            ->each(static fn (Run $run): bool => $run->finish('failed', 'The run stopped responding and was ended.'));

        Automation::query()->where('enabled', true)->where('next_run_at', '<=', $now)->chunkById(100, function (Collection $automations) use ($launcher, $now): void {
            foreach ($automations as $automation) {
                $late = $automation->next_run_at->lt($now->subMinutes(self::LATE_MINUTES));

                try {
                    $automation->update(['next_run_at' => $automation->nextRun($now)]);

                    if (! $late) {
                        $launcher->start($automation, 'schedule');
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });

        return self::SUCCESS;
    }
}
