/**
 * One price line for any listing, either type. Sale listings show the
 * asking price; charter listings have no asking price by design — their
 * pricing is the charter rate block, so the line falls back to the rate
 * range. The range sticks to one currency (the first rate's — mixed
 * summer/winter currencies are real and must not be blended into one
 * mislabelled span) and one rate type, so a weekly range is never
 * labelled from daily rates.
 */

export type CharterRate = {
    season?: string | null;
    rate_type?: string | null;
    amount_min?: string | null;
    amount_max?: string | null;
    currency?: string | null;
};

const money = (amount: number, currency: string): string =>
    new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(amount);

export function formatRateRange(
    rates: CharterRate[] | null | undefined,
): string | null {
    if (!rates || rates.length === 0) {
        return null;
    }

    const currency = rates.find((rate) => rate.currency)?.currency;

    if (!currency) {
        return null;
    }

    const comparable = rates.filter((rate) => rate.currency === currency);
    const rateType = comparable[0]?.rate_type === 'daily' ? 'daily' : 'weekly';
    const scoped = comparable.filter(
        (rate) => (rate.rate_type ?? 'weekly') === rateType,
    );

    const mins = scoped
        .map((rate) => Number(rate.amount_min))
        .filter((value) => Number.isFinite(value) && value > 0);
    const maxs = scoped
        .map((rate) => Number(rate.amount_max))
        .filter((value) => Number.isFinite(value) && value > 0);

    if (mins.length === 0 && maxs.length === 0) {
        return null;
    }

    const low = Math.min(...(mins.length > 0 ? mins : maxs));
    const high = Math.max(...(maxs.length > 0 ? maxs : mins));
    const suffix = rateType === 'daily' ? '/ day' : '/ week';
    const range =
        high > low
            ? `${money(low, currency)}–${money(high, currency)}`
            : money(low, currency);

    return `${range} ${suffix}`;
}

export function formatListingPrice(options: {
    amount?: string | null;
    currency?: string | null;
    rates?: CharterRate[] | null;
    fallback?: string;
}): string {
    if (options.amount && options.currency) {
        return money(Number(options.amount), options.currency);
    }

    const range = formatRateRange(options.rates);

    if (range !== null) {
        return range;
    }

    return options.fallback ?? 'Price on application';
}
