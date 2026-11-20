import { onScopeDispose, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/*
 * One shared clock for every RelativeTime on the page, ticking every
 * 30 seconds while at least one component uses it.
 */
const now = ref(Date.now());
let subscribers = 0;
let timer: ReturnType<typeof setInterval> | null = null;

export function useNow(): Readonly<Ref<number>> {
    subscribers++;

    if (timer === null) {
        now.value = Date.now();
        timer = setInterval(() => {
            now.value = Date.now();
        }, 30_000);
    }

    onScopeDispose(() => {
        subscribers--;

        if (subscribers === 0 && timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    });

    return readonly(now);
}
