import type { Condition, Head, PageTotals } from '../commissionpage/shared';

export interface Person {
    id: number;
    name: string;
    dealer_code: string | null;
    phone: string | null;
    role: string;
    status: string;
    division: string | null;
    district: string | null;
}

export interface ReceiverLine {
    category: string;
    rule: string;
    pct: string;
}

export interface ReceiverRate {
    percentage: string;
    funding_mode?: 'company' | 'allocations';
    funding_sources?: Record<string, string>;
}

export type ScheduleState = 'draft' | 'active' | 'upcoming' | 'ended' | 'expired' | 'rejected' | 'cancelled';

/** One special rate or waiting draft, with what the viewer may do to it. */
export interface ScheduleItem {
    id: number;
    scope: string;
    state: ScheduleState;
    status: string;
    person_name?: string | null;
    lines: string[];
    effective_at: string;
    expires_at: string | null;
    reason: string | null;
    proposed_by_name: string | null;
    reviewed_by_name: string | null;
    review_reason: string | null;
    can_review: boolean;
    can_cancel: boolean;
    can_end: boolean;
}

export interface Controls {
    unconditional_salary: boolean;
    unconditional_rank_royalty: boolean;
    forced_royalty_rank: string | null;
    unconditional_rank_incentive: boolean;
    custom_conditions?: Record<string, string | number>;
    note: string | null;
}

export interface CustomizableField {
    key: string;
    unit: 'money' | 'percent' | 'count' | 'days';
    general: string;
}

export interface Seat {
    level: string;
    place: string;
    monthly_quota: string;
}

export interface SpecialData {
    workflow: { can_propose: boolean; is_owner: boolean; applies_at_once: boolean; can_set_conditions: boolean };
    receiver_lines: ReceiverLine[];
    heads: Head[];
    totals: PageTotals;
    conditions: Condition[];
    waivable: Record<string, string>;
    customizable?: Record<string, CustomizableField[]>;
    sub_dealer_salary_max_pct?: string;
    funding_sources: string[];
    person: Person | null;
    receiver_current?: { schedule_id: number; rates: Record<string, ReceiverRate> } | null;
    buyer_current?: { schedule_id: number; heads: Head[] } | null;
    schedules?: ScheduleItem[];
    controls?: Controls | null;
    seats?: Seat[];
}
