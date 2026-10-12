import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import SchedulesBanner from '../components/SchedulesBanner';
import AutomationsScreen from './AutomationsScreen';

const { navigate } = vi.hoisted(() => ({ navigate: vi.fn() }));

// The test host does not register extension screens, so navigation is checked by destination.
vi.mock('@pterodactyl/sdk', async (importOriginal) => ({ ...(await importOriginal<typeof import('@pterodactyl/sdk')>()), usePanelNavigate: () => navigate }));

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getAutomations: vi.fn(),
    runAutomation: vi.fn(),
    getRuns: vi.fn(),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
    vi.unstubAllGlobals();
});

/** Pretends the window is narrower than Tailwind's lg breakpoint. */
const narrow = () => vi.stubGlobal('matchMedia', (media: string) => ({ matches: false, media, addEventListener: () => {}, removeEventListener: () => {} }));

const run = (changes: Partial<api.RunSummary>): api.RunSummary => ({
    id: 1,
    status: 'success',
    source: 'schedule',
    user: null,
    message: null,
    created_at: new Date(Date.now() - 3 * 3600_000).toISOString(),
    started_at: null,
    finished_at: null,
    progress: null,
    ...changes,
});

const automation = (changes: Partial<api.Automation>): api.Automation => ({
    id: 1,
    name: 'Nightly restart',
    enabled: true,
    skip_offline: true,
    trigger: { type: 'daily', time: '04:00', timezone: 'Europe/Berlin' },
    summary: 'Every day at 04:00',
    steps: [],
    step_count: 3,
    next_run_at: new Date(Date.now() + 3600_000).toISOString(),
    last_run: run({}),
    ...changes,
});

function renderScreen(list: Partial<api.AutomationList>, permissions: string[]) {
    vi.mocked(api.getAutomations).mockResolvedValue({ automations: [], can_manage: false, can_run: false, ...list });
    host = createExtensionTestHost({ extensionId: 'automations', prefix: 'auto', server: createTestServer({ owner: false, permissions }) });
    render(<AutomationsScreen />, { wrapper: host.Wrapper });
}

test('lists automations with trigger, steps, runs and a progress bar while running', async () => {
    renderScreen(
        {
            automations: [
                automation({}),
                automation({
                    id: 2,
                    name: 'Backup when empty',
                    summary: 'Every 30 minutes',
                    trigger: { type: 'interval', every: 30, timezone: 'UTC' },
                    step_count: 1,
                    enabled: false,
                    next_run_at: null,
                    last_run: run({ id: 9, status: 'running', progress: { current: 2, total: 4, step: 'Countdown', percent: 37, until: new Date(Date.now() + 272_000).toISOString() } }),
                }),
            ],
            can_manage: true,
            can_run: true,
        },
        ['ext.automations.read', 'ext.automations.manage', 'ext.automations.run']
    );

    expect(await screen.findByText('Nightly restart')).toBeTruthy();
    expect(screen.getByText('Every day at 04:00 · Europe/Berlin')).toBeTruthy();
    expect(screen.getByText('3 steps')).toBeTruthy();
    const finished = screen.getByText('Nightly restart').closest('tr')!;
    expect(within(finished).getByText('Succeeded')).toBeTruthy();
    expect(within(finished).getByText('3 hours ago')).toBeTruthy();
    expect(screen.getByText('Paused')).toBeTruthy();

    const progress = screen.getByRole('progressbar');
    expect(progress.getAttribute('aria-valuenow')).toBe('37');
    expect(screen.getByText(/Step 2 of 4: Countdown · 4:3\d left/)).toBeTruthy();

    const running = screen.getByText('Backup when empty').closest('tr')!;
    expect(within(running).getByRole('button', { name: 'Cancel' })).toBeTruthy();
    expect(within(running).queryByRole('button', { name: 'Run now' })).toBeNull();
    expect((screen.getByRole('switch', { name: 'Backup when empty enabled' }) as HTMLElement).getAttribute('aria-checked')).toBe('false');

    vi.mocked(api.runAutomation).mockResolvedValue({ automations: [], can_manage: true, can_run: true });
    fireEvent.click(within(screen.getByText('Nightly restart').closest('tr')!).getByRole('button', { name: 'Run now' }));
    await waitFor(() => expect(api.runAutomation).toHaveBeenCalledWith(expect.any(String), 1));
});

