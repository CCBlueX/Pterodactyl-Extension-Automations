const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

/** "in 5 minutes", "3 hours ago". */
export function formatAgo(iso: string, now = Date.now()): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['day', 86400],
        ['hour', 3600],
        ['minute', 60],
    ];

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return relative.format(Math.round(seconds / size), unit);
        }
    }

    return relative.format(seconds, 'second');
}

/** A date and time, in the given time zone when there is one. */
export function formatWhen(iso: string, timeZone?: string): string {
    return new Date(iso).toLocaleString(undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone });
}

/** "4:32" until the given moment. */
export function formatLeft(iso: string, now = Date.now()): string {
    const seconds = Math.max(0, Math.round((new Date(iso).getTime() - now) / 1000));
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const rest = String(seconds % 60).padStart(2, '0');

    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${rest}` : `${minutes}:${rest}`;
}

/** "10m", "30s", "1h" for countdown points. */
export const shortDuration = (seconds: number): string => (seconds >= 3600 ? `${seconds / 3600}h` : seconds >= 60 ? `${seconds / 60}m` : `${seconds}s`);

/** "1 hour", "5 minutes", "1 minute 30 seconds", like the announcements. */
export function durationWords(seconds: number): string {
    const parts: string[] = [];

    for (const [unit, size] of [['hour', 3600], ['minute', 60], ['second', 1]] as const) {
        const amount = Math.floor(seconds / size);
        seconds %= size;

        if (amount > 0) {
            parts.push(`${amount} ${unit}${amount === 1 ? '' : 's'}`);
        }
    }

    return parts.length ? parts.join(' ') : '0 seconds';
}

export const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;
