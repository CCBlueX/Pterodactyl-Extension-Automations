<?php

declare(strict_types=1);

namespace Automations\Http\Controllers;

use Automations\Http\AutomationInput;
use Automations\Models\Automation;
use Automations\Models\Run;
use Automations\Panel\Panel;
use Automations\Runner\Launcher;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;

final readonly class AutomationController
{
    public function __construct(private Panel $panel, private AutomationInput $input, private Launcher $launcher) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        $manage = $this->can($request, $server, 'manage');

        return new JsonResponse([
            'automations' => Automation::query()->where('server_id', $server->id)->with('lastRun.user')->orderBy('name')->orderBy('id')->get()
                ->map(static fn (Automation $automation): array => $automation->present($manage)),
            'can_manage' => $manage,
            'can_run' => $this->can($request, $server, 'run'),
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        abort_if(Automation::query()->where('server_id', $server->id)->count() >= Automation::LIMIT, 422, 'A server can have up to '.Automation::LIMIT.' automations.');

        $automation = new Automation(['server_id' => $server->id] + $this->input->validate($request, $server, $this->panel->supportsPlayers($server)));
        $automation->next_run_at = $automation->nextRun();
        $automation->save();

        return $this->index($request, $server);
    }

    public function update(Request $request, Server $server, int $id): JsonResponse
    {
        $this->authorize($request, $server, 'manage');

        $automation = $this->find($server, $id);
        $automation->fill($this->input->validate($request, $server, $this->panel->supportsPlayers($server)));
        $automation->next_run_at = $automation->nextRun();
        $automation->save();

        return $this->index($request, $server);
    }

    public function toggle(Request $request, Server $server, int $id): JsonResponse
    {
        $this->authorize($request, $server, 'manage');

        $automation = $this->find($server, $id);
        $automation->enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $automation->next_run_at = $automation->nextRun();
        $automation->save();

        return $this->index($request, $server);
    }

    public function destroy(Request $request, Server $server, int $id): JsonResponse
    {
        $this->authorize($request, $server, 'manage');

        $this->find($server, $id)->delete();

        return $this->index($request, $server);
    }

    public function run(Request $request, Server $server, int $id): JsonResponse
    {
        $this->authorize($request, $server, 'run');

        abort_if($this->launcher->start($this->find($server, $id), 'manual', $request->user()->id) === null, 409, 'This automation is already running.');

        return $this->index($request, $server);
    }

    public function cancel(Request $request, Server $server, int $id): JsonResponse
    {
        $this->authorize($request, $server, 'run');

        $run = Run::query()->where('automation_id', $this->find($server, $id)->id)->whereIn('status', Run::ACTIVE)->latest('id')->first();
        abort_if($run === null || ! $run->finish('cancelled', "Cancelled by {$request->user()->username}."), 409, 'This automation is not running.');

        return $this->index($request, $server);
    }

    public function runs(Request $request, Server $server, int $id): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        return new JsonResponse([
            'runs' => Run::query()->where('automation_id', $this->find($server, $id)->id)->with('user')->orderByDesc('id')->limit(20)->get()
                ->map(static fn (Run $run): array => $run->present(true)),
        ]);
    }

    public function preview(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');

        $trigger = AutomationInput::trigger($request->input('trigger'));

        return new JsonResponse([
            'summary' => $trigger->summary(),
            'next' => array_map(static fn (DateTimeImmutable $time): string => $time->format(DATE_ATOM), $trigger->upcoming(CarbonImmutable::now())),
        ]);
    }

    private function find(Server $server, int $id): Automation
    {
        return Automation::query()->where('server_id', $server->id)->findOrFail($id);
    }

    private function can(Request $request, Server $server, string $permission): bool
    {
        return (bool) $request->user()?->can("ext.automations.{$permission}", $server);
    }

    private function authorize(Request $request, Server $server, string $permission): void
    {
        abort_unless($this->can($request, $server, $permission), 403, 'You do not have permission to do that.');
    }
}
