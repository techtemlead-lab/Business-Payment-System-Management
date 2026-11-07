/** How a payout was handed over (owner decision: all commissions are paid by hand for now). */
export const payoutMethods = ['cash', 'bank', 'bkash', 'nagad', 'other'] as const;
export type PayoutMethod = typeof payoutMethods[number];

/** Pre-select the method that matches the channel the partner asked to be paid by. */
export const methodForChannel = (channel: string): PayoutMethod => {
    const lower = String(channel ?? '').toLowerCase();
    return lower === 'bank' ? 'bank' : lower === 'bkash' ? 'bkash' : lower === 'nagad' ? 'nagad' : 'other';
};
