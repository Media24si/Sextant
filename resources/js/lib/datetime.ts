/**
 * Dates come from the server as ISO 8601 in UTC and are shown in
 * Europe/Ljubljana (SPEC §13.1; config('sextant.display_timezone')).
 */
export const displayTimeZone = 'Europe/Ljubljana';

const absoluteFormat = new Intl.DateTimeFormat('en-GB', {
    timeZone: displayTimeZone,
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

const relativeFormat = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });

const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 365 * 24 * 60 * 60],
    ['month', 30 * 24 * 60 * 60],
    ['week', 7 * 24 * 60 * 60],
    ['day', 24 * 60 * 60],
    ['hour', 60 * 60],
    ['minute', 60],
];

/** "20 Nov 2026, 21:24" in Europe/Ljubljana. */
export function formatDateTime(iso: string): string {
    return absoluteFormat.format(new Date(iso));
}

/** "7 minutes ago", "in 3 days", "just now". */
export function formatRelative(iso: string, now: number): string {
    const seconds = Math.round((new Date(iso).getTime() - now) / 1000);

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return relativeFormat.format(Math.trunc(seconds / size), unit);
        }
    }

    return 'just now';
}
