export type DimensionTag = {
    /** Key of the dimension type; keeps the list stable. */
    key: string;
    /** Name of the dimension type, e.g. "Cabang". */
    label: string;
    /** The chosen value, e.g. "JKT · Jakarta". */
    value: string;
};

/**
 * The dimensions of a transaction line as small captions under it ("Cabang: JKT · Jakarta"). It only
 * lays out text already resolved by the screen, so it knows nothing about where dimensions come from.
 */
export function DimensionTags({ tags }: { tags: DimensionTag[] }) {
    if (tags.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
            {tags.map((tag) => (
                <li key={tag.key}>
                    {tag.label}:{' '}
                    <span className="font-medium">{tag.value}</span>
                </li>
            ))}
        </ul>
    );
}

/**
 * Tags for the dimensions of one line, from the names the screen loaded. While the names are not
 * there yet (or the user may not read them) a value shows as a placeholder rather than a raw id.
 */
export function dimensionTags(
    dimensions: Record<string, string>,
    names:
        | {
              types: Record<string, string>;
              values: Record<string, { code: string; name: string }>;
          }
        | undefined,
): DimensionTag[] {
    return Object.entries(dimensions).map(([key, valueId]) => {
        const value = names?.values[valueId];

        return {
            key,
            label: names?.types[key] ?? key,
            value: value ? `${value.code} · ${value.name}` : '…',
        };
    });
}
