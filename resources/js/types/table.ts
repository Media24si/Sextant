/**
 * The server-side table contract (SPEC §13.5): what a list page's controller
 * returns for DataTable. `rows` is a paginated laravel-data collection.
 */
export type TableRows<TRow> = {
    data: TRow[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

export type TableProps<TRow> = {
    rows: TableRows<TRow>;
    filters: Record<string, string>;
    sort: string | null;
};

/** One filter chip; `value: null` clears the filter (the "All" chip). */
export type TableFilterChip = {
    label: string;
    value: string | null;
    count?: number;
};
