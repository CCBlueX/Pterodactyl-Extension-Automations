import type { ReactNode } from 'react';
import { Checkbox, Input, Label, Select, Switch, TextArea } from '@pterodactyl/sdk';
import type { PowerAction, Step } from '../api';
import { COUNTDOWN_POINTS } from '../lib/steps';
import { durationWords, shortDuration } from '../lib/format';
import DurationInput from './DurationInput';
import Toggle from './Toggle';

type Of<T extends Step['type']> = Extract<Step, { type: T }>;

interface Props<T extends Step['type']> {
    id: string;
    step: Of<T>;
    onChange: (step: Step) => void;
}

const POWER: { value: PowerAction; label: string }[] = [
    { value: 'start', label: 'Start' },
    { value: 'stop', label: 'Stop' },
    { value: 'restart', label: 'Restart' },
    { value: 'kill', label: 'Kill' },
];

const Hint = ({ children }: { children: ReactNode }) => <p className='auto:mt-1 auto:text-xs auto:text-muted-foreground'>{children}</p>;

/** "Continue if this step fails" for steps that can fail on their own. */
function ContinueOnFailure({ id, checked, onChange }: { id: string; checked: boolean; onChange: (checked: boolean) => void }) {
    return (
        <label htmlFor={`${id}-continue`} className='auto:flex auto:w-fit auto:cursor-pointer auto:items-center auto:gap-2 auto:text-xs auto:text-muted-foreground'>
            <Checkbox id={`${id}-continue`} checked={checked} onChange={onChange} />
            Continue if this step fails
        </label>
    );
}

function CommandFields({ id, step, onChange }: Props<'command'>) {
    return (
        <>
            <div>
                <Label htmlFor={`${id}-command`}>Command</Label>
                <Input id={`${id}-command`} className='auto:font-mono' maxLength={500} placeholder='say Hello' value={step.command} onChange={(event) => onChange({ ...step, command: event.currentTarget.value })} />
            </div>
            <ContinueOnFailure id={id} checked={step.continue_on_failure ?? false} onChange={(checked) => onChange({ ...step, continue_on_failure: checked })} />
        </>
    );
}

function PowerFields({ id, step, onChange }: Props<'power'>) {
    return (
        <>
            <div className='auto:sm:max-w-60'>
                <Label htmlFor={`${id}-action`}>Signal</Label>
                <Select id={`${id}-action`} value={step.action} options={POWER} onChange={(value) => onChange({ ...step, action: value as PowerAction })} />
            </div>
            <ContinueOnFailure id={id} checked={step.continue_on_failure ?? false} onChange={(checked) => onChange({ ...step, continue_on_failure: checked })} />
        </>
    );
}

function BackupFields({ id, step, onChange }: Props<'backup'>) {
    return (
        <>
            <div>
                <Label htmlFor={`${id}-name`}>Name (optional)</Label>
                <Input id={`${id}-name`} maxLength={191} placeholder='Named after the automation' value={step.name ?? ''} onChange={(event) => onChange({ ...step, name: event.currentTarget.value || null })} />
            </div>
            <div>
                <Label htmlFor={`${id}-ignored`}>Ignored files (optional)</Label>
                <TextArea id={`${id}-ignored`} rows={3} className='auto:font-mono' placeholder={'logs/\ncache/*'} value={step.ignored} onChange={(event) => onChange({ ...step, ignored: event.currentTarget.value })} />
                <Hint>One path per line, like a .gitignore. When the server is at its backup limit, the oldest unlocked backup is replaced.</Hint>
            </div>
            <Switch id={`${id}-wait`} label='Wait until it finishes' description='The next step starts once the backup is done (up to 1 hour).' checked={step.wait} onChange={(wait) => onChange({ ...step, wait })} />
            <ContinueOnFailure id={id} checked={step.continue_on_failure ?? false} onChange={(checked) => onChange({ ...step, continue_on_failure: checked })} />
        </>
    );
}

function WaitFields({ id, step, onChange }: Props<'wait'>) {
    return (
        <div className='auto:sm:max-w-80'>
            <Label htmlFor={`${id}-seconds`}>Wait for</Label>
            <DurationInput id={`${id}-seconds`} seconds={step.seconds} onChange={(seconds) => onChange({ ...step, seconds })} />
        </div>
    );
}

