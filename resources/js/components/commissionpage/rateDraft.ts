import { localizeDigits } from '../../business-date';
import { SUB_DEALER_ONLY, fromBasisPoints, shareForBikePart, toBasisPoints, type Head, type PageTotals } from './shared';

/** What a person has typed for one commission: its percent and, when it is split, each part. */
export interface RowDraft {
    pct: string;
    parts: Record<string, string>;
    /** Where the sub-dealer extra part goes; empty for every other commission. */
    destination: string;
}

export type Drafts = Record<string, RowDraft>;

export const MONEY_TEXT = /^\d{1,11}(\.\d{1,2})?$/;

export interface Problem {
    key: string;
    replace?: Record<string, string>;
}

/** One draft per commission, started from the values of these heads. */
export const startDrafts = (heads: Head[]): Drafts => Object.fromEntries(heads.map((head) => [
    head.key,
    { pct: head.percentage, parts: Object.fromEntries(head.children.map((part) => [part.key, part.pct])), destination: head.destination ?? '' },
]));

/** Everything wrong with one row, as plain-language keys the screen turns into words. */
export function rowProblems(head: Head, draft: RowDraft): Problem[] {
    const total = toBasisPoints(draft.pct);
    if (total === null) {
        return [{ key: 'err_percent' }];
    }
    if (head.children.some((part) => part.unit === 'money' && !MONEY_TEXT.test((draft.parts[part.key] ?? '').trim()))) {
        return [{ key: 'err_number' }];
    }
    const percentParts = head.children.filter((part) => part.unit === 'percent');
    if (percentParts.length === 0) {
        return [];
    }
    const parts = percentParts.map((part) => toBasisPoints(part.param === null ? '0' : draft.parts[part.key] ?? ''));
    if (parts.some((value) => value === null)) {
        return [{ key: 'err_percent' }];
    }
    if (head.key === 'car_bike_incentive') {
        const bike = parts[0] as number;
        if (bike > total) {
            return [{ key: 'err_bike_more' }];
        }

        return shareForBikePart(total, bike) === null ? [{ key: 'err_bike_split' }] : [];
    }
    const sum = (parts as number[]).reduce((all, value) => all + value, 0);

    return sum === total ? [] : [{ key: 'err_parts_sum', replace: { sum: localizeDigits(String(parseFloat(fromBasisPoints(sum)))), total: localizeDigits(String(parseFloat(fromBasisPoints(total)))) } }];
}

/**
 * What the draft of one commission changes compared with a baseline (the live rule for the rules window, the general
 * rate for a special rate). An empty object means nothing changed.
 */
export function ruleEntry(head: Head, baseline: Head, draft: RowDraft, withDestination: boolean): Record<string, unknown> {
    const total = toBasisPoints(draft.pct);
    if (total === null) {
        return {};
    }
    const entry: Record<string, unknown> = {};
    if (total !== toBasisPoints(baseline.percentage)) {
        entry.percentage = fromBasisPoints(total);
    }
    const parameters: Record<string, string> = {};
    head.children.forEach((part, index) => {
        if (part.param === null) {
            return;
        }
        const base = baseline.children[index] ?? part;
        if (part.unit === 'money') {
            const typed = (draft.parts[part.key] ?? '').trim();
            if (Number(typed) !== Number(base.pct)) {
                parameters[part.param] = typed;
            }

            return;
        }
        if (head.key === 'car_bike_incentive') {
            // The saved value is the bike's share of the fund, not the bike's percent of the order.
            const bike = toBasisPoints(draft.parts.bike) ?? 0;
            const share = shareForBikePart(total, bike);
            if (share !== null && (bike !== toBasisPoints(base.pct) || total !== toBasisPoints(baseline.percentage))) {
                parameters[part.param] = fromBasisPoints(share);
            }

            return;
        }
        const next = toBasisPoints(draft.parts[part.key]) ?? 0;
        if (next !== toBasisPoints(base.pct)) {
            parameters[part.param] = fromBasisPoints(next);
        }
    });
    if (withDestination && head.key === 'additional_rank' && draft.destination !== (baseline.destination ?? '')) {
        parameters.destination = draft.destination;
    }
    if (Object.keys(parameters).length > 0) {
        entry.parameters = parameters;
    }

    return entry;
}

/** The totals of the typed percents against the two pool limits, as the engine counts them (a switched-off commission counts as zero). */
export function poolTotals(heads: Head[], drafts: Drafts, totals: PageTotals): { dealerTotal: number; subTotal: number; dealerCap: number; subCap: number; over: boolean } {
    const sum = (keep: (head: Head) => boolean) => heads.filter(keep).reduce((all, head) => all + (head.is_active ? toBasisPoints(drafts[head.key].pct) ?? 0 : 0), 0);
    const dealerTotal = sum((head) => !SUB_DEALER_ONLY.includes(head.key));
    const subTotal = sum(() => true);
    const dealerCap = Math.round(parseFloat(totals.dealer_cap_pct) * 100);
    const subCap = Math.round(parseFloat(totals.sub_dealer_cap_pct) * 100);

    return { dealerTotal, subTotal, dealerCap, subCap, over: dealerTotal > dealerCap || subTotal > subCap };
}
