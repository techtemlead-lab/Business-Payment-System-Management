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

export type NotEarnedArea = { month: string; amount: string };

export type WithdrawInfo ={ can: boolean; kyc_block: boolean; nid_block?: boolean; nid_request?: NidRequest; saved_accounts?: Array<{ id: number; label: string; is_default: boolean }>; security_wait: string | null; minimum: string; channel_limits?: Record<string, string | null>; tin_last4?: string | null };

export type Certificates = { years: number[]; rank: boolean };

export type ReserveReceipt = { id: number; number: string };

/** A decimal string from the server that is below zero. */
export function isBelowZero(amount: unknown): boolean {
    return Number(amount) < 0;
}

export type HeldCommissionLine = { id: number; amount: string; what: string; order_id: string; order_number: string | null; reason: 'quota' | 'inactive' };

export type HeldCommission = { total: string; count: number; lines: HeldCommissionLine[] };

export type SalaryNote = { month: string; state: 'paid' | 'none_low_volume' | 'none_other'; amount: string | null; pool: string | null; sharers: number | null; min_volume: string };
