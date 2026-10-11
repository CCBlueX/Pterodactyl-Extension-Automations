import type { Progress } from '../api';
import { formatLeft } from '../lib/format';

/** The bar under a running automation. */
export default function RunProgress({ progress }: { progress: Progress }) {
    const percent = Math.min(100, Math.max(4, progress.percent));
    const label = `Step ${progress.current} of ${progress.total}: ${progress.step}${progress.until ? ` · ${formatLeft(progress.until)} left` : ''}`;

    return (
        <div className='auto:mt-2 auto:max-w-sm'>
            <div className='auto:h-1.5 auto:w-full auto:overflow-hidden auto:rounded-sm auto:bg-sunken' role='progressbar' aria-label={label} aria-valuenow={progress.percent} aria-valuemin={0} aria-valuemax={100}>
                <div className='auto:h-1.5 auto:rounded-sm auto:bg-primary auto:transition-[width] auto:duration-500' style={{ width: `${percent}%` }} />
            </div>
            <p className='auto:mt-1 auto:text-xs auto:text-muted-foreground'>{label}</p>
        </div>
    );
}
