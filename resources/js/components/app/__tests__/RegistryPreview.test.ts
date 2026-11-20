import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import RegistryPreview from '@/components/app/RegistryPreview.vue';
import { monitorArgumentsNote } from '@/lib/labels';

const registry: App.Data.Detection.RegistryPreviewData = {
    source: 'rdap',
    server: 'rdap.register.si',
    found: true,
    registrar: 'Arnes',
    expires_at: '2027-07-18',
    nameservers: ['dns1.media24.si', 'dns2.media24.si'],
    statuses: ['active'],
    error: null,
};

describe('RegistryPreview', () => {
    it('shows source, registrar, expiry and nameservers', () => {
        const text = mount(RegistryPreview, { props: { registry } }).text();

        expect(text).toContain('RDAP · rdap.register.si');
        expect(text).toContain('Arnes');
        expect(text).toContain('18 Jul 2027');
        expect(text).toContain('dns1.media24.si, dns2.media24.si');
    });

    it('says when the registry has no record', () => {
        const text = mount(RegistryPreview, {
            props: { registry: { ...registry, found: false } },
        }).text();

        expect(text).toContain('The registry has no record of this domain');
    });

    it('says when the registry could not be asked', () => {
        const text = mount(RegistryPreview, {
            props: {
                registry: { ...registry, error: 'RDAP rate limit reached' },
            },
        }).text();

        expect(text).toContain("couldn't be asked: RDAP rate limit reached");
    });
});

describe('monitorArgumentsNote', () => {
    it('describes the overridden arguments of a proposed check', () => {
        expect(
            monitorArgumentsNote({
                checker: 'site.redirect',
                label: null,
                arguments: {
                    path: '/novice',
                    expected_target: 'https://www.svet24.si/',
                },
            }),
        ).toBe('path /novice, expects https://www.svet24.si/');
        expect(
            monitorArgumentsNote({
                checker: 'site.http',
                label: null,
                arguments: {},
            }),
        ).toBeNull();
    });
});
