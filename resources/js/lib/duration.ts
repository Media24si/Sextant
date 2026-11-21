/** Short durations, as Sextant writes them in messages: "45 s", "15 min", "2 h 5 min", "3 d 4 h". */
function format(ms: number): string {
    const seconds = Math.max(0, Math.round(ms / 1000));

    if (seconds < 60) {
        return `${seconds} s`;
    }

    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (days > 0) {
        return hours % 24 > 0 ? `${days} d ${hours % 24} h` : `${days} d`;
    }

    if (hours > 0) {
        return minutes % 60 > 0
            ? `${hours} h ${minutes % 60} min`
            : `${hours} h`;
    }

    return `${minutes} min`;
}

export const Duration = {
    between: (from: string, to: string): string =>
        format(new Date(to).getTime() - new Date(from).getTime()),
    since: (from: string, now: number = Date.now()): string =>
        format(now - new Date(from).getTime()),
};
