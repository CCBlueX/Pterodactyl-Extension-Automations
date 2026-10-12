import { Button, NamedIcon, useCurrentServerRequired, usePanelNavigate, useServerPermission } from '@pterodactyl/sdk';

/** Points from the core Schedules page to Automations. */
export default function SchedulesBanner() {
    const { identifier } = useCurrentServerRequired().attributes;
    const navigate = usePanelNavigate();

    if (!useServerPermission('ext.automations.read')) {
        return null;
    }

    return (
        // The slot sits outside the page container, so the banner lines up with the content itself.
        <div className='auto:mx-4 auto:mt-4 auto:flex auto:max-w-(--layout-content-width) auto:flex-col auto:gap-3 auto:rounded-sm auto:border auto:border-border auto:bg-card auto:p-3 auto:sm:mt-6 auto:sm:flex-row auto:sm:items-center auto:xl:mx-auto'>
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
