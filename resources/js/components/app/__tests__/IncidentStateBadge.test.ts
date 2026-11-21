import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import IncidentStateBadge from '@/components/app/IncidentStateBadge.vue';

const incident = (
    overrides: Partial<App.Data.Incidents.IncidentRowData>,
): App.Data.Incidents.IncidentRowData => ({
    id: 1,
    title: 'svet24.si — HTTP',
    severity: 'error',
    state: 'open',
    message: '500',
    resource: { type: 'site', id: 1, name: 'svet24.si' },
    checker: 'site.http',
    opened_at: '2026-11-21T08:00:00Z',
    resolved_at: null,
    resolution: null,
    acknowledged_by: null,
    parent_id: null,
    children_count: 0,
    recovering: false,
    muted: false,
    ...overrides,
});

describe('IncidentStateBadge', () => {
    it('shows the severity and markers of an open incident', () => {
        const text = mount(IncidentStateBadge, {
            props: {
                incident: incident({
                    state: 'acknowledged',
                    recovering: true,
                    muted: true,
                }),
            },
        }).text();

        expect(text).toContain('error');
        expect(text).toContain('Acknowledged');
        expect(text).toContain('Recovering');
        expect(text).toContain('Muted');
    });

    it('shows only "Resolved" once it is over', () => {
        const text = mount(IncidentStateBadge, {
            props: {
                incident: incident({ state: 'resolved', recovering: true }),
            },
        }).text();

        expect(text).toBe('Resolved');
    });
});
