import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import SchedulesBanner from './components/SchedulesBanner';

export default definePterodactylExtension({
    setup({ screens, slots }) {
        screens.register('automations', () => import('./screens/AutomationsScreen'));
        screens.register('editor', () => import('./screens/EditorScreen'));
        slots.register('server.schedules.before', SchedulesBanner);
    },
});
