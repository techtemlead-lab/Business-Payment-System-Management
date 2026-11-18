import { localizeDigits } from '../business-date';
import type { Translate } from './catalogWords';

/** Orders from this amount up (10 lakh) ask "is this right?" before they are sent. */
export const BIG_ORDER_TAKA = 1_000_000;

/**
 * An amount as the lakh and crore people say it: 903200000 becomes "90 কোটি 32 লাখ টাকা".
 * Returns an empty string below one thousand, where the plain figure already reads easily.
 */
export function amountInWords(amount: number, translate: Translate): string {
    let rest = Math.floor(Math.abs(amount));
    if (!Number.isFinite(rest) || rest < 1000) {
        return '';
    }
    const parts: string[] = [];
    for (const [size, key] of [[10_000_000, 'unit_crore'], [100_000, 'unit_lakh'], [1000, 'unit_thousand']] as const) {
        const count = Math.floor(rest / size);
        rest -= count * size;
        if (count > 0) {
            parts.push(`${localizeDigits(String(count))} ${translate('partner_ux', key)}`);
        }
    }
    if (rest > 0) {
        parts.push(localizeDigits(String(rest)));
    }

    return `${parts.join(' ')} ${translate('partner_ux', 'unit_taka')}`;
}
