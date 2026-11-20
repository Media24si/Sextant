import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import ServingPath from '@/components/app/ServingPath.vue';

const visibleText = (wrapper: ReturnType<typeof mount>) =>
    wrapper
        .findAll('[aria-hidden="true"], a')
        .map((node) => node.text())
        .join(' ');

describe('ServingPath', () => {
    it('shows host, the front service dashed, and the backend', () => {
        const wrapper = mount(ServingPath, {
            props: {
                hostname: 'svet24.si',
                path: { front: 'Varnish', backend: 'web02' },
            },
        });

        expect(visibleText(wrapper)).toBe('svet24.si → Varnish → web02');
        expect(wrapper.find('.border-dashed').text()).toBe('Varnish');
        expect(wrapper.find('.sr-only').text()).toBe(
            'Serving path of svet24.si: through Varnish to web02',
        );
    });

    it('goes straight to the backend when nothing is in front', () => {
        const wrapper = mount(ServingPath, {
            props: { path: { front: null, backend: 'web02' } },
        });

        expect(visibleText(wrapper)).toBe('web02');
        expect(wrapper.find('.sr-only').text()).toBe(
            'Serving path: directly to web02',
        );
    });

    it('marks a missing backend as not assigned', () => {
        const wrapper = mount(ServingPath, {
            props: { path: { front: 'Cloudflare', backend: null } },
        });

        expect(visibleText(wrapper)).toBe('Cloudflare → backend not assigned');
        expect(wrapper.findAll('.border-dashed')).toHaveLength(2);
    });

    it('links the backend when given its page', () => {
        const wrapper = mount(ServingPath, {
            props: {
                path: { front: null, backend: 'web02' },
                backendHref: '/servers/2',
            },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });

        expect(wrapper.find('a').text()).toBe('web02');
    });
});
