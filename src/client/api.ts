import { http } from '@pterodactyl/sdk';

export type PowerAction = 'start' | 'stop' | 'restart' | 'kill';
export type StateName = 'running' | 'offline' | 'starting' | 'stopping';
export type Comparison = 'above' | 'below';

export type Condition = { kind: 'state'; state: StateName } | { kind: 'cpu' | 'memory' | 'players'; op: Comparison; value: number };

export type Step =
    | { type: 'command'; command: string; continue_on_failure?: boolean }
    | { type: 'power'; action: PowerAction; continue_on_failure?: boolean }
    | { type: 'backup'; name: string | null; ignored: string; wait: boolean; continue_on_failure?: boolean }
    | { type: 'wait'; seconds: number }
    | { type: 'wait_for'; state: 'running' | 'offline'; timeout: number }
    | { type: 'countdown'; points: number[]; message: string }
    | { type: 'if'; condition: Condition; then: Step[]; else: Step[] }
    | { type: 'webhook'; kind: 'discord' | 'webhook'; url: string; message: string; continue_on_failure?: boolean };

export type StepType = Step['type'];

export interface Trigger {
    type: 'interval' | 'daily' | 'weekly';
    timezone: string;
    every?: number;
    time?: string;
    days?: number[];
}

export type RunStatus = 'queued' | 'running' | 'success' | 'failed' | 'skipped' | 'cancelled';

export interface Progress {
    current: number;
    total: number;
    step: string;
    percent: number;
    until: string | null;
}

export interface RunSummary {
    id: number;
    status: RunStatus;
    source: 'schedule' | 'manual';
    user: string | null;
    message: string | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
    progress: Progress | null;
}

export interface LogEntry {
    at: string;
    position: string;
    step: string;
    result: 'ok' | 'failed' | 'skipped' | 'info';
    message: string;
}

export interface Run extends RunSummary {
    log: LogEntry[];
}

export interface Automation {
    id: number;
    name: string;
    enabled: boolean;
    skip_offline: boolean;
    trigger: Trigger;
    summary: string;
    steps: Step[];
    step_count: number;
    next_run_at: string | null;
    last_run: RunSummary | null;
}

export interface AutomationList {
    automations: Automation[];
    can_manage: boolean;
    can_run: boolean;
}

export interface AutomationInput {
    name: string;
    enabled: boolean;
    skip_offline: boolean;
    trigger: Trigger;
    steps: Step[];
}

export interface Preview {
    summary: string;
    next: string[];
}

const base = (id: string) => `/api/client/servers/${id}/extensions/automations`;

export const keys = {
    list: (id: string) => ['automations', id] as const,
    runs: (id: string, automation: number) => ['automations', id, 'runs', automation] as const,
    preview: (id: string, trigger: Trigger) => ['automations', id, 'preview', trigger] as const,
};

export const isActive = (run: RunSummary | null): boolean => run !== null && (run.status === 'queued' || run.status === 'running');

export const getAutomations = async (id: string) => (await http.get<AutomationList>(base(id))).data;
export const saveAutomation = async (id: string, input: AutomationInput, automation?: number) =>
    (automation ? await http.put<AutomationList>(`${base(id)}/automations/${automation}`, input) : await http.post<AutomationList>(`${base(id)}/automations`, input)).data;
export const setEnabled = async (id: string, automation: number, enabled: boolean) => (await http.put<AutomationList>(`${base(id)}/automations/${automation}/enabled`, { enabled })).data;
export const deleteAutomation = async (id: string, automation: number) => (await http.delete<AutomationList>(`${base(id)}/automations/${automation}`)).data;
export const runAutomation = async (id: string, automation: number) => (await http.post<AutomationList>(`${base(id)}/automations/${automation}/run`)).data;
export const cancelAutomation = async (id: string, automation: number) => (await http.post<AutomationList>(`${base(id)}/automations/${automation}/cancel`)).data;
export const getRuns = async (id: string, automation: number) => (await http.get<{ runs: Run[] }>(`${base(id)}/automations/${automation}/runs`)).data.runs;
export const previewTrigger = async (id: string, trigger: Trigger) => (await http.post<Preview>(`${base(id)}/preview`, { trigger })).data;
