/*
 * The slice of Laravel's LengthAwarePaginator JSON the pages read. The
 * numbered link array is omitted on purpose: the UI paginates with
 * previous/next only.
 */
export type Paginated<T> = {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
