import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Button,
    Dialog,
    Empty,
    EmptyContent,
    EmptyDescription,
    EmptyHeader,
    EmptyMedia,
    EmptyTitle,
    NamedIcon,
    ServerContentBlock,
    Spinner,
    Switch,
    Table,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    usePanelNavigate,
} from '@pterodactyl/sdk';
import { cancelAutomation, deleteAutomation, getAutomations, isActive, keys, runAutomation, setEnabled, type Automation, type AutomationList } from '../api';
import HistoryDialog from '../components/HistoryDialog';
import RunProgress from '../components/RunProgress';
import StatusPill from '../components/StatusPill';
import { formatAgo, formatWhen, plural } from '../lib/format';

export default function AutomationsScreen() {
    const { identifier } = useCurrentServerRequired().attributes;
    const navigate = usePanelNavigate();
    const queryClient = useQueryClient();
    const list = useQuery({
        queryKey: keys.list(identifier),
        queryFn: () => getAutomations(identifier),
        refetchInterval: (query) => (query.state.data?.automations.some((automation) => isActive(automation.last_run)) ? 3000 : 30000),
    });
    const [deleting, setDeleting] = useState<Automation | null>(null);
    const [history, setHistory] = useState<Automation | null>(null);
    const update = (result: AutomationList) => queryClient.setQueryData(keys.list(identifier), result);
    const onError = (error: unknown) => toast.error(httpErrorToHuman(error));

    const toggling = useMutation({ mutationFn: ({ automation, enabled }: { automation: Automation; enabled: boolean }) => setEnabled(identifier, automation.id, enabled), onSuccess: update, onError });
    const running = useMutation({ mutationFn: (automation: Automation) => runAutomation(identifier, automation.id), onSuccess: (result, automation) => (update(result), toast.success(`${automation.name} started.`)), onError });
    const cancelling = useMutation({ mutationFn: (automation: Automation) => cancelAutomation(identifier, automation.id), onSuccess: update, onError });
    const removal = useMutation({ mutationFn: (automation: Automation) => deleteAutomation(identifier, automation.id), onSuccess: (result) => (update(result), setDeleting(null)), onError });

    const edit = (automation: Automation | 'new') => navigate({ extension: 'automations', screen: 'editor', params: { id: identifier, automationId: automation === 'new' ? 'new' : String(automation.id) } });
    const data = list.data;
    const newButton = (
        <Button className='auto:inline-flex auto:h-9 auto:shrink-0 auto:items-center auto:gap-2 auto:px-3' onClick={() => edit('new')}>
            <NamedIcon name='plus' className='auto:h-4 auto:w-4' />
            New automation
        </Button>
    );

    if (!data) {
        return (
            <ServerContentBlock title='Automations'>
                {list.isError ? <p className='auto:text-sm auto:text-destructive'>{httpErrorToHuman(list.error)}</p> : <Spinner size={Spinner.Size.LARGE} centered />}
            </ServerContentBlock>
        );
    }

    return (
        <ServerContentBlock title='Automations'>
            <div className='auto:mb-3 auto:flex auto:flex-col auto:gap-2 auto:sm:flex-row auto:sm:items-center auto:sm:justify-between'>
                <p className='auto:text-sm auto:text-muted-foreground'>Steps with countdowns, waits and conditions that run on their own, in your time zone.</p>
                {data.can_manage && data.automations.length > 0 && newButton}
            </div>
            <Table
                rows={data.automations}
                keyOf={(automation) => String(automation.id)}
                isFetching={list.isFetching}
                emptyState={
                    <Empty>
                        <EmptyHeader>
                            <EmptyMedia variant='icon'>
                                <NamedIcon name='workflow' />
                            </EmptyMedia>
                            <EmptyTitle>No automations</EmptyTitle>
                            <EmptyDescription>Restart with a countdown, back up when nobody is online or tell Discord when the server is back.</EmptyDescription>
                        </EmptyHeader>
                        {data.can_manage && <EmptyContent>{newButton}</EmptyContent>}
                    </Empty>
                }
                columns={[
                    {
                        id: 'name',
                        label: 'Automation',
                        cell: (automation) => (
                            <div className='auto:min-w-48'>
                                <p className='auto:font-medium auto:text-foreground'>{automation.name}</p>
                                <p className='auto:mt-0.5 auto:text-xs auto:text-muted-foreground'>
                                    {automation.summary} · {automation.trigger.timezone}
                                </p>
                                {isActive(automation.last_run) && automation.last_run?.progress && <RunProgress progress={automation.last_run.progress} />}
                            </div>
                        ),
                    },
                    { id: 'steps', label: 'Steps', cell: (automation) => <span className='auto:whitespace-nowrap auto:text-sm'>{plural(automation.step_count, 'step')}</span> },
                    {
                        id: 'next',
                        label: 'Next run',
                        cell: (automation) =>
                            automation.next_run_at ? (
                                <span className='auto:whitespace-nowrap auto:text-xs' title={formatAgo(automation.next_run_at)}>
                                    {formatWhen(automation.next_run_at)}
                                </span>
                            ) : (
                                <span className='auto:text-xs auto:text-muted-foreground'>Paused</span>
                            ),
                    },
                    {
                        id: 'last',
                        label: 'Last run',
                        cell: (automation) =>
                            automation.last_run ? (
                                <span className='auto:flex auto:flex-wrap auto:items-center auto:gap-2' title={automation.last_run.message ?? undefined}>
                                    <StatusPill status={automation.last_run.status} />
                                    <span className='auto:whitespace-nowrap auto:text-xs auto:text-muted-foreground'>{formatAgo(automation.last_run.created_at)}</span>
                                </span>
                            ) : (
                                <span className='auto:text-xs auto:text-muted-foreground'>Never</span>
                            ),
                    },
                    {
                        id: 'enabled',
                        label: 'Enabled',
                        cell: (automation) => (
                            <Switch
                                aria-label={`${automation.name} enabled`}
                                checked={automation.enabled}
                                disabled={!data.can_manage || toggling.isPending}
                                onChange={(enabled) => toggling.mutate({ automation, enabled })}
                            />
                        ),
                    },
                    {
                        id: 'actions',
                        label: '',
                        cell: (automation) => (
                            <div className='auto:flex auto:flex-wrap auto:justify-end auto:gap-2'>
                                {data.can_run &&
                                    (isActive(automation.last_run) ? (
                                        <Button.Danger size='xsmall' disabled={cancelling.isPending} onClick={() => cancelling.mutate(automation)}>
                                            Cancel
                                        </Button.Danger>
                                    ) : (
                                        <Button size='xsmall' disabled={running.isPending} onClick={() => running.mutate(automation)}>
                                            Run now
                                        </Button>
                                    ))}
                                {data.can_manage && (
                                    <Button.Text size='xsmall' onClick={() => edit(automation)}>
                                        Edit
                                    </Button.Text>
                                )}
                                <Button.Text size='xsmall' onClick={() => setHistory(automation)}>
                                    History
                                </Button.Text>
                                {data.can_manage && (
                                    <Button.Danger size='xsmall' isSecondary onClick={() => setDeleting(automation)}>
                                        Delete
                                    </Button.Danger>
                                )}
                            </div>
                        ),
                    },
                ]}
            />
            {history && <HistoryDialog server={identifier} automation={history} onClose={() => setHistory(null)} />}
            <Dialog.Confirm open={deleting !== null} onClose={() => setDeleting(null)} title={`Delete ${deleting?.name}?`} confirm='Delete' pending={removal.isPending} onConfirmed={() => deleting && removal.mutate(deleting)}>
                Its history is deleted too. A run in progress stops.
            </Dialog.Confirm>
        </ServerContentBlock>
    );
}
