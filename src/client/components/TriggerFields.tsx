import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Input, Label, Select, Spinner, httpErrorToHuman } from '@pterodactyl/sdk';
import { keys, previewTrigger, type Trigger } from '../api';
import { formatWhen } from '../lib/format';
import Toggle from './Toggle';

const TYPES = [
    { value: 'interval', label: 'Every few minutes' },
    { value: 'daily', label: 'Every day' },
    { value: 'weekly', label: 'On chosen weekdays' },
];

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

export const browserTimeZone = () => Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';

function timeZones(current: string): string[] {
    const zones = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : [];

    return [...new Set(['UTC', current, browserTimeZone(), ...zones])].sort((a, b) => (a === 'UTC' ? -1 : b === 'UTC' ? 1 : a.localeCompare(b)));
}

function useDebounced<T>(value: T, delay: number): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delay);

        return () => clearTimeout(timer);
    }, [value, delay]);

    return debounced;
}

interface Props {
    server: string;
    trigger: Trigger;
    onChange: (trigger: Trigger) => void;
}

export default function TriggerFields({ server, trigger, onChange }: Props) {
    const zones = useMemo(() => timeZones(trigger.timezone).map((zone) => ({ value: zone, label: zone.replaceAll('_', ' ') })), [trigger.timezone]);
    const debounced = useDebounced(trigger, 300);
    const preview = useQuery({ queryKey: keys.preview(server, debounced), queryFn: () => previewTrigger(server, debounced), retry: false, placeholderData: keepPreviousData });
    const days = trigger.days ?? [];

    const setType = (type: Trigger['type']) =>
        onChange(type === 'interval' ? { type, every: trigger.every ?? 15, timezone: trigger.timezone } : { type, time: trigger.time ?? '04:00', ...(type === 'weekly' ? { days: days.length ? days : [1, 2, 3, 4, 5] } : {}), timezone: trigger.timezone });

    return (
        <div className='auto:grid auto:gap-4'>
            <div>
                <Label htmlFor='auto-trigger-type'>Runs</Label>
                <Select id='auto-trigger-type' value={trigger.type} options={TYPES} onChange={(value) => setType(value as Trigger['type'])} />
            </div>
            {trigger.type === 'interval' ? (
                <div>
                    <Label htmlFor='auto-trigger-every'>Every (minutes)</Label>
                    <Input id='auto-trigger-every' type='number' min={1} max={1440} value={trigger.every ?? 15} onChange={(event) => onChange({ ...trigger, every: Number(event.currentTarget.value) })} />
                    <p className='auto:mt-1 auto:text-xs auto:text-muted-foreground'>Counted from midnight: every 15 minutes runs at :00, :15, :30 and :45.</p>
                </div>
            ) : (
                <div>
                    <Label htmlFor='auto-trigger-time'>At</Label>
                    <Input id='auto-trigger-time' type='time' value={trigger.time ?? '04:00'} onChange={(event) => onChange({ ...trigger, time: event.currentTarget.value })} />
                </div>
            )}
            {trigger.type === 'weekly' && (
                <div>
                    <p className='auto:mb-1 auto:text-sm auto:text-foreground'>On</p>
                    <div className='auto:flex auto:flex-wrap auto:gap-1.5' role='group' aria-label='Weekdays'>
                        {DAYS.map((day, index) => (
                            <Toggle
                                key={day}
                                pressed={days.includes(index + 1)}
                                label={DAY_NAMES[index]}
                                onClick={() => onChange({ ...trigger, days: days.includes(index + 1) ? days.filter((d) => d !== index + 1) : [...days, index + 1].sort((a, b) => a - b) })}
                            >
                                {day}
                            </Toggle>
                        ))}
                    </div>
                </div>
            )}
            <div>
                <Label htmlFor='auto-trigger-timezone'>Time zone</Label>
                <Select id='auto-trigger-timezone' value={trigger.timezone} options={zones} searchPlaceholder='Search time zones' onChange={(value) => onChange({ ...trigger, timezone: String(value) })} />
            </div>
            <div className='auto:rounded-sm auto:bg-sunken auto:p-3'>
                <p className='auto:text-xs auto:font-medium auto:uppercase auto:tracking-wide auto:text-muted-foreground'>Next runs</p>
                {preview.isError ? (
                    <p className='auto:mt-1 auto:text-sm auto:text-destructive'>{httpErrorToHuman(preview.error)}</p>
                ) : !preview.data ? (
                    <Spinner size={Spinner.Size.SMALL} />
                ) : (
                    <>
                        <p className='auto:mt-1 auto:text-sm auto:text-foreground'>{preview.data.summary}</p>
                        <ul className='auto:mt-1 auto:grid auto:gap-0.5 auto:text-sm auto:text-muted-foreground' aria-label='Next runs'>
                            {preview.data.next.map((time) => (
                                <li key={time}>{formatWhen(time, debounced.timezone)}</li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </div>
    );
}
