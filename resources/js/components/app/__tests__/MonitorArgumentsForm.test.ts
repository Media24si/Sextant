import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import MonitorArgumentsForm from '@/components/app/MonitorArgumentsForm.vue';
import type { ArgumentOverrides } from '@/lib/monitors';

type Field = App.Data.Monitors.ArgumentSchemaData;

const field = (overrides: Partial<Field>): Field => ({
    type: 'string',
    name: 'path',
    label: 'Path',
    help: null,
    nullable: false,
    options: null,
    default: '/',
    ...overrides,
});

const schema: Field[] = [
    field({ type: 'string', name: 'path', label: 'Path', default: '/' }),
    field({ type: 'int', name: 'timeout', label: 'Timeout', default: 10 }),
    field({ type: 'bool', name: 'verify_tls', label: 'Verify', default: true }),
    field({
        type: 'intList',
        name: 'expected_status',
        label: 'Expected',
        default: [200],
    }),
    field({
        type: 'select',
        name: 'address',
        label: 'Address',
        nullable: true,
        default: null,
        options: { '192.0.2.10': '192.0.2.10' },
    }),
];

function render(model: ArgumentOverrides = {}) {
    const updates: ArgumentOverrides[] = [];
    const wrapper = mount(MonitorArgumentsForm, {
        props: {
            schema,
            modelValue: model,
            'onUpdate:modelValue': (value: ArgumentOverrides) =>
                updates.push(value),
        },
    });

    return { wrapper, updates };
}

describe('MonitorArgumentsForm', () => {
    it('maps each schema type to its field, with the default as placeholder', () => {
        const { wrapper } = render();

        expect(
            wrapper.find('[data-field=path] input').attributes('placeholder'),
        ).toBe('/');
        expect(
            wrapper.find('[data-field=timeout] input').attributes('type'),
        ).toBe('number');
        expect(
            wrapper.find('[data-field=verify_tls] [data-slot=switch]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-field=verify_tls]').text()).toContain(
            'Yes (default)',
        );
        expect(
            wrapper
                .find('[data-field=expected_status] input')
                .attributes('placeholder'),
        ).toBe('200');
        expect(
            wrapper
                .findAll('[data-field=address] option')
                .map((option) => option.text()),
        ).toEqual(['Default (Not set)', '192.0.2.10']);
    });

    it('stores typed overrides and drops emptied fields so they inherit', async () => {
        const { wrapper, updates } = render({ path: '/novice' });

        await wrapper.find('[data-field=timeout] input').setValue('20');
        expect(updates.at(-1)).toEqual({ path: '/novice', timeout: 20 });

        await wrapper.find('[data-field=path] input').setValue('');
        expect(updates.at(-1)).toEqual({});
    });

    it('shows an override and offers the default back', () => {
        const { wrapper } = render({ verify_tls: false, path: '/shop' });

        expect(
            (
                wrapper.find('[data-field=path] input')
                    .element as HTMLInputElement
            ).value,
        ).toBe('/shop');
        expect(wrapper.find('[data-field=verify_tls]').text()).toContain(
            'Use default',
        );
    });
});
