/**
 * A stable key from a display name: "Jurnal Penyesuaian Akhir" becomes `jurnal_penyesuaian_akhir`.
 * Keys are lowercase letters, digits and underscores, start with a letter, and stay within `max`
 * characters (the rule of a transaction type key). Accents are folded to their base letter.
 */
export function slugify(text: string, max = 64): string {
    const base = text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '');

    if (base === '') {
        return '';
    }

    const keyed = /^[a-z]/.test(base) ? base : `t_${base}`;

    return keyed.slice(0, max).replace(/_+$/, '');
}
