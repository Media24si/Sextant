/**
 * Labels and colour classes for the four statuses (GOAL "Status model",
 * SPEC §13.1). Tailwind needs the full class names written out.
 */
export const statusLabels: Record<App.Enums.Status, string> = {
    ok: 'OK',
    warning: 'Warning',
    error: 'Error',
    unknown: 'Unknown',
};

/** Solid colour, for dots. */
export const statusDotClasses: Record<App.Enums.Status, string> = {
    ok: 'bg-status-ok',
    warning: 'bg-status-warning',
    error: 'bg-status-error',
    unknown: 'bg-status-unknown',
};

/** Text on a tinted background, for badges and pills. */
export const statusToneClasses: Record<App.Enums.Status, string> = {
    ok: 'bg-status-ok-bg text-status-ok-fg',
    warning: 'bg-status-warning-bg text-status-warning-fg',
    error: 'bg-status-error-bg text-status-error-fg',
    unknown: 'bg-status-unknown-bg text-status-unknown-fg',
};

/** Incident severity (SPEC §3.11): only warning and error. */
export type Severity = Extract<App.Enums.Status, 'warning' | 'error'>;
