<?php

declare(strict_types=1);

namespace Automations\Tests\Unit;

use Automations\Support\Actions;
use Automations\Support\Engine;
use Automations\Support\Outcome;
use Automations\Support\StepFailed;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private FakeServer $server;

    protected function setUp(): void
    {
        $this->server = new FakeServer;
    }

    public function test_a_restart_with_countdown_resumes_from_the_stored_cursor(): void
    {
        $steps = [
            ['type' => 'countdown', 'points' => [600, 300, 60, 30], 'message' => 'say Restarting in {TIME}'],
            ['type' => 'command', 'command' => 'save-all', 'continue_on_failure' => false],
            ['type' => 'power', 'action' => 'restart', 'continue_on_failure' => false],
            ['type' => 'wait_for', 'state' => 'running', 'timeout' => 5],
            ['type' => 'command', 'command' => 'say Back online', 'continue_on_failure' => false],
        ];

        $passes = $this->runToEnd($steps);

        $this->assertSame([
            [0, 'say Restarting in 10 minutes'],
            [300, 'say Restarting in 5 minutes'],
            [540, 'say Restarting in 1 minute'],
            [570, 'say Restarting in 30 seconds'],
            [600, 'save-all'],
            [600, 'power:restart'],
            [630, 'say Back online'],
        ], $this->server->sent);
        $this->assertSame('success', end($passes)->status);
        // Wakes at each announcement, at zero, then polls the state twice.
        $this->assertSame([300, 540, 570, 600, 615, 630], array_map(static fn (Outcome $outcome): ?int => $outcome->wakeAt, array_slice($passes, 0, -1)));
    }

    public function test_a_late_pass_announces_only_the_latest_point(): void
    {
        $engine = new Engine($this->server);
        $steps = [['type' => 'countdown', 'points' => [600, 300, 60], 'message' => 'say {TIME}']];

        $outcome = $engine->advance($steps, null);
        $this->server->clock = 580;
        $outcome = $engine->advance($steps, self::roundTrip($outcome->cursor));

        $this->assertSame([[0, 'say 10 minutes'], [580, 'say 1 minute']], $this->server->sent);
        $this->assertSame(600, $outcome->wakeAt);
    }

    public function test_conditions_pick_a_branch(): void
    {
        $steps = [
            ['type' => 'if', 'condition' => ['kind' => 'players', 'op' => 'below', 'value' => 1], 'then' => [
                ['type' => 'power', 'action' => 'restart', 'continue_on_failure' => false],
            ], 'else' => [
                ['type' => 'if', 'condition' => ['kind' => 'memory', 'op' => 'above', 'value' => 90], 'then' => [
                    ['type' => 'command', 'command' => 'say Memory is high', 'continue_on_failure' => false],
                ], 'else' => []],
            ]],
            ['type' => 'command', 'command' => 'say done', 'continue_on_failure' => false],
        ];

        $this->server->players = 4;
        $this->server->memory = 95.0;
        $outcome = (new Engine($this->server))->advance($steps, null);

        $this->assertSame('success', $outcome->status);
        $this->assertSame(['say Memory is high', 'say done'], array_column($this->server->sent, 1));
        $this->assertSame('4 players online, not below 1. Running otherwise.', $outcome->log[0]['message']);
        $this->assertSame('Memory is at 95%, above 90%. Running then.', $outcome->log[1]['message']);
        $this->assertSame([0, 'else', 0, 'then', 0], $outcome->log[2]['path']);
    }

    public function test_an_unreachable_minecraft_server_counts_as_empty(): void
    {
        $this->server->players = null;
        $steps = [['type' => 'if', 'condition' => ['kind' => 'players', 'op' => 'below', 'value' => 1], 'then' => [['type' => 'command', 'command' => 'x', 'continue_on_failure' => false]], 'else' => []]];

        (new Engine($this->server))->advance($steps, null);

        $this->assertSame(['x'], array_column($this->server->sent, 1));
    }

    public function test_a_failed_step_stops_the_run_unless_it_may_fail(): void
    {
        $this->server->online = false;
        $steps = [
            ['type' => 'command', 'command' => 'say hi', 'continue_on_failure' => true],
            ['type' => 'webhook', 'kind' => 'discord', 'url' => 'https://discord.com/api/webhooks/1/a', 'message' => 'hi', 'continue_on_failure' => false],
            ['type' => 'command', 'command' => 'say bye', 'continue_on_failure' => false],
            ['type' => 'webhook', 'kind' => 'discord', 'url' => 'https://discord.com/api/webhooks/1/a', 'message' => 'never', 'continue_on_failure' => false],
        ];

        $outcome = (new Engine($this->server))->advance($steps, null);

        $this->assertSame('failed', $outcome->status);
        $this->assertSame('Server must be online in order to send commands.', $outcome->message);
        $this->assertSame(['failed', 'ok', 'failed'], array_column($outcome->log, 'result'));
        $this->assertSame([2], $outcome->cursor['path']);
        $this->assertSame(['discord:hi'], array_column($this->server->sent, 1));
    }

    public function test_waiting_for_a_state_gives_up(): void
    {
        $steps = [['type' => 'wait_for', 'state' => 'offline', 'timeout' => 1]];

        $passes = $this->runToEnd($steps);
        $last = end($passes);

        $this->assertSame('failed', $last->status);
        $this->assertSame('Gave up after 1 minute: the server is running.', $last->message);
        $this->assertSame(60, $this->server->clock);
    }

    public function test_a_backup_can_be_awaited(): void
    {
        $steps = [
            ['type' => 'backup', 'name' => null, 'ignored' => "logs\ncache", 'wait' => true, 'continue_on_failure' => false],
            ['type' => 'power', 'action' => 'start', 'continue_on_failure' => false],
        ];
        $this->server->backupDoneAt = 40;

        $passes = $this->runToEnd($steps);

        $this->assertSame([[0, 'backup:logs,cache'], [45, 'power:start']], $this->server->sent);
        $this->assertSame('success', end($passes)->status);
        $this->assertSame(['info', 'ok', 'ok'], array_column(array_merge(...array_map(static fn (Outcome $outcome): array => $outcome->log, $passes)), 'result'));
    }

    public function test_long_runs_hand_over_to_the_next_pass(): void
    {
        $this->server->commandSeconds = 8;
        $steps = array_fill(0, 5, ['type' => 'command', 'command' => 'say x', 'continue_on_failure' => false]);

        $first = (new Engine($this->server, 20))->advance($steps, null);

        $this->assertSame('running', $first->status);
        $this->assertSame(['path' => [3], 'state' => null], $first->cursor);
        $this->assertSame(24, $first->wakeAt);

        $second = (new Engine($this->server, 20))->advance($steps, self::roundTrip($first->cursor));
        $this->assertSame('success', $second->status);
        $this->assertCount(5, $this->server->sent);
    }

    public function test_plain_waits_sleep_until_their_deadline(): void
    {
        $engine = new Engine($this->server);
        $steps = [['type' => 'wait', 'seconds' => 90], ['type' => 'command', 'command' => 'go', 'continue_on_failure' => false]];

        $first = $engine->advance($steps, null);
        $this->assertSame(90, $first->wakeAt);
        $this->assertSame(['path' => [0], 'state' => ['from' => 0, 'until' => 90]], $first->cursor);

        $this->server->clock = 89;
        $this->assertSame(90, $engine->advance($steps, self::roundTrip($first->cursor))->wakeAt);

        $this->server->clock = 90;
        $last = $engine->advance($steps, self::roundTrip($first->cursor));
        $this->assertSame('success', $last->status);
        $this->assertSame('Waited 1 minute 30 seconds.', $last->log[0]['message']);
    }

    private static function roundTrip(?array $cursor): ?array
    {
        return json_decode(json_encode($cursor, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Runs passes like the queued job does: store the cursor as JSON, move the clock to the wake time, continue.
     *
     * @return list<Outcome>
     */
    private function runToEnd(array $steps): array
    {
        $passes = [];
        $cursor = null;

        for ($i = 0; $i < 100; $i++) {
            $outcome = (new Engine($this->server))->advance($steps, $cursor);
            $passes[] = $outcome;

            if ($outcome->status !== 'running') {
                return $passes;
            }

            $cursor = self::roundTrip($outcome->cursor);
            $this->server->clock = max($this->server->clock, $outcome->wakeAt);
        }

        $this->fail('The run did not finish.');
    }
}

/**
 * A server whose power state follows the signals it receives; a restart takes 30 seconds.
 */
final class FakeServer implements Actions
{
    public int $clock = 0;

    public bool $online = true;

    public ?int $players = 0;

    public ?float $memory = 10.0;

    public int $commandSeconds = 0;

    public int $backupDoneAt = 0;

    /** @var list<array{int, string}> */
    public array $sent = [];

    private ?int $restartedAt = null;

    public function now(): int
    {
        return $this->clock;
    }

    public function command(string $command): void
    {
        if (! $this->online) {
            throw new StepFailed('Server must be online in order to send commands.');
        }

        $this->sent[] = [$this->clock, $command];
        $this->clock += $this->commandSeconds;
    }

    public function power(string $action): void
    {
        $this->sent[] = [$this->clock, "power:{$action}"];
        $this->restartedAt = $action === 'restart' ? $this->clock : null;
        $this->online = $action !== 'stop' && $action !== 'kill';
    }

    public function backup(?string $name, array $ignored): string
    {
        $this->sent[] = [$this->clock, 'backup:'.implode(',', $ignored)];

        return 'b1';
    }

    public function backupStatus(string $backup): string
    {
        return $this->clock >= $this->backupDoneAt ? 'success' : 'running';
    }

    public function usage(): array
    {
        $starting = $this->restartedAt !== null && $this->clock < $this->restartedAt + 30;

        return ['state' => $starting ? 'starting' : ($this->online ? 'running' : 'offline'), 'cpu' => 5.0, 'memory' => $this->memory];
    }

    public function players(): ?int
    {
        return $this->players;
    }

    public function notify(string $kind, string $url, string $message): void
    {
        $this->sent[] = [$this->clock, "{$kind}:{$message}"];
    }
}
