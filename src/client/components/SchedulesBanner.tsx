import { Button, NamedIcon, useCurrentServerRequired, usePanelNavigate, useServerPermission } from '@pterodactyl/sdk';

/** Points from the core Schedules page to Automations. */
export default function SchedulesBanner() {
    const { identifier } = useCurrentServerRequired().attributes;
    const navigate = usePanelNavigate();

    if (!useServerPermission('ext.automations.read')) {
        return null;
    }

    return (
        <div className='auto:mb-4 auto:flex auto:flex-col auto:gap-3 auto:rounded-sm auto:border auto:border-border auto:bg-card auto:p-3 auto:sm:flex-row auto:sm:items-center'>
            <NamedIcon name='workflow' className='auto:hidden auto:h-5 auto:w-5 auto:shrink-0 auto:text-primary auto:sm:block' />
            <p className='auto:flex-1 auto:text-sm auto:text-foreground'>
                Need countdowns, conditions or Discord messages? <span className='auto:text-muted-foreground'>Automations do more than schedules, with time zones and a run history.</span>
            </p>
            <Button size='small' className='auto:shrink-0' onClick={() => navigate({ extension: 'automations', screen: 'automations', params: { id: identifier } })}>
                Open Automations
            </Button>
        </div>
    );
}
