import type { LogEntry, RunStatus } from '../api';

const TONES = {
    neutral: 'auto:border-border auto:bg-muted auto:text-muted-foreground',
    info: 'auto:border-primary/25 auto:bg-primary/10 auto:text-primary',
    success: 'auto:border-success/25 auto:bg-success/10 auto:text-success',
    warning: 'auto:border-warning/25 auto:bg-warning/10 auto:text-warning',
    danger: 'auto:border-destructive/25 auto:bg-destructive/10 auto:text-destructive',
};

const STATUS: Record<RunStatus | LogEntry['result'], { label: string; tone: keyof typeof TONES }> = {
    queued: { label: 'Queued', tone: 'info' },
    running: { label: 'Running', tone: 'info' },
    success: { label: 'Succeeded', tone: 'success' },
    failed: { label: 'Failed', tone: 'danger' },
    skipped: { label: 'Skipped', tone: 'warning' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    ok: { label: 'Done', tone: 'success' },
    info: { label: 'Info', tone: 'neutral' },
};

/** Styled like the panel's node status badge. */
export default function StatusPill({ status }: { status: RunStatus | LogEntry['result'] }) {
    const { label, tone } = STATUS[status];

    return (
        <span className={`auto:inline-flex auto:items-center auto:gap-1.5 auto:whitespace-nowrap auto:rounded-sm auto:border auto:px-2 auto:py-0.5 auto:text-xs auto:font-medium ${TONES[tone]}`}>
            <span aria-hidden className='auto:h-1.5 auto:w-1.5 auto:rounded-full auto:bg-current' />
            {label}
        </span>
    );
}
