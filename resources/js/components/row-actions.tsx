import { EllipsisIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * The actions of one table row, in a menu instead of a row of buttons: a row with several actions
 * would otherwise take the width of the table. Fill it with `DropdownMenuItem`s; an item that is hard
 * to undo should open a `ConfirmDialog` rather than act at once.
 */
export function RowActions({
    label,
    children,
}: {
    /** Names the row for assistive technology, e.g. "Actions for 1111". */
    label: string;
    children: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('common.actionsFor', { name: label })}
                    />
                }
            >
                <EllipsisIcon aria-hidden />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-44">
                <DropdownMenuGroup>{children}</DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
