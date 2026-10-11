import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    Button,
    Input,
    Label,
    NamedIcon,
    PanelLink,
    ServerContentBlock,
    Spinner,
    Switch,
    TitledGreyBox,
    httpErrorToHuman,
    toast,
    useCurrentServerRequired,
    usePanelNavigate,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import { getAutomations, keys, saveAutomation, type Automation, type AutomationInput } from '../api';
import StepList from '../components/StepList';
import TriggerFields, { browserTimeZone } from '../components/TriggerFields';
import { countSteps } from '../lib/steps';

const MAX_STEPS = 50;

interface EditorProps {
    server: string;
    automation: Automation | null;
    players: boolean;
}

function Editor({ server, automation, players }: EditorProps) {
    const navigate = usePanelNavigate();
    const queryClient = useQueryClient();
    const [input, setInput] = useState<AutomationInput>(() =>
        automation
            ? { name: automation.name, enabled: automation.enabled, skip_offline: automation.skip_offline, trigger: automation.trigger, steps: automation.steps }
            : { name: '', enabled: true, skip_offline: false, trigger: { type: 'daily', time: '04:00', timezone: browserTimeZone() }, steps: [] }
    );
    const set = (changes: Partial<AutomationInput>) => setInput((current) => ({ ...current, ...changes }));
    const back = () => navigate({ extension: 'automations', screen: 'automations', params: { id: server } });
    const count = countSteps(input.steps);

    const saving = useMutation({
        mutationFn: () => saveAutomation(server, { ...input, name: input.name.trim() }, automation?.id),
        onSuccess: (result) => {
            queryClient.setQueryData(keys.list(server), result);
            toast.success(`${input.name.trim()} saved.`);
            void back();
        },
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <>
            <div className='auto:mb-4 auto:flex auto:flex-col auto:gap-2 auto:sm:flex-row auto:sm:items-center auto:sm:justify-between'>
                <div className='auto:min-w-0'>
                    <PanelLink destination={{ extension: 'automations', screen: 'automations', params: { id: server } }} className='auto:inline-flex auto:items-center auto:gap-1 auto:text-xs auto:text-muted-foreground auto:hover:text-foreground'>
                        <NamedIcon name='arrow-left' className='auto:h-3.5 auto:w-3.5' />
                        Automations
                    </PanelLink>
                    <h2 className='auto:truncate auto:text-2xl auto:font-medium auto:text-foreground'>{automation ? automation.name : 'New automation'}</h2>
                </div>
            </div>
            <div className='auto:grid auto:gap-6 auto:lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]'>
                <TitledGreyBox title={`Steps${count ? ` (${count}/${MAX_STEPS})` : ''}`}>
                    {input.steps.length === 0 && <p className='auto:mb-3 auto:text-sm auto:text-muted-foreground'>Steps run from top to bottom. Start with a countdown, a command or a condition.</p>}
                    <StepList steps={input.steps} onChange={(steps) => set({ steps })} players={players} />
                </TitledGreyBox>
                <div className='auto:grid auto:content-start auto:gap-6'>
                    <TitledGreyBox title='Trigger'>
                        <TriggerFields server={server} trigger={input.trigger} onChange={(trigger) => set({ trigger })} />
                    </TitledGreyBox>
                    <TitledGreyBox title='Settings'>
                        <div className='auto:grid auto:gap-4'>
                            <div>
                                <Label htmlFor='auto-name'>Name</Label>
                                <Input id='auto-name' maxLength={100} placeholder='Nightly restart' value={input.name} onChange={(event) => set({ name: event.currentTarget.value })} />
                            </div>
                            <Switch id='auto-enabled' label='Enabled' description='Runs on its trigger. Run now works either way.' checked={input.enabled} onChange={(enabled) => set({ enabled })} />
                            <Switch id='auto-skip-offline' label='Skip if the server is offline' description='The run is recorded as skipped instead.' checked={input.skip_offline} onChange={(skip_offline) => set({ skip_offline })} />
                        </div>
                    </TitledGreyBox>
                </div>
            </div>
            <div className='auto:mt-6 auto:flex auto:flex-col-reverse auto:gap-2 auto:sm:flex-row auto:sm:justify-end'>
                <Button.Text disabled={saving.isPending} onClick={() => void back()}>
                    Cancel
                </Button.Text>
                <Button disabled={input.name.trim() === '' || input.steps.length === 0 || count > MAX_STEPS} isLoading={saving.isPending} onClick={() => saving.mutate()}>
                    {automation ? 'Save changes' : 'Create automation'}
                </Button>
            </div>
        </>
    );
}

export default function EditorScreen({ data }: ScreenComponentProps) {
    const { identifier, egg_features } = useCurrentServerRequired().attributes;
    const list = useQuery({ queryKey: keys.list(identifier), queryFn: () => getAutomations(identifier) });
    const id = data.params.automationId ?? 'new';
    const automation = id === 'new' ? null : (list.data?.automations.find((entry) => String(entry.id) === id) ?? null);
    const players = (egg_features ?? []).some((feature) => feature.toLowerCase() === 'eula');

    return (
        <ServerContentBlock title='Automations'>
            {!list.data ? (
                list.isError ? <p className='auto:text-sm auto:text-destructive'>{httpErrorToHuman(list.error)}</p> : <Spinner size={Spinner.Size.LARGE} centered />
            ) : id !== 'new' && !automation ? (
                <p className='auto:text-sm auto:text-muted-foreground'>
                    This automation no longer exists.{' '}
                    <PanelLink destination={{ extension: 'automations', screen: 'automations', params: { id: identifier } }} className='auto:text-foreground auto:underline'>
                        Back to Automations
                    </PanelLink>
                </p>
            ) : (
                <Editor key={id} server={identifier} automation={automation} players={players} />
            )}
        </ServerContentBlock>
    );
}
