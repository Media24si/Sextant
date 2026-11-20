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