function WaitForFields({ id, step, onChange }: Props<'wait_for'>) {
    return (
        <div className='auto:grid auto:gap-2 auto:sm:grid-cols-2'>
            <div>
                <Label htmlFor={`${id}-state`}>Until the server is</Label>
                <Select
                    id={`${id}-state`}
                    value={step.state}
                    options={[
                        { value: 'running', label: 'Running' },
                        { value: 'offline', label: 'Offline' },
                    ]}
                    onChange={(value) => onChange({ ...step, state: value as 'running' | 'offline' })}
                />
            </div>
            <div>
                <Label htmlFor={`${id}-timeout`}>Give up after (minutes)</Label>
                <Input id={`${id}-timeout`} type='number' min={1} max={60} value={step.timeout} onChange={(event) => onChange({ ...step, timeout: Number(event.currentTarget.value) })} />
            </div>
        </div>
    );
}

function CountdownFields({ id, step, onChange }: Props<'countdown'>) {
    const toggle = (point: number) => onChange({ ...step, points: step.points.includes(point) ? step.points.filter((p) => p !== point) : [...step.points, point].sort((a, b) => b - a) });
    const longest = step.points[0];

    return (
        <>
            <div>
                <p className='auto:mb-1 auto:text-sm auto:text-foreground'>Announce at</p>
                <div className='auto:flex auto:flex-wrap auto:gap-1.5' role='group' aria-label='Announce at'>
                    {COUNTDOWN_POINTS.map((point) => (
                        <Toggle key={point} pressed={step.points.includes(point)} label={`${durationWords(point)} before`} onClick={() => toggle(point)}>
                            {shortDuration(point)}
                        </Toggle>
                    ))}
                </div>
                <Hint>{longest ? `Takes ${durationWords(longest)}, then the next step runs.` : 'Pick at least one time.'}</Hint>
            </div>
            <div>
                <Label htmlFor={`${id}-message`}>Announcement</Label>
                <Input id={`${id}-message`} className='auto:font-mono' maxLength={500} value={step.message} onChange={(event) => onChange({ ...step, message: event.currentTarget.value })} />
                <Hint>
                    {'{TIME}'} becomes the time left. Sends: <span className='auto:font-mono auto:text-foreground'>{step.message.replaceAll('{TIME}', durationWords(longest ?? 600))}</span>
                </Hint>
            </div>
        </>
    );
}

function WebhookFields({ id, step, onChange }: Props<'webhook'>) {
    return (
        <>
            <div className='auto:grid auto:gap-2 auto:sm:grid-cols-[10rem_minmax(0,1fr)]'>
                <div>
                    <Label htmlFor={`${id}-kind`}>Send to</Label>
                    <Select
                        id={`${id}-kind`}
                        value={step.kind}
                        options={[
                            { value: 'discord', label: 'Discord' },
                            { value: 'webhook', label: 'Webhook' },
                        ]}
                        onChange={(value) => onChange({ ...step, kind: value as 'discord' | 'webhook' })}
                    />
                </div>
                <div>
                    <Label htmlFor={`${id}-url`}>URL</Label>
                    <Input id={`${id}-url`} type='url' maxLength={500} placeholder={step.kind === 'discord' ? 'https://discord.com/api/webhooks/...' : 'https://example.com/hook'} value={step.url} onChange={(event) => onChange({ ...step, url: event.currentTarget.value })} />
                </div>
            </div>
            <div>
                <Label htmlFor={`${id}-message`}>Message</Label>
                <Input id={`${id}-message`} maxLength={2000} value={step.message} onChange={(event) => onChange({ ...step, message: event.currentTarget.value })} />
                <Hint>{'{SERVER}'} and {'{AUTOMATION}'} are filled in. Webhooks receive JSON with the message, server and automation.</Hint>
            </div>
            <ContinueOnFailure id={id} checked={step.continue_on_failure ?? false} onChange={(checked) => onChange({ ...step, continue_on_failure: checked })} />
        </>
    );
}

/** The fields of every step type except if/else, which StepList renders with its branches. */
export default function StepFields({ id, step, onChange }: { id: string; step: Exclude<Step, { type: 'if' }>; onChange: (step: Step) => void }) {
    switch (step.type) {
        case 'command':
            return <CommandFields id={id} step={step} onChange={onChange} />;
        case 'power':
            return <PowerFields id={id} step={step} onChange={onChange} />;
        case 'backup':
            return <BackupFields id={id} step={step} onChange={onChange} />;
        case 'wait':
            return <WaitFields id={id} step={step} onChange={onChange} />;
        case 'wait_for':
            return <WaitForFields id={id} step={step} onChange={onChange} />;
        case 'countdown':
            return <CountdownFields id={id} step={step} onChange={onChange} />;
        case 'webhook':
            return <WebhookFields id={id} step={step} onChange={onChange} />;
    }
}
