<?php

declare(strict_types=1);

namespace Automations\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * When an automation runs: every N minutes, daily at a time or on chosen weekdays, in a time zone.
 */
final readonly class Trigger
{
    public const array TYPES = ['interval', 'daily', 'weekly'];

    public const array DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** @param list<int> $days ISO weekdays, 1 = Monday. */
    private function __construct(
        public string $type,
        public int $every,
        public int $hour,
        public int $minute,
        public array $days,
        public string $timezone,
    ) {}

    /** @throws InvalidArgumentException */
    public static function fromArray(mixed $data): self
    {
        if (! is_array($data) || ! in_array($data['type'] ?? null, self::TYPES, true)) {
            throw new InvalidArgumentException('Choose how often the automation runs.');
        }

        $timezone = $data['timezone'] ?? null;

        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Choose a valid time zone.');
        }

        if ($data['type'] === 'interval') {
            $every = filter_var($data['every'] ?? null, FILTER_VALIDATE_INT);

            if ($every === false || $every < 1 || $every > 1440) {
                throw new InvalidArgumentException('Run every 1 to 1440 minutes.');
            }

            return new self('interval', $every, 0, 0, [], $timezone);
        }

        if (! is_string($data['time'] ?? null) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $data['time'], $time) !== 1) {
            throw new InvalidArgumentException('Enter the time as HH:MM.');
        }

        $days = [];

        if ($data['type'] === 'weekly') {
            foreach (is_array($data['days'] ?? null) ? $data['days'] : [] as $day) {
                $day = filter_var($day, FILTER_VALIDATE_INT);

                if ($day === false || ! isset(self::DAYS[$day])) {
                    throw new InvalidArgumentException('Choose weekdays from Monday to Sunday.');
                }

                $days[$day] = $day;
            }

            if ($days === []) {
                throw new InvalidArgumentException('Choose at least one weekday.');
            }

            ksort($days);
        }

        return new self($data['type'], 0, (int) $time[1], (int) $time[2], array_values($days), $timezone);
    }

    /** @return array{type: string, timezone: string, every?: int, time?: string, days?: list<int>} */
    public function toArray(): array
    {
        return match ($this->type) {
            'interval' => ['type' => 'interval', 'every' => $this->every, 'timezone' => $this->timezone],
            'daily' => ['type' => 'daily', 'time' => $this->time(), 'timezone' => $this->timezone],
            default => ['type' => 'weekly', 'time' => $this->time(), 'days' => $this->days, 'timezone' => $this->timezone],
        };
    }

    /** The first run strictly after the given moment, in UTC. */
    public function next(DateTimeInterface $after): DateTimeImmutable
    {
        $after = DateTimeImmutable::createFromInterface($after);
        $next = $this->type === 'interval' ? $this->nextInterval($after) : $this->nextDay($after);

        return $next->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return list<DateTimeImmutable> */
    public function upcoming(DateTimeInterface $after, int $count = 3): array
    {
        $runs = [];

        for ($i = 0; $i < $count; $i++) {
            $after = $runs[] = $this->next($after);
        }

        return $runs;
    }

    public function summary(): string
    {
        if ($this->type === 'interval') {
            return match (true) {
                $this->every === 1 => 'Every minute',
                $this->every === 60 => 'Every hour',
                $this->every % 60 === 0 => sprintf('Every %d hours', $this->every / 60),
                default => "Every {$this->every} minutes",
            };
        }

        $at = 'at '.$this->time();

        if ($this->type === 'daily' || count($this->days) === 7) {
            return "Every day {$at}";
        }

        return match ($this->days) {
            [1, 2, 3, 4, 5] => "Weekdays {$at}",
            [6, 7] => "Weekends {$at}",
            default => count($this->days) === 1
                ? 'Every '.self::DAYS[$this->days[0]]." {$at}"
                : self::list(array_map(static fn (int $day): string => mb_substr(self::DAYS[$day], 0, 3), $this->days))." {$at}",
        };
    }

    /** @param list<string> $items */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return implode(', ', $items)." and {$last}";
    }

    private function time(): string
    {
        return sprintf('%02d:%02d', $this->hour, $this->minute);
    }

    /** Wall-clock minutes of the day that are a multiple of the interval; repeated hours in autumn run again. */
    private function nextInterval(DateTimeImmutable $after): DateTimeImmutable
    {
        $zone = new DateTimeZone($this->timezone);
        $timestamp = intdiv($after->getTimestamp(), 60) * 60 + 60;

        while (true) {
            $local = (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone);

            if (((int) $local->format('G') * 60 + (int) $local->format('i')) % $this->every === 0) {
                return $local;
            }

            $timestamp += 60;
        }
    }

    /** One run per matching day; a time skipped by a clock change runs once the clock has moved on. */
    private function nextDay(DateTimeImmutable $after): DateTimeImmutable
    {
        $zone = new DateTimeZone($this->timezone);
        $midnight = new DateTimeImmutable($after->setTimezone($zone)->format('Y-m-d').' 00:00:00', $zone);

        for ($day = 0; ; $day++) {
            $date = $midnight->modify("+{$day} days");

            if ($this->type === 'weekly' && ! in_array((int) $date->format('N'), $this->days, true)) {
                continue;
            }

            $candidate = $date->setTime($this->hour, $this->minute);

            if ($candidate > $after) {
                return $candidate;
            }
        }
    }
}
