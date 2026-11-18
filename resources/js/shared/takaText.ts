import { isBanglaDocument, localizeDigits } from '../business-date';

type TakaLocale = 'bn' | 'en';

const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

const LAKH = 100000n;

/** Whole taka and paisa as exact integers; anything unreadable or negative counts as zero. */
function toCents(value: unknown): bigint {
    let text = '';
    if (typeof value === 'number') {
        text = Number.isFinite(value) ? value.toFixed(2) : '0';
    } else if (typeof value === 'bigint') {
        text = value.toString();
    } else if (typeof value === 'string') {
        text = value.replace(/[৳,\s]/g, '');
    }
    const match = /^(\d{1,16})(?:\.(\d+))?$/.exec(text);
    if (!match) {
        return 0n;
    }
    const fraction = Math.round(Number(`0.${match[2] ?? '0'}`) * 100);

    return BigInt(match[1]) * 100n + BigInt(fraction);
}

/** Lakh/crore grouping of a whole number written in digits: "12345678" becomes "1,23,45,678". */
function group(whole: string): string {
    if (whole.length <= 3) {
        return whole;
    }

    return `${whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',')},${whole.slice(-3)}`;
}

function isBangla(locale?: TakaLocale): boolean {
    return locale ? locale === 'bn' : isBanglaDocument();
}

function digits(text: string, locale?: TakaLocale): string {
    if (!locale) {
        return localizeDigits(text);
    }

    return locale === 'bn' ? text.replace(/[0-9]/g, digit => BANGLA_DIGITS[Number(digit)]) : text;
}

/** "৳1,23,45,678", or "৳1,234.50" when there are paisa; Bangla digits follow the locale or the page. */
export function takaAmount(value: unknown, locale?: TakaLocale): string {
    const cents = toCents(value);
    const paisa = cents % 100n;
    const whole = group((cents / 100n).toString());

    return `৳${digits(paisa === 0n ? whole : `${whole}.${paisa.toString().padStart(2, '0')}`, locale)}`;
}

/** "1 crore 20 lakh taka" for amounts from one lakh up (rounded down to a whole lakh), else null. */
export function takaWords(value: unknown, locale?: TakaLocale): string | null {
    const totalLakh = toCents(value) / 100n / LAKH;
    if (totalLakh < 1n) {
        return null;
    }
    const bangla = isBangla(locale);
    const crore = totalLakh / 100n;
    const lakh = totalLakh % 100n;
    const parts: string[] = [];
    if (crore > 0n) {
        parts.push(`${group(crore.toString())} ${bangla ? 'কোটি' : 'crore'}`);
    }
    if (lakh > 0n) {
        parts.push(`${lakh} ${bangla ? 'লাখ' : 'lakh'}`);
    }

    return digits(`${parts.join(' ')} ${bangla ? 'টাকা' : 'taka'}`, locale);
}

/** The amount plus its words in brackets when there are words: "৳৩০,০০,০০,০০০ (৩০ কোটি টাকা)". */
export function takaWithWords(value: unknown, locale?: TakaLocale): string {
    const words = takaWords(value, locale);
    const amount = takaAmount(value, locale);

    return words === null ? amount : `${amount} (${words})`;
}
