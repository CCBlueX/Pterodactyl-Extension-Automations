import { Button, NamedIcon } from '@pterodactyl/sdk';
import type { Step } from '../api';
import { MAX_DEPTH, STEP_TYPES, move, newStep, removeAt, replaceAt, stepType } from '../lib/steps';
import AddStepMenu from './AddStepMenu';
import ConditionFields from './ConditionFields';
import StepFields from './StepFields';

interface Props {
    steps: Step[];
    onChange: (steps: Step[]) => void;
    players: boolean;
    /** Position of the parent if/else and the branch, such as "2 › then", or empty at the top. */
    parent?: string;
    depth?: number;
}

/** An ordered list of steps; if/else steps hold two more of these. */
export default function StepList({ steps, onChange, players, parent = '', depth = 1 }: Props) {
    const position = (index: number) => (parent ? `${parent} ${index + 1}` : String(index + 1));
    const idOf = (index: number) => `auto-step-${position(index).replace(/[^a-z0-9]+/gi, '-')}`;

    return (
        <div className='auto:grid auto:gap-3'>
            {steps.length > 0 && (
                <ol className='auto:grid auto:gap-3'>
                    {steps.map((step, index) => {
                        const meta = stepType(step.type);
                        const label = `step ${position(index)}`;
                        const set = (changed: Step) => onChange(replaceAt(steps, index, changed));

                        return (
                            <li key={index} className='auto:rounded-sm auto:border auto:border-border auto:bg-sunken auto:p-3'>
                                <div className='auto:flex auto:items-center auto:gap-2'>
                                    <span className='auto:flex auto:h-6 auto:min-w-6 auto:items-center auto:justify-center auto:rounded-sm auto:bg-card auto:px-1 auto:text-xs auto:text-muted-foreground'>{index + 1}</span>
                                    <NamedIcon name={meta.icon} className='auto:h-4 auto:w-4 auto:shrink-0 auto:text-muted-foreground' />
                                    <span className='auto:truncate auto:text-sm auto:font-medium auto:text-foreground'>{meta.label}</span>
                                    <div className='auto:ml-auto auto:flex auto:shrink-0 auto:gap-1'>
                                        <Button.Text size='xsmall' aria-label={`Move ${label} up`} title='Move up' disabled={index === 0} onClick={() => onChange(move(steps, index, -1))}>
                                            <NamedIcon name='arrow-up' className='auto:h-3.5 auto:w-3.5' />
                                        </Button.Text>
                                        <Button.Text size='xsmall' aria-label={`Move ${label} down`} title='Move down' disabled={index === steps.length - 1} onClick={() => onChange(move(steps, index, 1))}>
                                            <NamedIcon name='arrow-down' className='auto:h-3.5 auto:w-3.5' />
                                        </Button.Text>
                                        <Button.Danger size='xsmall' isSecondary aria-label={`Remove ${label}`} title='Remove' onClick={() => onChange(removeAt(steps, index))}>
                                            <NamedIcon name='trash-2' className='auto:h-3.5 auto:w-3.5' />
                                        </Button.Danger>
                                    </div>
                                </div>
                                <div className='auto:mt-3 auto:grid auto:gap-3'>
                                    {step.type === 'if' ? (
                                        <>
                                            <ConditionFields id={idOf(index)} condition={step.condition} players={players} onChange={(condition) => set({ ...step, condition })} />
                                            {(['then', 'else'] as const).map((branch) => (
                                                <div key={branch} className='auto:border-l-2 auto:border-border auto:pl-3'>
                                                    <p className='auto:mb-2 auto:text-xs auto:font-medium auto:uppercase auto:tracking-wide auto:text-muted-foreground'>{branch === 'then' ? 'Then' : 'Otherwise'}</p>
                                                    <StepList
                                                        steps={step[branch]}
                                                        onChange={(nested) => set({ ...step, [branch]: nested })}
                                                        players={players}
                                                        parent={`${position(index)} › ${branch === 'then' ? 'then' : 'otherwise'}`}
                                                        depth={depth + 1}
                                                    />
                                                </div>
                                            ))}
                                        </>
                                    ) : (
                                        <StepFields id={idOf(index)} step={step} onChange={set} />
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ol>
            )}
            <div className='auto:flex auto:flex-wrap auto:items-center auto:gap-2'>
                {steps.length === 0 && parent && <span className='auto:text-xs auto:text-muted-foreground'>Nothing yet.</span>}
                <AddStepMenu types={STEP_TYPES.map((entry) => entry.type).filter((type) => type !== 'if' || depth < MAX_DEPTH)} target={parent || undefined} onAdd={(type) => onChange([...steps, newStep(type)])} />
            </div>
        </div>
    );
}
