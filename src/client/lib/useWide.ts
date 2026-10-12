import { useSyncExternalStore } from 'react';

// Tailwind's lg breakpoint: below it the automations table squeezes its actions into a column.
// Without matchMedia (tests) the wide layout is used.
const QUERY = '(min-width: 64rem)';

function subscribe(listener: () => void): () => void {
    const media = window.matchMedia?.(QUERY);
    media?.addEventListener('change', listener);

    return () => media?.removeEventListener('change', listener);
}

const isWide = () => window.matchMedia?.(QUERY).matches ?? true;

/** Whether the window is wide enough for the automations table. */
export const useWide = (): boolean => useSyncExternalStore(subscribe, isWide, () => true);
