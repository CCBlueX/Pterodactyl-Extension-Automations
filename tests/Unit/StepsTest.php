<?php

declare(strict_types=1);

namespace Automations\Tests\Unit;

use Automations\Support\Steps;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StepsTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function invalidTrees(): iterable
    {
        yield 'empty' => [[], 'Add at least one step.'];
        yield 'not a list' => [['a' => ['type' => 'wait', 'seconds' => 1]], 'Steps must be a list.'];
        yield 'unknown type' => [[['type' => 'reboot']], 'Step 1: Choose what the step does.'];
        yield 'empty command' => [[['type' => 'command', 'command' => '  ']], 'Step 1: Enter a command.'];
        yield 'two commands in one' => [[['type' => 'command', 'command' => "stop\nstart"]], 'Step 1: Enter a command.'];
        yield 'unknown power' => [[['type' => 'power', 'action' => 'reboot']], 'Step 1: Choose a power action.'];
        yield 'long wait' => [[['type' => 'wait', 'seconds' => 86401]], 'Step 1: Wait between 1 second and 24 hours.'];
        yield 'wait for starting' => [[['type' => 'wait_for', 'state' => 'starting', 'timeout' => 5]], 'Step 1: Choose the state to wait for.'];
        yield 'odd countdown point' => [[['type' => 'countdown', 'points' => [42], 'message' => 'say {TIME}']], 'Step 1: Choose announcement times from the list.'];
        yield 'players without minecraft' => [[['type' => 'if', 'condition' => ['kind' => 'players', 'op' => 'above', 'value' => 1]]], 'Step 1: Player counts are only available for Minecraft servers.'];
        yield 'memory above 100' => [[['type' => 'if', 'condition' => ['kind' => 'memory', 'op' => 'above', 'value' => 120]]], 'Step 1: Enter a memory usage from 0 to 100%.'];
        yield 'nested error' => [
            [['type' => 'wait', 'seconds' => 5], ['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running'], 'then' => [], 'else' => [['type' => 'wait', 'seconds' => 1], ['type' => 'command']]]],
            'Step 2 › otherwise 2: Enter a command.',
        ];
        yield 'too deep' => [
            [['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running'], 'then' => [
                ['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running'], 'then' => [
                    ['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running']],
                ]],
            ]]],
            'Step 1 › then 1 › then 1: If/else can be nested up to 2 levels deep.',
        ];
        yield 'too many' => [array_fill(0, 51, ['type' => 'wait', 'seconds' => 1]), 'An automation can have up to 50 steps.'];
        yield 'not discord' => [[['type' => 'webhook', 'kind' => 'discord', 'url' => 'https://example.com/hook', 'message' => 'hi']], 'Step 1: Enter a Discord webhook URL (https://discord.com/api/webhooks/...).'];
        yield 'not a url' => [[['type' => 'webhook', 'kind' => 'webhook', 'url' => 'example', 'message' => 'hi']], 'Step 1: Enter a valid webhook URL.'];
    }

    public function test_it_normalizes_a_full_tree(): void
    {
        $steps = Steps::normalize([
            ['type' => 'countdown', 'points' => [60, 600, '300', 60], 'message' => '  say Restarting in {TIME}  ', 'extra' => 'dropped'],
            ['type' => 'if', 'condition' => ['kind' => 'players', 'op' => 'below', 'value' => '1'], 'then' => [
                ['type' => 'power', 'action' => 'restart', 'continue_on_failure' => true],
            ], 'else' => [
                ['type' => 'webhook', 'kind' => 'discord', 'url' => 'https://discord.com/api/webhooks/123/abc-DEF', 'message' => 'Players are online, skipped the restart.'],
            ]],
            ['type' => 'backup', 'name' => '', 'ignored' => "logs\r\n\n  cache/*  \n", 'wait' => 1],
            ['type' => 'wait', 'seconds' => 90],
            ['type' => 'wait_for', 'state' => 'running', 'timeout' => 5],
            ['type' => 'command', 'command' => 'save-all'],
        ], true, static fn (): ?string => null);

        $this->assertSame(['type' => 'countdown', 'points' => [600, 300, 60], 'message' => 'say Restarting in {TIME}'], $steps[0]);
        $this->assertSame(['kind' => 'players', 'op' => 'below', 'value' => 1], $steps[1]['condition']);
        $this->assertSame([['type' => 'power', 'action' => 'restart', 'continue_on_failure' => true]], $steps[1]['then']);
        $this->assertSame('discord', $steps[1]['else'][0]['kind']);
        $this->assertSame(['type' => 'backup', 'name' => null, 'ignored' => "logs\ncache/*", 'wait' => true, 'continue_on_failure' => false], $steps[2]);
        $this->assertSame(8, Steps::count($steps));
        $this->assertEqualsCanonicalizing(['control.console', 'control.restart', 'backup.create'], Steps::permissions($steps));
    }

    #[DataProvider('invalidTrees')]
    public function test_invalid_trees_are_refused_with_the_position(mixed $steps, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Steps::normalize($steps, false, static fn (): ?string => null);
    }

    public function test_webhook_targets_are_checked(): void
    {
        $this->expectExceptionMessage('Step 1: 10.0.0.1 points to a private address.');

        Steps::normalize([['type' => 'webhook', 'kind' => 'webhook', 'url' => 'https://10.0.0.1/hook', 'message' => 'hi']], false, static fn (string $url): string => parse_url($url, PHP_URL_HOST).' points to a private address.');
    }

    public function test_it_walks_into_and_out_of_branches(): void
    {
        $steps = [
            ['type' => 'wait', 'seconds' => 1],
            ['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running'], 'then' => [
                ['type' => 'command', 'command' => 'a'],
                ['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running'], 'then' => [['type' => 'command', 'command' => 'b']], 'else' => []],
            ], 'else' => []],
            ['type' => 'command', 'command' => 'c'],
        ];

        $this->assertSame([0], Steps::first($steps));
        $this->assertSame([1], Steps::next($steps, [0]));
        $this->assertSame([1, 'then', 0], Steps::enter($steps, [1], 'then'));
        $this->assertSame([2], Steps::enter($steps, [1], 'else'));
        $this->assertSame([1, 'then', 1], Steps::next($steps, [1, 'then', 0]));
        $this->assertSame([1, 'then', 1, 'then', 0], Steps::enter($steps, [1, 'then', 1], 'then'));
        $this->assertSame([2], Steps::next($steps, [1, 'then', 1, 'then', 0]));
        $this->assertNull(Steps::next($steps, [2]));
        $this->assertSame('b', Steps::at($steps, [1, 'then', 1, 'then', 0])['command']);
        $this->assertNull(Steps::at($steps, [1, 'else', 0]));
        $this->assertNull(Steps::first([]));
        $this->assertSame('2 › then 2 › then 1', Steps::position([1, 'then', 1, 'then', 0]));
        $this->assertSame('If / else', Steps::label($steps[1]));
    }

    public function test_webhook_urls_can_be_hidden(): void
    {
        $webhook = ['type' => 'webhook', 'kind' => 'discord', 'url' => 'https://discord.com/api/webhooks/1/secret', 'message' => 'hi', 'continue_on_failure' => false];
        $steps = Steps::redact([$webhook, ['type' => 'if', 'condition' => ['kind' => 'state', 'state' => 'running'], 'then' => [], 'else' => [$webhook]]]);

        $this->assertSame('', $steps[0]['url']);
        $this->assertSame('', $steps[1]['else'][0]['url']);
        $this->assertSame(['kind' => 'state', 'state' => 'running'], $steps[1]['condition']);
        $this->assertSame('hi', $steps[0]['message']);
    }
}
