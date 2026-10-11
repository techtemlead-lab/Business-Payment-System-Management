export type Shared = {
    [key: string]: unknown;
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
            role: "admin" | "dealer";
            mfa_enabled: boolean;
        } | null;
    };
    company: {
        company_name: string;
        contact_email: string | null;
        contact_phone: string | null;
        mode: "COMMISSION_BASED" | "RANK_BASED";
        mode_revision: number;
    };
    demo: boolean;
    flash: { success: string | null };
};
export type Dealer = {
    id: number;
    code: string;
    name: string;
    email: string;
    name_bn?: string;
    mobile?: string;
    level: string;
    division: string;
    district?: string;
    upazila?: string;
    union_name?: string;
    business_name?: string;
    status: string;
    created_at?: string;
};
export type Preview = {
    company_bps: number;
    dealer_bps: number;
    pool_bps: number;
    mrp_minor: number;
    company_minor: number;
    dealer_minor: number;
    pool_minor: number;
    allocated_minor: number;
    unallocated_minor: number;
    allocated_bps: number;
    preview_only: boolean;
    rewards: {
        name: string;
        bps: number;
        amount_minor: number;
        status: string;
    }[];
};
export type Audit = {
    id: number;
    actor_id: number | null;
    action: string;
    subject_id: number | null;
    details: Record<string, unknown>;
    created_at: string;
};
export type Balances = {
    earned_minor: number;
    reserved_minor: number;
    paid_minor: number;
    available_minor?: number;
};
export type Entry = {
    id: number;
    dealer_id: number;
    business_reference: string;
    kind: string;
    amount_minor: number;
    currency: string;
    rule_version_id: number;
    created_at: string;
};
export type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
};
export const money = (minor: number) =>
    `৳${new Intl.NumberFormat("en-BD", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(minor / 100)}`;
export const date = (value: string) =>
    new Date(value).toLocaleDateString("en-GB", {
        day: "numeric",
        month: "short",
        year: "numeric",
        timeZone: "Asia/Dhaka",
    });
export const actionLabel = (value: string) =>
    value.replaceAll(".", " · ").replaceAll("_", " ");
