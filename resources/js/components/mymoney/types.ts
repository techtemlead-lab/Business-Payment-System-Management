import type { MoneyTableData } from '../MoneyTable';
import type { NidRequest } from '../NidSubmit';

export type MoneyLifetime = {
    ordered_mrp: string;
    cash_given: string;
    withdrawn: string;
    spent: string;
    earned: string;
};

export type MoneySummary = {
    available: string;
    waiting: string;
    asked: string;
    total: string;
    lifetime: MoneyLifetime;
};

export type IncomeCard = { key: string; label: string; amount: string };

export type StatementRow = {
    id: string | number;
    occurred_at: string;
    type: string;
    type_label: string;
    percent: string | number | null;
    order_id: string | null;
    /** False when the order belongs to someone in the team, so there is no page to open. */
    order_is_own?: boolean;
    order_number: string | null;
    from_name: string | null;
    withdrawal_id: string | null;
    receipt_url: string | null;
    waiting_until: string | null;
    amount: string;
    running: string | null;
};

export type StatementTable = MoneyTableData<StatementRow>;

export type WithdrawalRequest = {
    id: string;
    created_at: string;
    amount: string;
    status: 'requested' | 'approved' | 'rejected';
    reason: string | null;
    on_hold?: boolean;
};

export type WithdrawalHistoryRow = {
    id: string;
    created_at: string;
    approved_at: string | null;
    paid_at: string | null;
    returned_at: string | null;
    updated_at: string | null;
    amount: string;
    withheld_amount: string;
    net_amount: string;
    status: 'requested' | 'approved' | 'paid' | 'rejected' | 'cancelled' | 'returned' | string;
    channel: string;
    destination_masked: string;
    payment_reference: string | null;
    reason: string | null;
    on_hold: boolean;
    has_proof: boolean;
    proof_url: string | null;
    proof_is_image: boolean;
    receipt_id: number | null;
    receipt_number: string | null;
    receipt_method: string | null;
    bank_name: string | null;
    received_by: string | null;
    receipt_print_url: string | null;
    detail_url: string;
    can_cancel: boolean;
    payout_line: string | null;
};

export type WithdrawalHistoryData = {
    summary: {
        paid_total: string;
        paid_count: number;
        pending_total: string;
        pending_count: number;
        total_count: number;
    };
    filters: {
        status: string;
        channel: string;
    };
    rows: {
        data: WithdrawalHistoryRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
};

export type NotEarnedArea = { month: string; amount: string };

/** The weekly withdrawal day: requests only on the application day, money on the payout day. */
export type WithdrawWeek = { enabled: boolean; open: boolean; message: string | null; request_day: string; payout_day: string; next_request_date: string; payout_date: string };

export type WithdrawInfo ={ can: boolean; kyc_block: boolean; nid_block?: boolean; nid_request?: NidRequest; saved_accounts?: Array<{ id: number; label: string; is_default: boolean; channel?: string }>; methods?: string[]; week?: WithdrawWeek; security_wait: string | null; minimum: string; channel_limits?: Record<string, string | null>; tin_last4?: string | null };

export type Certificates = { years: number[]; rank: boolean };

export type ReserveReceipt = { id: number; number: string };

/** A decimal string from the server that is below zero. */
export function isBelowZero(amount: unknown): boolean {
    return Number(amount) < 0;
}

export type HeldCommissionLine = { id: number; amount: string; what: string; order_id: string; order_number: string | null; reason: 'quota' | 'inactive' };

export type HeldCommission = { total: string; count: number; lines: HeldCommissionLine[] };

export type SalaryNote = { month: string; state: 'paid' | 'none_low_volume' | 'none_other'; amount: string | null; pool: string | null; sharers: number | null; min_volume: string };

export type PendingLine = { id: string; head: string; what: string; month: string; amount: string; order_number: string | null; reason: 'target_not_met' | 'target_met_waiting' | 'month_end'; estimate: boolean };

/**
 * A month-end item (rank royalty, the salary or bonus, an area share of a new order): all decided on the same day.
 * `amount` is null when no honest figure exists before the month closes (rank royalty, a dealer's bonus).
 */
export type MonthEndLine = { id: string; head: string; what: string; month: string; amount: string | null; order_number: string | null; reason: 'month_end' | 'month_end_area' | 'month_end_area_met' | 'salary_shared_month_end' | 'royalty_month_end'; estimate: boolean };

export type PaidLine = { id: string; head: string; what: string; month: string; amount: string; order_number: string | null };

/** Money that waits for the month's target or the month-end close. It is not in the usable balance. */
export type PendingMoney = { role?: string; total: string; count: number; more?: number; has_estimate: boolean; lines: PendingLine[]; month_end?: MonthEndLine[]; paid: PaidLine[]; rewards?: RewardWaitingLine[] };

/** What the company spent on this partner (not a wallet credit). */
/** `amount` is null when the company paid directly for many members and this partner has no amount of their own. */
export type CompanyBenefitLine = { id: string; date: string; what: string; purpose: string; amount: string | null; status: 'paid_by_office' };

export type CompanyBenefits = { total: string; count: number; lines: CompanyBenefitLine[] };

/** A prize the partner has earned (rank prize, bike, car) that is not in the balance yet. */
export type RewardWaitingLine = {
    id: string;
    head: string;
    what: string;
    /** Null when no honest figure exists before the month closes (the rank royalty). */
    amount: string | null;
    reason: 'rank_prize_office' | 'rank_prize_keep' | 'prize_office' | 'prize_review' | 'royalty_month_end' | 'tour_fund';
    /** "pending": earned or coming, not in the balance. "set_aside": the partner's own fund, spent by the office. */
    state: 'pending' | 'set_aside';
    since: string | null;
};
