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
