import { localizeDigits } from '../../business-date';

/** One part of a commission that is split (for example the area share of one level). */
export interface HeadPart {
    key: string;
    /** The rule parameter that holds the part's value; null when the part is worked out from the others. */
    param: string | null;
    /** A percent of the purchase, or a prize amount in taka (rank prizes), which is not a split of the head's percent. */
    unit: 'percent' | 'money';
    /** The percent, or the taka when unit is money. */
    pct: string;
}

/** One commission as the engine holds it (CommissionPageModel::heads). */
export interface Head {
    key: string;
    kind: string;
    percentage: string;
    dealer_pct: string | null;
    sub_dealer_pct: string;
    applies_to: BuyerType[];
    is_active: boolean;
    version: number;
    children: HeadPart[];
    children_total: string | null;
    children_match: boolean;
    /** Shared with the dealer, or paid only on a sub-dealer's purchase ("সাব-ডিলার আলাদা"). */
    block: 'shared' | 'sub_only';
    /** A sub-dealer's purchase calls this commission by another name. */
    sub_name: boolean;
    /** Where the sub-dealer extra part goes (additional_rank only). */
    destination: 'rank_fund' | 'extra_fund' | null;
}

export type BuyerType = 'dealer' | 'sub_dealer';

export interface ConditionNumber {
    param: string;
    unit: 'money' | 'count' | 'percent' | 'days' | 'months';
    controls: 'own' | 'team' | 'each' | 'area' | 'team_count' | 'team_depth' | 'reward' | 'time';
    value: string | null;
}

export interface Condition {
    id: string;
    rule: string;
    applies_to: BuyerType[];
    version: number;
    facts: { destination?: 'rank_fund' | 'extra_fund' };
    numbers: ConditionNumber[];
}

export interface PageTotals {
    dealer_pct: string;
    sub_dealer_pct: string;
    dealer_cap_pct: string;
    sub_dealer_cap_pct: string;
    company_mrp_pct: string;
}

export interface PageWorkflow {
    can_edit: boolean;
    applies_at_once: boolean;
    drafts_waiting: number;
}

export interface CommissionPageData {
    heads: Head[];
    totals: PageTotals;
    conditions: Condition[];
    workflow: PageWorkflow;
}

export const DESTINATIONS = ['rank_fund', 'extra_fund'] as const;

/** Heads paid only on a sub-dealer's own purchase. */
export const SUB_DEALER_ONLY = ['additional_rank', 'sub_dealer_salary', 'sub_dealer_tour'];

/** A percent typed as "5", "5.5" or "5.25" in basis points (550), or null when it is not a valid percent. */
export function toBasisPoints(value: string): number | null {
    const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(value.trim());
    if (!match) {
        return null;
    }
    const points = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));

    return points > 10000 ? null : points;
}

/** Basis points back to the two-decimal text the engine stores ("2.50"). */
export function fromBasisPoints(points: number): string {
    return `${Math.floor(points / 100)}.${String(points % 100).padStart(2, '0')}`;
}

/** "5.00" shows as "5", "2.50" as "2.5"; Bangla digits on Bangla pages. */
export function showPercent(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return localizeDigits(`${parseFloat(value)}%`);
}

/** Splits the bike and car prize fund exactly as CommissionPolicy::vehiclePoolRates does. */
export function bikePartBasisPoints(totalPoints: number, sharePoints: number): number {
    return Math.floor((totalPoints * sharePoints + 5000) / 10000);
}

/** The bike share (in basis points of the fund) that gives exactly this bike part, or null when no two-decimal share does. */
export function shareForBikePart(totalPoints: number, bikePoints: number): number | null {
    if (totalPoints <= 0) {
        return null;
    }
    const share = Math.round((bikePoints * 10000) / totalPoints);

    return bikePartBasisPoints(totalPoints, share) === bikePoints ? share : null;
}

/** The same cookie-based token the other office pages send with a plain fetch. */
export function xsrfToken(): string {
    const cookie = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1];

    return cookie ? decodeURIComponent(cookie) : '';
}
