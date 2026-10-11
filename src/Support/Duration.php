<?php

declare(strict_types=1);

namespace Automations\Support;

final class Duration
{
    /** "1 hour", "5 minutes" or "1 minute 30 seconds". */
    public static function words(int $seconds): string
    {
        $parts = [];

        foreach (['hour' => 3600, 'minute' => 60, 'second' => 1] as $unit => $size) {
            $amount = intdiv($seconds, $size);
            $seconds %= $size;

            if ($amount > 0) {
                $parts[] = $amount.' '.$unit.($amount === 1 ? '' : 's');
            }
        }

        return $parts === [] ? '0 seconds' : implode(' ', $parts);
    }
}
