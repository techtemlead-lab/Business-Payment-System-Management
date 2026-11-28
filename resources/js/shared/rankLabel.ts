/**
 * The short name people see for a rank. The stored rank keys (dealer, mo, am, sm, me) never change: the top key
 * `me` is shown as "ED" (owner, 2026-11-21). Use this instead of upper-casing a rank key, so the top rank is never
 * shown as "ME". The same map is in app/Support/RankLabel.php.
 */
const SHOWN: Record<string, string> = { me: 'ED' };

export function rankLabel(key: string): string {
    const lower = String(key ?? '').trim().toLowerCase();

    return SHOWN[lower] ?? lower.toUpperCase();
}

const FULL_NAME_KEYS = ['mo', 'am', 'sm'];

/**
 * The name a person reads on screen: the full name for Marketing Officer, Area Manager and Sales Manager (client,
 * 2026-11-28), "ED" for the top rank. Never use it for a stored value or a file name; use rankLabel for those.
 */
export function rankName(key: string, translate: (group: string, key: string) => string): string {
    const lower = String(key ?? '').trim().toLowerCase();

    return FULL_NAME_KEYS.includes(lower) ? translate('portal', `rank_short_${lower}`) : rankLabel(lower);
}
