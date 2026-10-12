import type { LucideIcon, LucideProps } from 'lucide-react';
import { DropdownMenu, NamedIcon } from '@pterodactyl/sdk';
import type { StepType } from '../api';
import { STEP_TYPES } from '../lib/steps';

// The menu items take icon components, so each step's icon name gets a small wrapper.
const ICONS = new Map(STEP_TYPES.map((entry) => [entry.type, ((props: LucideProps) => <NamedIcon name={entry.icon} {...props} />) as unknown as LucideIcon]));

interface Props {
    types: StepType[];
    /** Where the step goes, such as "1 › then", read out after "Add step". */
    target?: string;
    onAdd: (type: StepType) => void;
}

/** One "Add step" button with a menu of the step types. */
export default function AddStepMenu({ types, target, onAdd }: Props) {
    return (
        <DropdownMenu
            className='auto:max-h-(--available-height) auto:w-88 auto:max-w-[calc(100vw-2rem)] auto:overflow-y-auto'
            triggerClassName='auto:inline-flex auto:items-center auto:gap-1.5 auto:rounded-sm auto:border auto:border-border auto:bg-popover auto:px-2 auto:py-1 auto:text-xs auto:uppercase auto:tracking-wide auto:text-foreground auto:transition-colors auto:duration-150 auto:hover:border-secondary auto:hover:bg-secondary auto:data-[popup-open]:border-secondary auto:data-[popup-open]:bg-secondary'
            triggerContent={
                <>
                    <NamedIcon name='plus' className='auto:h-3.5 auto:w-3.5' />
                    Add step
                    {target && <span className='auto:sr-only'> to {target}</span>}
                </>
            }
        >
            {STEP_TYPES.filter((entry) => types.includes(entry.type)).map((entry) => (
                <DropdownMenu.Item key={entry.type} icon={ICONS.get(entry.type)} description={entry.description} onClick={() => onAdd(entry.type)}>
                    {entry.label}
                </DropdownMenu.Item>
            ))}
        </DropdownMenu>
    );
}
