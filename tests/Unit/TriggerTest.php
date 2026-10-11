<?php

declare(strict_types=1);

namespace Automations\Tests\Unit;

use Automations\Support\Trigger;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TriggerTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function invalidTriggers(): iterable
    {
        yield 'no type' => [['timezone' => 'UTC']];
        yield 'unknown zone' => [['type' => 'daily', 'time' => '04:00', 'timezone' => 'Mars/Olympus']];
        yield 'offset instead of zone' => [['type' => 'daily', 'time' => '04:00', 'timezone' => '+02:00']];
        yield 'zero minutes' => [['type' => 'interval', 'every' => 0, 'timezone' => 'UTC']];
        yield 'more than a day' => [['type' => 'interval', 'every' => 1441, 'timezone' => 'UTC']];
        yield 'bad time' => [['type' => 'daily', 'time' => '24:00', 'timezone' => 'UTC']];
        yield 'no days' => [['type' => 'weekly', 'time' => '04:00', 'days' => [], 'timezone' => 'UTC']];
        yield 'day eight' => [['type' => 'weekly', 'time' => '04:00', 'days' => [8], 'timezone' => 'UTC']];
        yield 'not an array' => ['daily'];
    }

    public function test_intervals_follow_the_wall_clock_of_the_time_zone(): void
    {
        $trigger = Trigger::fromArray(['type' => 'interval', 'every' => 15, 'timezone' => 'UTC']);

        $this->assertSame(['10:15', '10:30', '10:45'], self::times($trigger->upcoming(self::utc('2026-06-01 10:07:31'))));
        $this->assertSame(['10:30'], self::times([$trigger->next(self::utc('2026-06-01 10:15:00'))]));

        // Hourly in a +05:30 zone runs at the local full hour, which is half past in UTC.
        $hourly = Trigger::fromArray(['type' => 'interval', 'every' => 60, 'timezone' => 'Asia/Kolkata']);
        $this->assertSame('2026-06-01T10:30:00+00:00', $hourly->next(self::utc('2026-06-01 10:00:00'))->format(DateTimeInterface::ATOM));
    }

    public function test_intervals_restart_at_local_midnight(): void
    {
        $trigger = Trigger::fromArray(['type' => 'interval', 'every' => 420, 'timezone' => 'Europe/Berlin']);

        // 07:00, 14:00, 21:00 and then 00:00 local time (CEST, UTC+2).
        $this->assertSame(['19:00', '22:00', '05:00'], self::times($trigger->upcoming(self::utc('2026-06-01 12:30:00'))));
    }

    public function test_daily_runs_in_the_chosen_time_zone(): void
    {
        $trigger = Trigger::fromArray(['type' => 'daily', 'time' => '04:00', 'timezone' => 'America/New_York']);

        $this->assertSame(
            ['2026-06-01T08:00:00+00:00', '2026-06-02T08:00:00+00:00', '2026-06-03T08:00:00+00:00'],
            self::atoms($trigger->upcoming(self::utc('2026-06-01 07:59:00'))),
        );
        $this->assertSame('2026-06-02T08:00:00+00:00', $trigger->next(self::utc('2026-06-01 08:00:00'))->format(DateTimeInterface::ATOM));
    }

    public function test_weekly_runs_on_the_chosen_days_only(): void
    {
        // 2026-06-03 is a Wednesday.
        $trigger = Trigger::fromArray(['type' => 'weekly', 'time' => '06:30', 'days' => [5, 1, 3], 'timezone' => 'UTC']);

        $this->assertSame(
            ['2026-06-05T06:30:00+00:00', '2026-06-08T06:30:00+00:00', '2026-06-10T06:30:00+00:00'],
            self::atoms($trigger->upcoming(self::utc('2026-06-03 07:00:00'))),
        );
        $this->assertSame([1, 3, 5], $trigger->toArray()['days']);
    }

    public function test_daily_runs_once_on_both_clock_changes(): void
    {
        $trigger = Trigger::fromArray(['type' => 'daily', 'time' => '02:30', 'timezone' => 'Europe/Berlin']);

        // Spring forward on 2026-03-29: 02:30 does not exist, the run moves to 03:30 CEST.
        $this->assertSame(
            ['2026-03-28T01:30:00+00:00', '2026-03-29T01:30:00+00:00', '2026-03-30T00:30:00+00:00'],
            self::atoms($trigger->upcoming(self::utc('2026-03-28 00:00:00'))),
        );

        // Fall back on 2026-10-25: 02:30 happens twice, the run happens once.
        $this->assertSame(
            ['2026-10-24T00:30:00+00:00', '2026-10-25T00:30:00+00:00', '2026-10-26T01:30:00+00:00'],
            self::atoms($trigger->upcoming(self::utc('2026-10-23 23:00:00'))),
        );
    }

    public function test_daily_keeps_the_local_time_across_a_clock_change(): void
    {
        $trigger = Trigger::fromArray(['type' => 'weekly', 'time' => '04:00', 'days' => [7], 'timezone' => 'America/New_York']);

        // US clocks go back on 2026-11-01: the same 04:00 local time is 08:00 UTC before and 09:00 UTC after.
        $this->assertSame(
            ['2026-10-25T08:00:00+00:00', '2026-11-01T09:00:00+00:00'],
            self::atoms($trigger->upcoming(self::utc('2026-10-20 00:00:00'), 2)),
        );
    }

    public function test_intervals_skip_missing_times_and_repeat_the_autumn_hour(): void
    {
        $trigger = Trigger::fromArray(['type' => 'interval', 'every' => 60, 'timezone' => 'Europe/Berlin']);

        // 01:00 CET, then 03:00 CEST: 02:00 does not exist on 2026-03-29.
        $this->assertSame(['00:00', '01:00', '02:00'], self::times($trigger->upcoming(self::utc('2026-03-28 23:30:00'))));

        // 02:00 CEST and 02:00 CET both run on 2026-10-25, an hour apart.
        $this->assertSame(
            ['2026-10-25T00:00:00+00:00', '2026-10-25T01:00:00+00:00', '2026-10-25T02:00:00+00:00'],
            self::atoms($trigger->upcoming(self::utc('2026-10-24 23:30:00'))),
        );
    }

    public function test_summaries_read_like_a_sentence(): void
    {
        $this->assertSame('Every minute', Trigger::fromArray(['type' => 'interval', 'every' => 1, 'timezone' => 'UTC'])->summary());
        $this->assertSame('Every 15 minutes', Trigger::fromArray(['type' => 'interval', 'every' => 15, 'timezone' => 'UTC'])->summary());
        $this->assertSame('Every hour', Trigger::fromArray(['type' => 'interval', 'every' => 60, 'timezone' => 'UTC'])->summary());
        $this->assertSame('Every 6 hours', Trigger::fromArray(['type' => 'interval', 'every' => 360, 'timezone' => 'UTC'])->summary());
        $this->assertSame('Every day at 04:00', Trigger::fromArray(['type' => 'daily', 'time' => '04:00', 'timezone' => 'UTC'])->summary());
        $this->assertSame('Weekdays at 07:15', Trigger::fromArray(['type' => 'weekly', 'time' => '07:15', 'days' => [1, 2, 3, 4, 5], 'timezone' => 'UTC'])->summary());
        $this->assertSame('Weekends at 10:00', Trigger::fromArray(['type' => 'weekly', 'time' => '10:00', 'days' => [6, 7], 'timezone' => 'UTC'])->summary());
        $this->assertSame('Every Sunday at 03:00', Trigger::fromArray(['type' => 'weekly', 'time' => '03:00', 'days' => [7], 'timezone' => 'UTC'])->summary());
        $this->assertSame('Mon, Wed and Fri at 06:30', Trigger::fromArray(['type' => 'weekly', 'time' => '06:30', 'days' => [1, 3, 5], 'timezone' => 'UTC'])->summary());
    }

    #[DataProvider('invalidTriggers')]
    public function test_invalid_triggers_are_refused(mixed $data): void
    {
        $this->expectException(InvalidArgumentException::class);

        Trigger::fromArray($data);
    }

    public function test_it_normalizes_what_it_stores(): void
    {
        $this->assertSame(
            ['type' => 'daily', 'time' => '04:05', 'timezone' => 'Europe/Berlin'],
            Trigger::fromArray(['type' => 'daily', 'time' => '04:05', 'days' => [1], 'every' => 3, 'timezone' => 'Europe/Berlin'])->toArray(),
        );
        $this->assertSame(['type' => 'interval', 'every' => 30, 'timezone' => 'UTC'], Trigger::fromArray(['type' => 'interval', 'every' => '30', 'timezone' => 'UTC'])->toArray());
    }

    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time.' UTC');
    }

    /**
     * @param  list<DateTimeImmutable>  $times
     * @return list<string>
     */
    private static function times(array $times): array
    {
        return array_map(static fn (DateTimeImmutable $time): string => $time->format('H:i'), $times);
    }

    /**
     * @param  list<DateTimeImmutable>  $times
     * @return list<string>
     */
    private static function atoms(array $times): array
    {
        return array_map(static fn (DateTimeImmutable $time): string => $time->format(DateTimeInterface::ATOM), $times);
    }
}
