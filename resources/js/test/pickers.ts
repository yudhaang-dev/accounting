import { screen, within } from '@testing-library/react';
import type { UserEvent } from '@testing-library/user-event';

/** Opens the date picker's popover and returns it. */
async function open(
    user: UserEvent,
    trigger: HTMLElement,
): Promise<HTMLElement> {
    await user.click(trigger);

    return screen.findByRole('dialog');
}

/** Picks today from the popover's own shortcut. */
export async function pickToday(user: UserEvent, trigger: HTMLElement) {
    const popover = await open(user, trigger);

    await user.click(within(popover).getByRole('button', { name: 'Hari ini' }));
}

/**
 * Picks a specific `YYYY-MM-DD` the way a person would: jump to its year and month from the caption,
 * then press the day. (The picker starts on the month of the current value or today.)
 */
export async function pickDate(
    user: UserEvent,
    trigger: HTMLElement,
    iso: string,
) {
    const popover = await open(user, trigger);
    const [year, month, day] = iso.split('-').map(Number) as [
        number,
        number,
        number,
    ];
    // The caption's two dropdowns are native selects: month first, then year.
    const [monthSelect, yearSelect] = within(popover).getAllByRole('combobox');

    await user.selectOptions(yearSelect as HTMLElement, String(year));
    await user.selectOptions(monthSelect as HTMLElement, String(month - 1));

    const cell = popover.querySelector<HTMLElement>(
        `[data-day="${new Date(year, month - 1, day).toLocaleDateString('id')}"]`,
    );

    if (!cell) {
        throw new Error(`No day ${iso} in the calendar`);
    }

    await user.click(cell);
}

/** Today as `YYYY-MM-DD` in the test's zone. */
export function todayIso(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}
