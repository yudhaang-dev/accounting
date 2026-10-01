import { useCallback, useEffect, useState } from 'react';

/**
 * Counts down whole seconds towards a deadline, for "try again in 42s" and resend cooldowns.
 * It is deadline-based, so a throttled background tab catches up instead of drifting.
 */
export function useCountdown() {
    const [deadline, setDeadline] = useState<number | null>(null);
    const [remaining, setRemaining] = useState(0);

    const start = useCallback((seconds: number) => {
        const next = Date.now() + seconds * 1000;

        setDeadline(next);
        setRemaining(Math.ceil(seconds));
    }, []);

    useEffect(() => {
        if (deadline === null) {
            return;
        }

        const tick = () => {
            const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));

            setRemaining(left);

            if (left === 0) {
                setDeadline(null);
            }
        };

        const timer = window.setInterval(tick, 250);

        return () => window.clearInterval(timer);
    }, [deadline]);

    return { remaining, active: remaining > 0, start };
}
