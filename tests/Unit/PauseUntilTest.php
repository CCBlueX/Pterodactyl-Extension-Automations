<?php

declare(strict_types=1);

namespace Automations\Tests\Unit;

use Automations\Support\PauseUntil;
use Automations\Support\Trigger;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PauseUntilTest extends TestCase
{
    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidPauses(): iterable
    {
        yield 'in the past' => ['2026-06-01T09:00', 'UTC'];
        yield 'right now' => ['2026-06-01T10:00', 'UTC'];
        yield 'past once the zone is applied' => ['2026-06-01T11:30', 'Europe/Berlin'];
        yield 'more than a year' => ['2027-06-03T10:00', 'UTC'];
        yield 'not a date' => ['tomorrow', 'UTC'];
        yield 'impossible day' => ['2026-02-30T10:00', 'UTC'];
        yield 'with seconds' => ['2026-06-02T10:00:00', 'UTC'];
        yield 'not a string' => [123, 'UTC'];
        yield 'unknown zone' => ['2026-06-02T10:00', 'Mars/Olympus'];
        yield 'offset instead of zone' => ['2026-06-02T10:00', '+02:00'];
    }

    public function test_the_time_is_read_in_the_chosen_time_zone(): void
    {
        $this->assertSame('2026-06-02T06:00:00+00:00', self::utc(PauseUntil::parse('2026-06-02T08:00', 'Europe/Berlin', self::now())));
        $this->assertSame('2026-06-01T10:01:00+00:00', self::utc(PauseUntil::parse('2026-06-01T10:01', 'UTC', self::now())));
        $this->assertSame('2026-06-01T17:00:00+00:00', self::utc(PauseUntil::parse('2026-06-01T13:00', 'America/New_York', self::now())));
    }

    public function test_a_year_ahead_is_allowed(): void
    {
        $this->assertSame('2027-06-02T10:00:00+00:00', self::utc(PauseUntil::parse('2027-06-02T10:00', 'UTC', self::now())));
    }

    public function test_the_first_run_is_the_first_trigger_time_after_the_pause(): void
    {
        $daily = Trigger::fromArray(['type' => 'daily', 'time' => '03:00', 'timezone' => 'UTC']);

        $this->assertSame('2026-06-02T03:00:00+00:00', self::utc(PauseUntil::firstRun($daily, null, self::now())));
        $this->assertSame('2026-06-05T03:00:00+00:00', self::utc(PauseUntil::firstRun($daily, new DateTimeImmutable('2026-06-04 12:00:00 UTC'), self::now())));
        // A run exactly at the end of the pause is not skipped.
        $this->assertSame('2026-06-04T03:00:00+00:00', self::utc(PauseUntil::firstRun($daily, new DateTimeImmutable('2026-06-04 03:00:00 UTC'), self::now())));
        // A pause that has ended changes nothing.
        $this->assertSame('2026-06-02T03:00:00+00:00', self::utc(PauseUntil::firstRun($daily, new DateTimeImmutable('2026-05-01 00:00:00 UTC'), self::now())));
    }

    #[DataProvider('invalidPauses')]
    public function test_invalid_pauses_are_refused(mixed $local, mixed $timezone): void
    {
        $this->expectException(InvalidArgumentException::class);

        PauseUntil::parse($local, $timezone, self::now());
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-01 10:00:00 UTC');
    }

    private static function utc(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }
}
