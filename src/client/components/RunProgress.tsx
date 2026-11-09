import { ProgressBar } from '@pterodactyl/sdk';
import type { Progress } from '../api';
import { formatLeft } from '../lib/format';

/** The bar under a running automation. */
export default function RunProgress({ progress }: { progress: Progress }) {
    const label = `Step ${progress.current} of ${progress.total}: ${progress.step}${progress.until ? ` · ${formatLeft(progress.until)} left` : ''}`;

    return (
        <div className='auto:mt-2 auto:max-w-sm'>
            <ProgressBar value={Math.min(100, Math.max(4, progress.percent))} tone='info' label={label} />
            <p className='auto:mt-1 auto:text-xs auto:text-muted-foreground'>{label}</p>
        </div>
    );
}
