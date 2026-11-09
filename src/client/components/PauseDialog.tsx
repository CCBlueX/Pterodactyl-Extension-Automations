import { useState } from 'react';
import { Button, Dialog, Input, Label } from '@pterodactyl/sdk';
import type { Automation } from '../api';
import { browserTimeZone } from './TriggerFields';

interface Props {
    automation: Automation;
    pending: boolean;
    onPause: (until: string, timezone: string) => void;
    onClose: () => void;
}

/** "2026-11-10T08:00" for the input, in the browser's time zone. */
const localInput = (date: Date) => new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);

/** Asks until when the scheduled runs of an automation stop. */
export default function PauseDialog({ automation, pending, onPause, onClose }: Props) {
    const [until, setUntil] = useState(() => localInput(new Date(Date.now() + 86400_000)));
    const timezone = browserTimeZone();

    return (
        <Dialog open onClose={onClose} title={`Pause ${automation.name}`} preventExternalClose={pending}>
            <div className='auto:grid auto:gap-2'>
                <Label htmlFor='auto-pause-until'>Pause until</Label>
                <Input id='auto-pause-until' type='datetime-local' autoFocus min={localInput(new Date())} value={until} onChange={(event) => setUntil(event.currentTarget.value)} />
                <p className='auto:text-xs auto:text-muted-foreground'>Scheduled runs are skipped until then, in {timezone.replaceAll('_', ' ')}. Run now still works.</p>
            </div>
            <Dialog.Footer>
                <Button.Text className='auto:w-full auto:sm:w-auto' disabled={pending} onClick={onClose}>
                    Cancel
                </Button.Text>
                <Button className='auto:w-full auto:sm:w-auto' disabled={!until} isLoading={pending} onClick={() => onPause(until, timezone)}>
                    Pause
                </Button>
            </Dialog.Footer>
        </Dialog>
    );
}
