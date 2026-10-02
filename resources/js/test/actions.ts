import { screen, within } from '@testing-library/react';
import type { UserEvent } from '@testing-library/user-event';

/** Opens the actions menu of a table row (named by its code or name) and picks one item. */
export async function rowAction(user: UserEvent, row: string, item: string) {
    await user.click(
        await screen.findByRole('button', { name: `Aksi untuk ${row}` }),
    );
    await user.click(await screen.findByRole('menuitem', { name: item }));
}

/** The confirmation that guards an action hard to undo. */
export async function confirmDialog() {
    return screen.findByRole('alertdialog');
}

/** Answers the confirmation with one of its buttons. */
export async function confirmWith(user: UserEvent, name: string) {
    await user.click(
        within(await confirmDialog()).getByRole('button', { name }),
    );
}
