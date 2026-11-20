/** Display labels for enums shared by several pages. */
export const externalServiceKindLabels: Record<
    App.Enums.ExternalServiceKind,
    string
> = {
    cdn: 'CDN',
    proxy: 'Proxy',
    other: 'Other',
};

export const rangeSourceLabels: Record<App.Enums.RangeSource, string> = {
    cloudflare: 'Cloudflare',
    fastly: 'Fastly',
    cloudfront: 'CloudFront',
};

/** The default checks detection proposes (SPEC §4.3, §4.4), as the prototype names them. */
export const checkerLabels: Record<string, string> = {
    'site.http': 'HTTP · every minute',
    'site.ssl': 'SSL certificate · every 12 h',
    'site.path': 'Serving path matches DNS · every 15 min',
    'site.redirect': 'Redirect · hourly',
    'domain.expiration': 'Expiration · daily · warn at 30, error at 7 days',
    'domain.nameservers': 'Nameservers · hourly',
};

/** A short note on what a proposed check overrides, e.g. "path /novice". */
export function monitorArgumentsNote(
    monitor: App.Data.Detection.ProposedMonitorData,
): string | null {
    const notes = Object.entries(monitor.arguments).map(([key, value]) =>
        key === 'expected_target'
            ? `expects ${String(value)}`
            : `${key.replace('_', ' ')} ${String(value)}`,
    );

    return notes.length > 0 ? notes.join(', ') : null;
}
