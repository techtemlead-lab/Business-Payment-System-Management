import type { MoneyTableData } from '../MoneyTable';

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
};

export type WithdrawInfo = { can: boolean; kyc_block: boolean; minimum: string };

export type Certificates = { years: number[]; rank: boolean };

/** A decimal string from the server that is below zero. */
export function isBelowZero(amount: unknown): boolean {
    return Number(amount) < 0;
}
