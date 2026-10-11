import type { ReactNode } from 'react';
import { Button } from '@pterodactyl/sdk';

/** A pressed/unpressed chip, for weekdays and countdown times. */
export default function Toggle({ pressed, label, onClick, children }: { pressed: boolean; label: string; onClick: () => void; children: ReactNode }) {
    return (
        <Button size='xsmall' color={pressed ? 'primary' : 'grey'} isSecondary={!pressed} aria-pressed={pressed} aria-label={label} title={label} className='auto:min-w-11' onClick={onClick}>
            {children}
        </Button>
    );
}
