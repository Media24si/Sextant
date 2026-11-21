/**
 * The header pill's status and text from `system` (SPEC §13.4, §13.7),
 * e.g. "Checks running · network OK".
 */
export function systemHealth(
    system: App.Data.Dashboard.SystemHealthData | null | undefined,
): { status: App.Enums.Status; label: string } {
    if (!system?.last_dispatch_at) {
        return { status: 'unknown', label: 'Checks not running yet' };
    }

    // The server decides, against its own clock (DashboardBuilder::system).
    if (!system.checks_running) {
        return { status: 'error', label: 'Checks stopped' };
    }

    if (system.network_healthy === false) {
        return { status: 'warning', label: 'Checks running · network down' };
    }

    if (system.stale_monitors > 0) {
        return {
            status: 'warning',
            label: `Checks running · ${system.stale_monitors} stale`,
        };
    }

    return { status: 'ok', label: 'Checks running · network OK' };
}
