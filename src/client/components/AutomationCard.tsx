import type { ReactNode } from 'react';
import { isActive, type Automation } from '../api';
import { plural } from '../lib/format';
import RunProgress from './RunProgress';

interface Props {
    automation: Automation;
    next: ReactNode;
    last: ReactNode;
    enabled: ReactNode;
    actions: ReactNode;
}

const Detail = ({ label, children }: { label: string; children: ReactNode }) => (
    <div className='auto:min-w-0'>
        <dt className='auto:text-xs auto:font-semibold auto:uppercase auto:tracking-wide auto:text-muted-foreground'>{label}</dt>
        <dd className='auto:mt-1'>{children}</dd>
    </div>
);

/** One automation on narrow screens, where the table does not fit. */
export default function AutomationCard({ automation, next, last, enabled, actions }: Props) {
    return (
        <li className='auto:p-3'>
            <div className='auto:flex auto:items-start auto:justify-between auto:gap-3'>
                <div className='auto:min-w-0'>
                    <p className='auto:break-words auto:font-medium auto:text-foreground'>{automation.name}</p>
                    <p className='auto:mt-0.5 auto:text-xs auto:text-muted-foreground'>
                        {automation.summary} · {automation.trigger.timezone} · {plural(automation.step_count, 'step')}
                    </p>
                </div>
                <div className='auto:shrink-0 auto:pt-0.5'>{enabled}</div>
            </div>
            {isActive(automation.last_run) && automation.last_run?.progress && <RunProgress progress={automation.last_run.progress} />}
            <dl className='auto:mt-3 auto:grid auto:grid-cols-2 auto:gap-3'>
                <Detail label='Next run'>{next}</Detail>
                <Detail label='Last run'>{last}</Detail>
            </dl>
            <div className='auto:mt-3'>{actions}</div>
        </li>
    );
}
