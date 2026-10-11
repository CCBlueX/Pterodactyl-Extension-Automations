import type { Condition, Step, StepType } from '../api';

export const MAX_DEPTH = 3;

export const STEP_TYPES: { type: StepType; label: string; icon: string }[] = [
    { type: 'command', label: 'Send command', icon: 'square-terminal' },
    { type: 'power', label: 'Power action', icon: 'power' },
    { type: 'backup', label: 'Create backup', icon: 'archive' },
    { type: 'wait', label: 'Wait', icon: 'hourglass' },
    { type: 'wait_for', label: 'Wait for state', icon: 'timer' },
    { type: 'countdown', label: 'Countdown', icon: 'alarm-clock' },
    { type: 'if', label: 'If / else', icon: 'split' },
    { type: 'webhook', label: 'Notify', icon: 'webhook' },
];

export const COUNTDOWN_POINTS = [3600, 1800, 900, 600, 300, 180, 120, 60, 30, 15, 10, 5, 3, 2, 1];

export const stepType = (type: StepType) => STEP_TYPES.find((entry) => entry.type === type) ?? STEP_TYPES[0];

export function newStep(type: StepType): Step {
    switch (type) {
        case 'command':
            return { type, command: '', continue_on_failure: false };
        case 'power':
            return { type, action: 'restart', continue_on_failure: false };
        case 'backup':
            return { type, name: null, ignored: '', wait: false, continue_on_failure: false };
        case 'wait':
            return { type, seconds: 60 };
        case 'wait_for':
            return { type, state: 'running', timeout: 5 };
        case 'countdown':
            return { type, points: [600, 300, 60, 30, 10], message: 'say Restarting in {TIME}' };
        case 'if':
            return { type, condition: { kind: 'state', state: 'running' }, then: [], else: [] };
        case 'webhook':
            return { type, kind: 'discord', url: '', message: '{AUTOMATION} ran on {SERVER}.', continue_on_failure: false };
    }
}

/** The condition kinds offered; player counts need a Minecraft egg. */
export function conditionKinds(players: boolean): { value: Condition['kind']; label: string }[] {
    return [
        { value: 'state', label: 'Server state is' },
        { value: 'cpu', label: 'CPU usage' },
        { value: 'memory', label: 'Memory usage' },
        ...(players ? [{ value: 'players' as const, label: 'Players online' }] : []),
    ];
}

export function newCondition(kind: Condition['kind']): Condition {
    if (kind === 'state') {
        return { kind, state: 'running' };
    }

    return { kind, op: kind === 'players' ? 'below' : 'above', value: kind === 'players' ? 1 : 90 };
}

export const move = <T>(items: T[], index: number, by: -1 | 1): T[] => {
    const target = index + by;

    if (target < 0 || target >= items.length) {
        return items;
    }

    const copy = [...items];
    [copy[index], copy[target]] = [copy[target], copy[index]];

    return copy;
};

export const replaceAt = <T>(items: T[], index: number, item: T): T[] => items.map((current, i) => (i === index ? item : current));

export const removeAt = <T>(items: T[], index: number): T[] => items.filter((_, i) => i !== index);

/** Every step in the tree, the way the panel counts them. */
export const countSteps = (steps: Step[]): number => steps.reduce((sum, step) => sum + 1 + (step.type === 'if' ? countSteps(step.then) + countSteps(step.else) : 0), 0);
