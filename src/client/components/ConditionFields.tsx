import { Input, Label, Select } from '@pterodactyl/sdk';
import type { Comparison, Condition, StateName } from '../api';
import { conditionKinds, newCondition } from '../lib/steps';

const STATES: { value: StateName; label: string }[] = [
    { value: 'running', label: 'Running' },
    { value: 'offline', label: 'Offline' },
    { value: 'starting', label: 'Starting' },
    { value: 'stopping', label: 'Stopping' },
];

const COMPARISONS: { value: Comparison; label: string }[] = [
    { value: 'above', label: 'above' },
    { value: 'below', label: 'below' },
];

interface Props {
    id: string;
    condition: Condition;
    players: boolean;
    onChange: (condition: Condition) => void;
}

export default function ConditionFields({ id, condition, players, onChange }: Props) {
    return (
        <div className='auto:grid auto:gap-2 auto:sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]'>
            <div>
                <Label htmlFor={`${id}-kind`}>If</Label>
                <Select id={`${id}-kind`} value={condition.kind} options={conditionKinds(players || condition.kind === 'players')} onChange={(value) => onChange(newCondition(value as Condition['kind']))} />
            </div>
            {condition.kind === 'state' ? (
                <div>
                    <Label htmlFor={`${id}-state`}>State</Label>
                    <Select id={`${id}-state`} value={condition.state} options={STATES} onChange={(value) => onChange({ kind: 'state', state: value as StateName })} />
                </div>
            ) : (
                <div className='auto:grid auto:grid-cols-[7rem_minmax(0,1fr)] auto:gap-2'>
                    <div>
                        <Label htmlFor={`${id}-op`}>Is</Label>
                        <Select id={`${id}-op`} value={condition.op} options={COMPARISONS} onChange={(value) => onChange({ ...condition, op: value as Comparison })} />
                    </div>
                    <div>
                        <Label htmlFor={`${id}-value`}>{condition.kind === 'players' ? 'Players' : 'Percent of the limit'}</Label>
                        <Input id={`${id}-value`} type='number' min={0} max={condition.kind === 'players' ? 100000 : condition.kind === 'cpu' ? 1000 : 100} value={condition.value} onChange={(event) => onChange({ ...condition, value: Number(event.currentTarget.value) })} />
                    </div>
                </div>
            )}
        </div>
    );
}
