import { describe, expect, it } from 'vite-plus/test';
import { Duration } from '@/lib/duration';
import { systemHealth } from '@/lib/health';

const now = Date.parse('2026-11-21T10:00:00Z');
const system = (
    overrides: Partial<App.Data.Dashboard.SystemHealthData>,
): App.Data.Dashboard.SystemHealthData => ({
    last_dispatch_at: '2026-11-21T09:59:30Z',
    stale_monitors: 0,
    network_healthy: null,
    ...overrides,
});

describe('systemHealth', () => {
    it.each([
        [null, 'unknown', 'Checks not running yet'],
        [
            system({ last_dispatch_at: null }),
            'unknown',
            'Checks not running yet',
        ],
        [
            system({ last_dispatch_at: '2026-11-21T09:55:00Z' }),
            'error',
            'Checks stopped',
        ],
        [
            system({ network_healthy: false }),
            'warning',
            'Checks running · network down',
        ],
        [system({ stale_monitors: 3 }), 'warning', 'Checks running · 3 stale'],
        [
            system({ network_healthy: true }),
            'ok',
            'Checks running · network OK',
        ],
    ] as const)('%#: %s', (input, status, label) => {
        expect(systemHealth(input, now)).toEqual({ status, label });
    });
});

describe('Duration', () => {
    it('writes short durations', () => {
        expect(
            Duration.between('2026-11-21T10:00:00Z', '2026-11-21T10:00:42Z'),
        ).toBe('42 s');
        expect(
            Duration.between('2026-11-21T10:00:00Z', '2026-11-21T10:15:00Z'),
        ).toBe('15 min');
        expect(
            Duration.between('2026-11-21T10:00:00Z', '2026-11-21T12:05:00Z'),
        ).toBe('2 h 5 min');
        expect(
            Duration.between('2026-11-18T06:00:00Z', '2026-11-21T10:00:00Z'),
        ).toBe('3 d 4 h');
    });
});
