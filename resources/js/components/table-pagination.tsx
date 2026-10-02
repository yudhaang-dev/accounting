import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ALL, PAGE_SIZES } from '@/lib/page-size';

type Props = {
    total: number;
    /** 1-based. */
    page: number;
    /** Rows per page; `ALL` (0) shows every row. */
    pageSize: number;
    onPageChange: (page: number) => void;
    /** The parent should go back to page 1 when the size changes. */
    onPageSizeChange: (size: number) => void;
    /** Offer "All": only for a list that holds all its rows in the browser. */
    allowAll?: boolean;
};

/**
 * The footer of every list: which rows are shown out of how many, how many to show per page, and
 * the move between pages. One component, so every table in the app pages the same way.
 */
export function TablePagination({
    total,
    page,
    pageSize,
    onPageChange,
    onPageSizeChange,
    allowAll = false,
}: Props) {
    const { t } = useTranslation();
    const labelId = useId();
    const all = pageSize === ALL;
    const lastPage = all ? 1 : Math.max(1, Math.ceil(total / pageSize));
    const from = total === 0 ? 0 : all ? 1 : (page - 1) * pageSize + 1;
    const to = all ? total : Math.min(total, page * pageSize);
    const sizes = [
        ...PAGE_SIZES.map((size) => ({
            value: String(size),
            label: String(size),
        })),
        ...(allowAll ? [{ value: String(ALL), label: t('pager.all') }] : []),
    ];

    return (
        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 text-sm text-muted-foreground">
            <span aria-live="polite">
                {total === 0
                    ? t('pager.none')
                    : t('pager.range', { from, to, total })}
            </span>

            <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div className="flex items-center gap-2">
                    <span id={labelId}>{t('pager.rows')}</span>
                    <Select
                        value={String(pageSize)}
                        items={sizes}
                        onValueChange={(value) =>
                            onPageSizeChange(Number(value))
                        }
                    >
                        <SelectTrigger
                            size="sm"
                            className="w-20"
                            aria-labelledby={labelId}
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {sizes.map((size) => (
                                    <SelectItem
                                        key={size.value}
                                        value={size.value}
                                    >
                                        {size.label}
                                    </SelectItem>
                                ))}
                            </SelectGroup>
                        </SelectContent>
                    </Select>
                </div>

                {lastPage > 1 && (
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={page <= 1}
                            onClick={() => onPageChange(page - 1)}
                        >
                            {t('pager.previous')}
                        </Button>
                        <span>{t('pager.page', { page, last: lastPage })}</span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={page >= lastPage}
                            onClick={() => onPageChange(page + 1)}
                        >
                            {t('pager.next')}
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}
