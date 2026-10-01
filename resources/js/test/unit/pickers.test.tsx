import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { DatePicker } from '@/components/date-picker';
import { MoneyInput } from '@/components/money-input';
import { SearchableSelect } from '@/components/searchable-select';
import { pickDate, pickToday, todayIso } from '@/test/pickers';

function Date_({ initial = '', clearable = false }) {
    const [value, setValue] = useState(initial);

    return (
        <>
            <span data-testid="value">{value || 'none'}</span>
            <DatePicker
                id="d"
                value={value}
                onChange={setValue}
                clearable={clearable}
                aria-label="Tanggal uji"
            />
        </>
    );
}

describe('date picker', () => {
    it('asks for a date until one is chosen, then writes it out', () => {
        const { unmount } = render(<Date_ />);

        expect(
            screen.getByRole('button', { name: 'Tanggal uji' }),
        ).toHaveTextContent('Pilih tanggal');
        unmount();
        render(<Date_ initial="2026-10-02" />);
        expect(
            screen.getByRole('button', { name: 'Tanggal uji' }),
        ).toHaveTextContent('2 Oktober 2026');
    });

    it('chooses a day from the calendar and closes', async () => {
        const typist = userEvent.setup();

        render(<Date_ />);
        await pickDate(
            typist,
            screen.getByRole('button', { name: 'Tanggal uji' }),
            '2026-03-15',
        );

        expect(screen.getByTestId('value')).toHaveTextContent('2026-03-15');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('jumps to today', async () => {
        const typist = userEvent.setup();

        render(<Date_ />);
        await pickToday(
            typist,
            screen.getByRole('button', { name: 'Tanggal uji' }),
        );

        expect(screen.getByTestId('value')).toHaveTextContent(todayIso());
    });

    it('can be cleared only when the field may stay empty', async () => {
        const typist = userEvent.setup();
        const { unmount } = render(<Date_ initial="2026-10-02" clearable />);

        await typist.click(screen.getByRole('button', { name: /Tanggal uji/ }));
        await typist.click(
            within(await screen.findByRole('dialog')).getByRole('button', {
                name: 'Hapus',
            }),
        );
        expect(screen.getByTestId('value')).toHaveTextContent('none');
        unmount();

        render(<Date_ initial="2026-10-02" />);
        await typist.click(screen.getByRole('button', { name: /Tanggal uji/ }));
        expect(
            within(await screen.findByRole('dialog')).queryByRole('button', {
                name: 'Hapus',
            }),
        ).not.toBeInTheDocument();
    });

    it('opens on the month of the current value, not on today', async () => {
        const typist = userEvent.setup();

        render(<Date_ initial="2024-02-29" />);
        await typist.click(screen.getByRole('button', { name: /Tanggal uji/ }));

        const popover = await screen.findByRole('dialog');

        expect(popover.querySelector('[data-day="29/2/2024"]')).not.toBeNull();
    });
});

function Money_({ initial = '', onValue = vi.fn() }) {
    const [value, setValue] = useState(initial);

    return (
        <>
            <MoneyInput
                aria-label="Jumlah"
                value={value}
                onChange={(next) => {
                    setValue(next);
                    onValue(next);
                }}
            />
            <button type="button" onClick={() => setValue('2500.75')}>
                isi
            </button>
            <button type="button" onClick={() => setValue('')}>
                kosongkan
            </button>
        </>
    );
}

describe('money input', () => {
    it('groups thousands as digits are typed and reports the plain decimal', async () => {
        const typist = userEvent.setup();
        const onValue = vi.fn();

        render(<Money_ onValue={onValue} />);
        await typist.type(screen.getByLabelText('Jumlah'), '1500000,50');

        expect(screen.getByLabelText('Jumlah')).toHaveValue('1.500.000,50');
        expect(onValue).toHaveBeenLastCalledWith('1500000.50');
    });

    it('keeps a trailing decimal separator while the user is still typing', async () => {
        const typist = userEvent.setup();

        render(<Money_ />);
        await typist.type(screen.getByLabelText('Jumlah'), '12,');

        expect(screen.getByLabelText('Jumlah')).toHaveValue('12,');
    });

    it('ignores letters and signs', async () => {
        const typist = userEvent.setup();
        const onValue = vi.fn();

        render(<Money_ onValue={onValue} />);
        await typist.type(screen.getByLabelText('Jumlah'), 'Rp -1a2');

        expect(screen.getByLabelText('Jumlah')).toHaveValue('12');
        expect(onValue).toHaveBeenLastCalledWith('12');
    });

    it('shows a value set from outside grouped, and empties when cleared', async () => {
        const typist = userEvent.setup();

        render(<Money_ initial="1500000.50" />);
        expect(screen.getByLabelText('Jumlah')).toHaveValue('1.500.000,50');

        await typist.click(screen.getByRole('button', { name: 'isi' }));
        expect(screen.getByLabelText('Jumlah')).toHaveValue('2.500,75');

        await typist.click(screen.getByRole('button', { name: 'kosongkan' }));
        expect(screen.getByLabelText('Jumlah')).toHaveValue('');
    });

    it('keeps the caret where the digit was typed, past a new separator', async () => {
        const typist = userEvent.setup();

        render(<Money_ initial="999" />);
        const input = screen.getByLabelText('Jumlah') as HTMLInputElement;

        await typist.type(input, '9', {
            initialSelectionStart: 3,
            initialSelectionEnd: 3,
        });

        expect(input).toHaveValue('9.999');
        expect(input.selectionStart).toBe(5);

        // In the middle: 1.234 -> insert 5 after the 2 -> 12.534, caret after the 5.
        await typist.clear(input);
        await typist.type(input, '1234');
        await typist.type(input, '5', {
            initialSelectionStart: 3,
            initialSelectionEnd: 3,
        });

        expect(input).toHaveValue('12.534');
        expect(input.selectionStart).toBe(4);
    });
});

const accounts = [
    { value: 'a', label: '1111 · Kas Kecil' },
    { value: 'b', label: '1112 · Bank Operasional' },
    { value: 'c', label: '4100 · Penjualan' },
];

function Select_({ clearable = false }) {
    const [value, setValue] = useState('');

    return (
        <>
            <span data-testid="value">{value || 'none'}</span>
            <SearchableSelect
                items={accounts}
                value={value}
                onValueChange={setValue}
                aria-label="Akun"
                placeholder="Pilih akun"
                emptyText="Tidak ada yang cocok."
                clearable={clearable}
            />
        </>
    );
}

describe('searchable select', () => {
    it('narrows the list to what was typed, by code or by name', async () => {
        const typist = userEvent.setup();

        render(<Select_ />);
        await typist.type(
            screen.getByRole('combobox', { name: 'Akun' }),
            'bank',
        );
        expect(
            await screen.findByRole('option', {
                name: '1112 · Bank Operasional',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: '1111 · Kas Kecil' }),
        ).not.toBeInTheDocument();

        await typist.clear(screen.getByRole('combobox', { name: 'Akun' }));
        await typist.type(screen.getByRole('combobox', { name: 'Akun' }), '41');
        expect(
            await screen.findByRole('option', { name: '4100 · Penjualan' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: '1112 · Bank Operasional' }),
        ).not.toBeInTheDocument();
    });

    it('says so when nothing matches', async () => {
        const typist = userEvent.setup();

        render(<Select_ />);
        await typist.type(
            screen.getByRole('combobox', { name: 'Akun' }),
            'zzz',
        );

        expect(
            await screen.findByText('Tidak ada yang cocok.'),
        ).toBeInTheDocument();
    });

    it('reports the chosen value', async () => {
        const typist = userEvent.setup();

        render(<Select_ />);
        await typist.type(
            screen.getByRole('combobox', { name: 'Akun' }),
            'kas',
        );
        await typist.click(
            await screen.findByRole('option', { name: '1111 · Kas Kecil' }),
        );

        expect(screen.getByTestId('value')).toHaveTextContent('a');
        expect(screen.getByRole('combobox', { name: 'Akun' })).toHaveValue(
            '1111 · Kas Kecil',
        );
    });

    it('can clear its choice only when the field may stay empty', async () => {
        const typist = userEvent.setup();

        render(<Select_ clearable />);
        await typist.type(
            screen.getByRole('combobox', { name: 'Akun' }),
            'kas',
        );
        await typist.click(
            await screen.findByRole('option', { name: '1111 · Kas Kecil' }),
        );
        await typist.click(screen.getByRole('button', { name: 'Hapus' }));

        expect(screen.getByTestId('value')).toHaveTextContent('none');
    });
});
