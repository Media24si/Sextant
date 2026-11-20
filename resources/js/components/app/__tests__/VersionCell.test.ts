import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import VersionCell from '@/components/app/VersionCell.vue';

describe('VersionCell', () => {
    it('shows the version in the mono font', () => {
        const wrapper = mount(VersionCell, { props: { version: '1.26.2' } });

        expect(wrapper.text()).toBe('1.26.2');
        expect(wrapper.classes()).toContain('font-mono');
        expect(wrapper.classes()).not.toContain('font-semibold');
        expect(wrapper.attributes('title')).toBeUndefined();
    });

    it('is orange and bold below the minimum, with the package version on hover', () => {
        const wrapper = mount(VersionCell, {
            props: {
                version: '3.0.13',
                packageVersion: '3.0.13-0ubuntu3.4',
                belowMinimum: true,
            },
        });

        expect(wrapper.classes()).toEqual(
            expect.arrayContaining(['font-semibold', 'text-orange-700']),
        );
        expect(wrapper.attributes('title')).toBe(
            'Package 3.0.13-0ubuntu3.4 · Below the minimum version',
        );
        expect(wrapper.find('.sr-only').text()).toBe('(below minimum)');
    });

    it('shows a placeholder when there is no version', () => {
        expect(mount(VersionCell, { props: { version: null } }).text()).toBe(
            '—',
        );
        expect(
            mount(VersionCell, {
                props: { version: null, placeholder: 'not configured' },
            }).text(),
        ).toBe('not configured');
    });
});
