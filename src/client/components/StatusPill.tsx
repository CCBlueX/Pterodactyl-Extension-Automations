import { Badge } from '@pterodactyl/sdk';
import type { LogEntry, RunStatus } from '../api';

const STATUS: Record<RunStatus | LogEntry['result'], { label: string; tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger' }> = {
    queued: { label: 'Queued', tone: 'info' },
    running: { label: 'Running', tone: 'info' },
    success: { label: 'Succeeded', tone: 'success' },
    failed: { label: 'Failed', tone: 'danger' },
    skipped: { label: 'Skipped', tone: 'warning' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    ok: { label: 'Done', tone: 'success' },
    info: { label: 'Info', tone: 'neutral' },
};

export default function StatusPill({ status }: { status: RunStatus | LogEntry['result'] }) {
    const { label, tone } = STATUS[status];

    return (
        <Badge tone={tone} size='sm' className='auto:whitespace-nowrap'>
            {label}
        </Badge>
    );
}
