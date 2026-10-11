import { useState } from 'react';
import { Input, Select } from '@pterodactyl/sdk';

const UNITS = [
    { value: 1, label: 'seconds' },
    { value: 60, label: 'minutes' },
    { value: 3600, label: 'hours' },
];

interface Props {
    id: string;
    seconds: number;
    onChange: (seconds: number) => void;
}

/** A number with a unit, stored as seconds. */
export default function DurationInput({ id, seconds, onChange }: Props) {
    const [unit, setUnit] = useState(seconds % 3600 === 0 ? 3600 : seconds % 60 === 0 ? 60 : 1);

    return (
        <div className='auto:grid auto:grid-cols-[minmax(0,1fr)_9rem] auto:gap-2'>
            <Input id={id} type='number' min={1} max={Math.floor(86400 / unit)} value={Math.round(seconds / unit)} onChange={(event) => onChange(Math.max(0, Math.round(Number(event.currentTarget.value))) * unit)} />
            <Select
                id={`${id}-unit`}
                value={unit}
                options={UNITS}
                onChange={(value) => {
                    setUnit(Number(value));
                    onChange(Math.max(1, Math.round(seconds / unit)) * Number(value));
                }}
            />
        </div>
    );
}