test('shows each automation as a card with all its actions on narrow screens', async () => {
    narrow();
    renderScreen(
        {
            automations: [
                automation({}),
                automation({ id: 2, name: 'Backup when empty', enabled: false, next_run_at: null, last_run: run({ id: 9, status: 'running', progress: { current: 1, total: 2, step: 'Wait', percent: 50, until: null } }) }),
            ],
            can_manage: true,
            can_run: true,
        },
        ['ext.automations.read', 'ext.automations.manage', 'ext.automations.run']
    );

    const card = (await screen.findByText('Nightly restart')).closest('li')!;
    expect(screen.queryByRole('table')).toBeNull();
    expect(within(card).getByText('Every day at 04:00 · Europe/Berlin · 3 steps')).toBeTruthy();
    expect(within(card).getByText('Next run')).toBeTruthy();
    expect(within(card).getByText('Succeeded')).toBeTruthy();
    expect(within(card).getByText('3 hours ago')).toBeTruthy();
    expect(within(card).getByRole('switch', { name: 'Nightly restart enabled' }).getAttribute('aria-checked')).toBe('true');
    for (const action of ['Run now', 'Edit', 'History', 'Delete']) {
        expect(within(card).getByRole('button', { name: action })).toBeTruthy();
    }

    const running = screen.getByText('Backup when empty').closest('li')!;
    expect(within(running).getByText('Paused')).toBeTruthy();
    expect(within(running).getByRole('progressbar')).toBeTruthy();
    expect(within(running).getByRole('button', { name: 'Cancel' })).toBeTruthy();

    fireEvent.click(within(card).getByRole('button', { name: 'Edit' }));
    expect(navigate).toHaveBeenCalledWith({ extension: 'automations', screen: 'editor', params: { id: expect.any(String), automationId: '1' } });
});

test('offers the first automation on narrow screens too', async () => {
    narrow();
    renderScreen({ automations: [], can_manage: true }, ['ext.automations.read', 'ext.automations.manage']);

    expect(await screen.findByText('No automations')).toBeTruthy();
    expect(screen.getByRole('button', { name: /New automation/ })).toBeTruthy();
});

test('is read only with only the read permission', async () => {
    renderScreen({ automations: [automation({})] }, ['ext.automations.read']);

    expect(await screen.findByText('Nightly restart')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Run now' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Edit' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Delete' })).toBeNull();
    expect(screen.queryByRole('button', { name: /New automation/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'History' })).toBeTruthy();
    expect((screen.getByRole('switch', { name: 'Nightly restart enabled' }) as HTMLElement).hasAttribute('data-disabled')).toBe(true);
});

test('shows the per-step log in the history', async () => {
    vi.mocked(api.getRuns).mockResolvedValue([
        {
            ...run({ status: 'failed', message: 'Server must be online in order to send commands.', started_at: new Date(Date.now() - 60_000).toISOString(), finished_at: new Date().toISOString() }),
            log: [
                { at: new Date().toISOString(), position: '1', step: 'Countdown', result: 'skipped', message: 'Could not announce 10 minutes: offline' },
                { at: new Date().toISOString(), position: '2 › then 1', step: 'Send command', result: 'failed', message: 'Server must be online in order to send commands.' },
            ],
        },
    ]);
    renderScreen({ automations: [automation({})] }, ['ext.automations.read']);

    fireEvent.click(await screen.findByRole('button', { name: 'History' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Show steps' }));

    expect(screen.getByText('2 › then 1. Send command')).toBeTruthy();
    expect(screen.getByText('Could not announce 10 minutes: offline')).toBeTruthy();
    expect(screen.getByText('Scheduled · took 1 min')).toBeTruthy();
});

test('offers the first automation when there is none', async () => {
    renderScreen({ automations: [], can_manage: true }, ['ext.automations.read', 'ext.automations.manage']);

    expect(await screen.findByText('No automations')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: /New automation/ }));
    expect(navigate).toHaveBeenCalledWith({ extension: 'automations', screen: 'editor', params: { id: expect.any(String), automationId: 'new' } });
});

test('the schedules banner links to automations for people who can see them', async () => {
    host = createExtensionTestHost({ extensionId: 'automations', prefix: 'auto', server: createTestServer({ owner: false, permissions: ['ext.automations.read'] }) });
    render(<SchedulesBanner />, { wrapper: host.Wrapper });

    fireEvent.click(await screen.findByRole('button', { name: 'Open Automations' }));
    expect(navigate).toHaveBeenCalledWith({ extension: 'automations', screen: 'automations', params: { id: expect.any(String) } });
    cleanup();
    host.dispose();

    host = createExtensionTestHost({ extensionId: 'automations', prefix: 'auto', server: createTestServer({ owner: false, permissions: ['schedule.read'] }) });
    const { container } = render(<SchedulesBanner />, { wrapper: host.Wrapper });
    expect(container.textContent).toBe('');
});
