/** "every 1 min", "every 15 min", "hourly", "every 12 h", "daily". */
export function everyLabel(minutes: number): string {
    if (minutes === 60) {
        return 'hourly';
    }

    if (minutes === 1440) {
        return 'daily';
    }

    if (minutes > 60 && minutes % 60 === 0) {
        return `every ${minutes / 60} h`;
    }

    return `every ${minutes} min`;
}

/**
 * The dot a monitor shows: its raw last result while Pending (SPEC §6.1),
 * otherwise its effective status.
 */
export function monitorDotStatus(
    monitor: App.Data.Monitors.MonitorRowData,
): App.Enums.Status {
    return monitor.pending && monitor.last_result_status
        ? monitor.last_result_status
        : monitor.status;
}

/** A monitor's argument overrides (SPEC §4.5): only keys set on the monitor. */
export type ArgumentOverrides = Record<
    string,
    string | number | boolean | (string | number)[]
>;
