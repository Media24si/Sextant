import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vite-plus/test';
import ConfidenceBadge from '@/components/app/ConfidenceBadge.vue';

describe('ConfidenceBadge', () => {
    it.each([
        ['detected', 'Detected', 'bg-status-ok-bg'],
        ['suggested', 'Suggested · confirm', 'bg-status-warning-bg'],
        ['not_found', 'Not found', 'bg-status-unknown-bg'],
    ] as const)('shows %s as "%s"', (confidence, label, tone) => {
        const wrapper = mount(ConfidenceBadge, { props: { confidence } });

        expect(wrapper.text()).toBe(label);
        expect(wrapper.classes()).toContain(tone);
    });

    it('shows a finding the admin changed as "Chosen by you"', () => {
        const wrapper = mount(ConfidenceBadge, {
            props: { confidence: 'suggested', chosen: true },
        });

        expect(wrapper.text()).toBe('Chosen by you');
        expect(wrapper.classes()).toContain('bg-status-ok-bg');
    });
});
