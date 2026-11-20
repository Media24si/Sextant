import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import GlobalSearch from '@/components/app/GlobalSearch.vue';

const routerVisit = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { visit: routerVisit },
}));

const results: App.Data.SearchResultData[] = [
    {
        type: 'server',
        id: 2,
        label: 'web02',
        context: '192.0.2.12',
        status: 'ok',
        url: '/servers/2',
    },
    {
        type: 'domain',
        id: 3,
        label: 'svet24.si',
        context: '5 sites',
        status: 'warning',
        url: '/domains/3',
    },
    {
        type: 'site',
        id: 4,
        label: 'svet24.si',
        context: 'News portal',
        status: 'ok',
        url: '/sites/4',
    },
];

const fetchMock = vi.fn(async (url: string) => ({
    url,
    json: async () => ({ results }),
}));

async function openAndType(wrapper: ReturnType<typeof mount>, text: string) {
    window.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }),
    );
    await flushPromises();

    const input = document.querySelector<HTMLInputElement>(
        '[data-test="global-search-input"]',
    );
    expect(input).not.toBeNull();
    input!.value = text;
    input!.dispatchEvent(new Event('input'));
    await vi.advanceTimersByTimeAsync(250);
    await flushPromises();

    return input!;
}

describe('GlobalSearch', () => {
    let wrapper: ReturnType<typeof mount>;

    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockClear();
        routerVisit.mockClear();
        wrapper = mount(GlobalSearch, { attachTo: document.body });
    });

    afterEach(() => {
        wrapper.unmount();
        vi.unstubAllGlobals();
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('opens with Ctrl+K and searches after 250 ms', async () => {
        await openAndType(wrapper, 'svet');

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(fetchMock.mock.calls[0][0]).toBe('/search?q=svet');
        expect(document.querySelectorAll('[role="option"]')).toHaveLength(3);
        expect(
            [...document.querySelectorAll('[role="group"] > p')].map((p) =>
                p.textContent?.trim(),
            ),
        ).toEqual(['Servers', 'Domains', 'Sites']);
    });

    it('does not search fewer than 2 characters', async () => {
        await openAndType(wrapper, 's');

        expect(fetchMock).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain(
            'Type at least 2 characters.',
        );
    });

    it('opens the first result on Enter', async () => {
        const input = await openAndType(wrapper, 'svet');

        input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));

        expect(routerVisit).toHaveBeenCalledWith('/servers/2');
    });

    it('moves with the arrow keys, wrapping around, and opens the highlighted result', async () => {
        const input = await openAndType(wrapper, 'svet');
        const press = async (key: string) => {
            input.dispatchEvent(new KeyboardEvent('keydown', { key }));
            await flushPromises();
        };

        await press('ArrowDown');
        expect(input.getAttribute('aria-activedescendant')).toBe(
            'global-search-option-1',
        );

        await press('ArrowUp');
        await press('ArrowUp');
        expect(input.getAttribute('aria-activedescendant')).toBe(
            'global-search-option-2',
        );
        expect(
            document.querySelector('[aria-selected="true"]')?.textContent,
        ).toContain('News portal');

        await press('Enter');
        expect(routerVisit).toHaveBeenCalledWith('/sites/4');
    });
});
