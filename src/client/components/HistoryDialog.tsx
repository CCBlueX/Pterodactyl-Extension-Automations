import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Button, Dialog, Spinner, httpErrorToHuman } from '@pterodactyl/sdk';
import { getRuns, isActive, keys, type Automation, type Run } from '../api';
import { formatAgo, formatWhen } from '../lib/format';
import RunProgress from './RunProgress';
import StatusPill from './StatusPill';

interface Props {
    server: string;
    automation: Automation;
    onClose: () => void;
}

const took = (run: Run): string | null => {
    if (!run.started_at || !run.finished_at) {
        return null;
    }

    const seconds = Math.round((new Date(run.finished_at).getTime() - new Date(run.started_at).getTime()) / 1000);

    return seconds < 60 ? `${seconds}s` : seconds < 3600 ? `${Math.round(seconds / 60)} min` : `${(seconds / 3600).toFixed(1)} h`;
};

export default function HistoryDialog({ server, automation, onClose }: Props) {
    const runs = useQuery({
        queryKey: keys.runs(server, automation.id),
        queryFn: () => getRuns(server, automation.id),
        refetchInterval: (query) => (query.state.data?.some(isActive) ? 3000 : false),
    });
    const [open, setOpen] = useState<number | null>(null);

    return (
        <Dialog open onClose={onClose} title={`History of ${automation.name}`}>
            {!runs.data ? (
                runs.isError ? <p className='auto:text-sm auto:text-destructive'>{httpErrorToHuman(runs.error)}</p> : <Spinner centered />
            ) : runs.data.length === 0 ? (
                <p className='auto:text-sm auto:text-muted-foreground'>This automation has not run yet.</p>
            ) : (
                <ul className='auto:grid auto:max-h-[60vh] auto:gap-2 auto:overflow-y-auto'>
                    {runs.data.map((run) => (
                        <li key={run.id} className='auto:rounded-sm auto:bg-sunken auto:px-3 auto:py-2'>
                            <div className='auto:flex auto:flex-wrap auto:items-center auto:gap-x-3 auto:gap-y-1'>
                                <StatusPill status={run.status} />
                                <span className='auto:text-sm auto:text-foreground' title={new Date(run.created_at).toLocaleString()}>
                                    {formatWhen(run.started_at ?? run.created_at)}
                                </span>
                                <span className='auto:text-xs auto:text-muted-foreground'>
                                    {run.source === 'manual' ? `Run by ${run.user ?? 'a former user'}` : 'Scheduled'}
                                    {took(run) && ` · took ${took(run)}`}
                                    {!took(run) && ` · ${formatAgo(run.created_at)}`}
                                </span>
                                {run.log.length > 0 && (
                                    <Button.Text size='xsmall' className='auto:ml-auto' onClick={() => setOpen(open === run.id ? null : run.id)}>
                                        {open === run.id ? 'Hide steps' : 'Show steps'}
                                    </Button.Text>
                                )}
                            </div>
                            {run.message && <p className='auto:mt-1 auto:text-xs auto:text-muted-foreground'>{run.message}</p>}
                            {run.progress && <RunProgress progress={run.progress} />}
                            {open === run.id && (
                                <ol className='auto:mt-2 auto:grid auto:gap-1 auto:border-t auto:border-border auto:pt-2'>
                                    {run.log.map((entry, index) => (
                                        <li key={index} className='auto:grid auto:grid-cols-[4.5rem_minmax(0,1fr)] auto:gap-2 auto:text-xs'>
                                            <span className='auto:font-mono auto:text-muted-foreground'>{new Date(entry.at).toLocaleTimeString()}</span>
                                            <span className='auto:min-w-0'>
                                                <span className='auto:mr-2 auto:inline-block'>
                                                    <StatusPill status={entry.result} />
                                                </span>
                                                <span className='auto:text-foreground'>
                                                    {entry.position}. {entry.step}
                                                </span>
                                                <span className='auto:block auto:break-words auto:text-muted-foreground'>{entry.message}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Dialog.Footer>
                <Button.Text className='auto:w-full auto:sm:w-auto' onClick={onClose}>
                    Close
                </Button.Text>
            </Dialog.Footer>
        </Dialog>
    );
}
