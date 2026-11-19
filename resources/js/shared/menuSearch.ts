/**
 * The sidebar's "মেনু খুঁজুন" box: everyday words for every page, and action entries that open the
 * exact place (withdraw, add stock, sign out ...). Every entry shows only to a person who may open it.
 */

const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';

/** Lower case, English digits, and no spaces, hyphens, dots or joiners: the form words are compared in. */
export function squash(text: string): string {
    return String(text ?? '')
        .normalize('NFC')
        .toLowerCase()
        .replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit)))
        .replace(/[\s\-_.,:;/\\()'"‘’“”​‌‍⁠]+/g, '');
}

/** True when the typed words (spaces ignored) appear inside any one of the texts. Nothing typed matches nothing. */
export function matchesAny(texts: string[], query: string): boolean {
    const wanted = squash(query);

    return wanted !== '' && texts.some((text) => squash(text).includes(wanted));
}

/** Splits a comma-separated word list from the language file. */
export function wordList(line: string): string[] {
    return line.split(/[,،]/).map((word) => word.trim()).filter(Boolean);
}

/** Item ids that share another page's word list. */
const KEYWORD_ALIASES: Record<string, string> = {
    'partner-money': 'my_money', 'my-money': 'my_money',
    circulars: 'partner_notices', notifications: 'partner_notices', 'partner-notices': 'partner_notices',
    'catalog-products': 'catalog', stock: 'inventory',
    'treasury-orders': 'financial_reports', 'financial-reports': 'financial_reports',
    'sub-dealers': 'accounts', 'sub-dealer-applications': 'partnerships',
    payroll: 'employees', 'staff-operations': 'employees', 'access-management': 'employees', 'field-sales': 'employees',
    content: 'website', messages: 'partner_messages', 'partner-messages': 'partner_messages', 'support-admin': 'partner_messages',
    'stock-lots': 'stock_lots', 'my-payroll': 'my_payroll',
};

/** The language-file key holding the everyday words of a menu item. */
export function keywordKeyFor(item: string): string {
    return `kw_${KEYWORD_ALIASES[item] ?? item.replaceAll('-', '_')}`;
}

export type SearchContext = {
    staff: boolean;
    role: string;
    capabilities: string[];
    /** The pages this person may open (the sidebar's own list). */
    available: Set<string>;
    /** May enter stock (the same right the stock buttons ask for). */
    stockCreate: boolean;
};

export type SearchAction = {
    id: string;
    /** Address after the portal base, or null for an action that does something instead of opening a page. */
    path: string | null;
    /** Language key (findability group) of the place the action sits in, shown under its name. */
    placeKey?: string;
    shown: (ctx: SearchContext) => boolean;
};

const hasPage = (ctx: SearchContext, ...pages: string[]): boolean => pages.every((page) => ctx.available.has(page));

/** Entries that open the exact place. Order is the order of the results. */
export const SEARCH_ACTIONS: SearchAction[] = [
    { id: 'withdraw', path: 'my-money?withdraw=1', placeKey: 'place_wallet', shown: (ctx) => !ctx.staff && hasPage(ctx, 'my-money') },
    { id: 'income_certificate', path: 'my-money', placeKey: 'place_wallet', shown: (ctx) => !ctx.staff && hasPage(ctx, 'my-money') },
    { id: 'customers_due', path: 'customers?status=due', placeKey: 'place_customers', shown: (ctx) => !ctx.staff && hasPage(ctx, 'customers') },
    { id: 'stock_in', path: 'inventory?action=stock_in', placeKey: 'place_stock', shown: (ctx) => ctx.staff && ctx.stockCreate && hasPage(ctx, 'inventory') },
    { id: 'stock_out', path: 'inventory?action=stock_out', placeKey: 'place_stock', shown: (ctx) => ctx.staff && ctx.stockCreate && hasPage(ctx, 'inventory') },
    { id: 'expense', path: 'treasury/funds?spend=1', placeKey: 'place_treasury_funds', shown: (ctx) => ctx.staff && hasPage(ctx, 'treasury') },
    { id: 'budget', path: 'treasury/work?tab=heads', placeKey: 'place_treasury_work', shown: (ctx) => ctx.staff && hasPage(ctx, 'treasury') },
    { id: 'report_files', path: 'operations/financial-reports', placeKey: 'place_reports', shown: (ctx) => ctx.staff && hasPage(ctx, 'financial-reports') },
    { id: 'my_salary', path: 'operations/my-payroll', shown: (ctx) => ctx.staff && hasPage(ctx, 'my-payroll') },
    { id: 'new_dealer', path: 'accounts/create', placeKey: 'place_partners', shown: (ctx) => ctx.staff && ctx.capabilities.includes('accounts') },
    { id: 'new_sub_dealer', path: 'accounts/create?intent=sub_dealer', placeKey: 'place_partners', shown: (ctx) => ctx.staff && ctx.capabilities.includes('accounts') },
    { id: 'new_product', path: 'products/create', placeKey: 'place_stock', shown: (ctx) => ctx.staff && ctx.capabilities.includes('catalog') && ctx.capabilities.includes('pricing') },
    { id: 'change_password', path: 'security', shown: (ctx) => hasPage(ctx, 'security') },
    { id: 'profile_photo', path: 'profile', shown: (ctx) => hasPage(ctx, 'profile') },
    { id: 'help', path: 'help', shown: (ctx) => hasPage(ctx, 'help') },
    { id: 'sign_out', path: null, shown: () => true },
];

export function shownActions(ctx: SearchContext): SearchAction[] {
    return SEARCH_ACTIONS.filter((action) => action.shown(ctx));
}
