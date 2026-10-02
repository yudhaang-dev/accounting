type Page<T> = { data: T[]; meta: { last_page: number } };

/**
 * Every row of a paginated list, fetched page by page until it is complete. For lists a screen holds
 * whole in the browser (a tree to indent, a picker to search): a few hundred rows, not millions.
 */
export async function fetchAll<T>(
    fetchPage: (page: number) => Promise<Page<T>>,
): Promise<T[]> {
    const rows: T[] = [];
    let page = 1;
    let lastPage = 1;

    do {
        const body = await fetchPage(page);

        rows.push(...body.data);
        lastPage = body.meta.last_page;
        page += 1;
    } while (page <= lastPage);

    return rows;
}
