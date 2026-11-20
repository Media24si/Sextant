import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import StatusBadge from '@/components/app/StatusBadge.vue';

describe('StatusBadge', () => {
    it.each([
        ['ok', 'OK', 'bg-status-ok-bg', 'bg-status-ok'],
        ['warning', 'Warning', 'bg-status-warning-bg', 'bg-status-warning'],
        ['error', 'Error', 'bg-status-error-bg', 'bg-status-error'],
        ['unknown', 'Unknown', 'bg-status-unknown-bg', 'bg-status-unknown'],
    ] as const)(
        'shows %s as "%s" in its colours',
        (status, label, tone, dot) => {
            const wrapper = mount(StatusBadge, { props: { status } });

            expect(wrapper.text()).toBe(label);
            expect(wrapper.classes()).toContain(tone);
            expect(wrapper.find('[aria-hidden="true"]').classes()).toContain(
                dot,
            );
        },
    );
});
