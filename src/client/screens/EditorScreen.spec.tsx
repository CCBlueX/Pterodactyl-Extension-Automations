import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import EditorScreen from './EditorScreen';

const { navigate } = vi.hoisted(() => ({ navigate: vi.fn() }));

// The test host does not register extension screens, so navigation is checked by destination.
vi.mock('@pterodactyl/sdk', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@pterodactyl/sdk')>()),
    usePanelNavigate: () => navigate,
    PanelLink: ({ children, className }: { children: React.ReactNode; className?: string }) => <a className={className}>{children}</a>,
}));

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getAutomations: vi.fn(),
    saveAutomation: vi.fn(),
    previewTrigger: vi.fn(),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

const existing: api.Automation = {
    id: 7,
    name: 'Nightly restart',
    enabled: true,
    skip_offline: true,
    trigger: { type: 'weekly', time: '05:30', days: [1, 3], timezone: 'Europe/Berlin' },
    summary: 'Mon and Wed at 05:30',
    steps: [
        { type: 'command', command: 'save-all', continue_on_failure: false },
        { type: 'power', action: 'restart', continue_on_failure: false },
    ],
    step_count: 2,
    next_run_at: null,
    last_run: null,
};

function renderEditor(automationId: string, eggFeatures: string[] = []) {
    vi.mocked(api.getAutomations).mockResolvedValue({ automations: [existing], can_manage: true, can_run: true });
    vi.mocked(api.previewTrigger).mockResolvedValue({ summary: 'Every day at 04:00', next: ['2026-10-12T02:00:00+00:00', '2026-10-13T02:00:00+00:00', '2026-10-14T02:00:00+00:00'] });
    vi.mocked(api.saveAutomation).mockResolvedValue({ automations: [], can_manage: true, can_run: true });
    const server = createTestServer({ owner: true, eggFeatures });
    host = createExtensionTestHost({ extensionId: 'automations', prefix: 'auto', server });
    render(<EditorScreen data={{ pathname: '', search: {}, params: { id: server.attributes.identifier, automationId } }} />, { wrapper: host.Wrapper });

    return server.attributes.identifier;
}

test('builds a restart with a countdown and a player check, then saves the tree', async () => {
    const id = renderEditor('new', ['eula']);

    fireEvent.change(await screen.findByLabelText('Name'), { target: { value: 'Restart when empty' } });
    const create = screen.getByRole('button', { name: 'Create automation' }) as HTMLButtonElement;
    expect(create.disabled).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Add If / else' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add Countdown' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add Power action to 1 › then' }));
    fireEvent.click(screen.getByRole('button', { name: 'Add Notify to 1 › otherwise' }));

    // The countdown goes first, which moves the if/else to position 2.
    fireEvent.click(screen.getByRole('button', { name: 'Move step 2 up' }));
    expect((screen.getByRole('button', { name: 'Move step 1 up' }) as HTMLButtonElement).disabled).toBe(true);
    expect(screen.getByRole('button', { name: 'Add Send command to 2 › then' })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: '30 minutes before' }));
    fireEvent.click(screen.getByRole('button', { name: '10 seconds before' }));
    expect(screen.getByText('Takes 30 minutes, then the next step runs.')).toBeTruthy();
    expect(screen.getByText('say Restarting in 30 minutes')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('URL'), { target: { value: 'https://discord.com/api/webhooks/1/abc' } });
    expect(await screen.findByText('Every day at 04:00')).toBeTruthy();
    expect(within(screen.getByRole('list', { name: 'Next runs' })).getAllByRole('listitem')).toHaveLength(3);

    fireEvent.click(create);
    await waitFor(() => expect(api.saveAutomation).toHaveBeenCalled());

    const [server, input, automation] = vi.mocked(api.saveAutomation).mock.calls[0];
    expect(server).toBe(id);
    expect(automation).toBeUndefined();
    expect(input.name).toBe('Restart when empty');
    expect(input.steps).toEqual([
        { type: 'countdown', points: [1800, 600, 300, 60, 30], message: 'say Restarting in {TIME}' },
        {
            type: 'if',
            condition: { kind: 'state', state: 'running' },
            then: [{ type: 'power', action: 'restart', continue_on_failure: false }],
            else: [{ type: 'webhook', kind: 'discord', url: 'https://discord.com/api/webhooks/1/abc', message: '{AUTOMATION} ran on {SERVER}.', continue_on_failure: false }],
        },
    ]);
    await waitFor(() => expect(navigate).toHaveBeenCalledWith({ extension: 'automations', screen: 'automations', params: { id } }));
});

test('offers player counts only for Minecraft eggs', async () => {
    const options = async () => {
        fireEvent.click(screen.getByRole('button', { name: 'Add If / else' }));
        const input = screen.getByLabelText('If');
        input.focus();
        fireEvent.keyDown(input, { key: 'ArrowDown' });

        return (await screen.findAllByRole('option')).map((option) => option.textContent);
    };

    renderEditor('new', ['eula']);
    await screen.findByLabelText('Name');
    expect(await options()).toContain('Players online');
    cleanup();
    host?.dispose();

    renderEditor('new', []);
    await screen.findByLabelText('Name');
    expect(await options()).not.toContain('Players online');
});

test('edits an existing automation', async () => {
    renderEditor('7');

    expect(await screen.findByDisplayValue('Nightly restart')).toBeTruthy();
    expect(screen.getByDisplayValue('save-all')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Monday' }).getAttribute('aria-pressed')).toBe('true');
    expect(screen.getByRole('button', { name: 'Tuesday' }).getAttribute('aria-pressed')).toBe('false');

    fireEvent.click(screen.getByRole('button', { name: 'Remove step 1' }));
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));

    await waitFor(() => expect(api.saveAutomation).toHaveBeenCalled());
    const [, input, automation] = vi.mocked(api.saveAutomation).mock.calls[0];
    expect(automation).toBe(7);
    expect(input.steps).toEqual([{ type: 'power', action: 'restart', continue_on_failure: false }]);
    expect(input.trigger).toEqual(existing.trigger);
});

test('says when the automation is gone', async () => {
    renderEditor('99');

    expect(await screen.findByText(/This automation no longer exists/)).toBeTruthy();
});
