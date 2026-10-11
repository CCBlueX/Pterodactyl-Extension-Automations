<?php

declare(strict_types=1);

namespace Automations\Runner;

use Automations\Jobs\RunAutomation;
use Automations\Models\Automation;
use Automations\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Starts runs, one at a time per automation.
 */
final readonly class Launcher
{
    /** The queued run, or null when the automation is already running. */
    public function start(Automation $automation, string $source, ?int $userId = null): ?Run
    {
        $run = DB::transaction(static function () use ($automation, $source, $userId): ?Run {
            $locked = Automation::query()->lockForUpdate()->find($automation->id);

            if ($locked === null || Run::query()->where('automation_id', $locked->id)->whereIn('status', Run::ACTIVE)->exists()) {
                return null;
            }

            return Run::query()->create([
                'automation_id' => $locked->id,
                'user_id' => $userId,
                'status' => 'queued',
                'source' => $source,
                'steps' => $locked->steps,
                'wake_at' => CarbonImmutable::now(),
            ]);
        });

        if ($run === null) {
            return null;
        }

        try {
            RunAutomation::dispatch($run->id);
        } catch (Throwable $exception) {
            report($exception);
            $run->finish('failed', 'The run could not be queued.');
        }

        $this->prune($automation);

        return $run;
    }

    private function prune(Automation $automation): void
    {
        $keep = Run::query()->where('automation_id', $automation->id)->orderByDesc('id')->skip(Run::KEEP)->value('id');

        if ($keep !== null) {
            Run::query()->where('automation_id', $automation->id)->where('id', '<=', $keep)->delete();
        }
    }
}
