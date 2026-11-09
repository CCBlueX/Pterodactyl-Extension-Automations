<?php

declare(strict_types=1);

namespace Automations\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class PauseUntil
{
    /** A pause longer than this is better done by turning the automation off. */
    public const int MAX_DAYS = 366;

    /**
     * The moment a local date and time such as "2026-11-10T08:00" in the given time zone stands for.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(mixed $local, mixed $timezone, DateTimeImmutable $now): DateTimeImmutable
    {
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Choose a valid time zone.');
        }

        $until = is_string($local) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone($timezone)) : false;

        if ($until === false || $until->format('Y-m-d\TH:i') !== $local) {
            throw new InvalidArgumentException('Choose a valid date and time.');
        }

        if ($until <= $now) {
            throw new InvalidArgumentException('Choose a time in the future.');
        }

        if ($until > $now->modify('+'.self::MAX_DAYS.' days')) {
            throw new InvalidArgumentException('Pause for up to a year, or turn the automation off.');
        }

        return $until;
    }

    /** The first scheduled run that is not skipped, which is at or after the end of a pause. */
    public static function firstRun(Trigger $trigger, ?DateTimeInterface $pausedUntil, DateTimeImmutable $now): DateTimeImmutable
    {
        return $pausedUntil !== null && $pausedUntil > $now
            ? $trigger->next(DateTimeImmutable::createFromInterface($pausedUntil)->modify('-1 second'))
            : $trigger->next($now);
    }
}
