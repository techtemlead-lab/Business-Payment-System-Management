import { isBanglaDocument } from '../business-date';

/**
 * Taka amounts in words, in the lakh / crore system, for the "are you sure" step before a big
 * order. The wording mirrors the server's App\Support\AmountInWords so the screen and the
 * invoice say the same thing.
 */

type WordsLocale = 'bn' | 'en';

const BANGLA_UNDER_HUNDRED = [
    'শূন্য', 'এক', 'দুই', 'তিন', 'চার', 'পাঁচ', 'ছয়', 'সাত', 'আট', 'নয়',
    'দশ', 'এগারো', 'বারো', 'তেরো', 'চৌদ্দ', 'পনেরো', 'ষোলো', 'সতেরো', 'আঠারো', 'উনিশ',
    'বিশ', 'একুশ', 'বাইশ', 'তেইশ', 'চব্বিশ', 'পঁচিশ', 'ছাব্বিশ', 'সাতাশ', 'আটাশ', 'উনত্রিশ',
    'ত্রিশ', 'একত্রিশ', 'বত্রিশ', 'তেত্রিশ', 'চৌত্রিশ', 'পঁয়ত্রিশ', 'ছত্রিশ', 'সাঁইত্রিশ', 'আটত্রিশ', 'উনচল্লিশ',
    'চল্লিশ', 'একচল্লিশ', 'বিয়াল্লিশ', 'তেতাল্লিশ', 'চুয়াল্লিশ', 'পঁয়তাল্লিশ', 'ছেচল্লিশ', 'সাতচল্লিশ', 'আটচল্লিশ', 'উনপঞ্চাশ',
    'পঞ্চাশ', 'একান্ন', 'বায়ান্ন', 'তিপ্পান্ন', 'চুয়ান্ন', 'পঞ্চান্ন', 'ছাপ্পান্ন', 'সাতান্ন', 'আটান্ন', 'উনষাট',
    'ষাট', 'একষট্টি', 'বাষট্টি', 'তেষট্টি', 'চৌষট্টি', 'পঁয়ষট্টি', 'ছেষট্টি', 'সাতষট্টি', 'আটষট্টি', 'উনসত্তর',
    'সত্তর', 'একাত্তর', 'বাহাত্তর', 'তিয়াত্তর', 'চুয়াত্তর', 'পঁচাত্তর', 'ছিয়াত্তর', 'সাতাত্তর', 'আটাত্তর', 'উনআশি',
    'আশি', 'একাশি', 'বিরাশি', 'তিরাশি', 'চুরাশি', 'পঁচাশি', 'ছিয়াশি', 'সাতাশি', 'আটাশি', 'উননব্বই',
    'নব্বই', 'একানব্বই', 'বিরানব্বই', 'তিরানব্বই', 'চুরানব্বই', 'পঁচানব্বই', 'ছিয়ানব্বই', 'সাতানব্বই', 'আটানব্বই', 'নিরানব্বই',
];

const BANGLA_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

const ENGLISH_ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
    'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
const ENGLISH_TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

function pageLocale(locale?: WordsLocale): WordsLocale {
    return locale ?? (isBanglaDocument() ? 'bn' : 'en');
}

/** Whole paisa from a number or a string such as "1,00,000.50"; nothing readable counts as zero. */
function toPaisa(value: string | number): number {
    const cleaned = typeof value === 'number' ? value : Number(String(value).replace(/[^0-9.\-]/g, ''));

    return Number.isFinite(cleaned) ? Math.abs(Math.round(cleaned * 100)) : 0;
}

function banglaWords(number: number): string {
    if (number < 100) {
        return BANGLA_UNDER_HUNDRED[number];
    }
    const parts: string[] = [];
    const crore = Math.floor(number / 10000000);
    let rest = number % 10000000;
    const lakh = Math.floor(rest / 100000);
    rest %= 100000;
    const thousand = Math.floor(rest / 1000);
    rest %= 1000;
    const hundred = Math.floor(rest / 100);
    rest %= 100;

    if (crore > 0) parts.push(`${banglaWords(crore)} কোটি`);
    if (lakh > 0) parts.push(`${BANGLA_UNDER_HUNDRED[lakh]} লাখ`);
    if (thousand > 0) parts.push(`${BANGLA_UNDER_HUNDRED[thousand]} হাজার`);
    if (hundred > 0) parts.push(`${BANGLA_UNDER_HUNDRED[hundred]}শত`);
    if (rest > 0) parts.push(BANGLA_UNDER_HUNDRED[rest]);

    return parts.join(' ');
}

function englishChunk(number: number): string {
    const parts: string[] = [];
    let rest = number;
    if (rest >= 100) {
        parts.push(`${ENGLISH_ONES[Math.floor(rest / 100)]} Hundred`);
        rest %= 100;
    }
    if (rest >= 20) {
        parts.push(ENGLISH_TENS[Math.floor(rest / 10)] + (rest % 10 !== 0 ? ` ${ENGLISH_ONES[rest % 10]}` : ''));
    } else if (rest > 0) {
        parts.push(ENGLISH_ONES[rest]);
    }

    return parts.join(' ').trim();
}

function englishWords(number: number): string {
    if (number === 0) {
        return 'Zero';
    }
    const parts: string[] = [];
    const crore = Math.floor(number / 10000000);
    let rest = number % 10000000;
    const lakh = Math.floor(rest / 100000);
    rest %= 100000;
    const thousand = Math.floor(rest / 1000);
    rest %= 1000;

    if (crore > 0) parts.push(`${englishWords(crore)} Crore`);
    if (lakh > 0) parts.push(`${englishChunk(lakh)} Lakh`);
    if (thousand > 0) parts.push(`${englishChunk(thousand)} Thousand`);
    if (rest > 0) parts.push(englishChunk(rest));

    return parts.join(' ').trim();
}

/** The taka part in words without the closing "only" / "মাত্র". */
function takaPhrase(paisa: number, locale: WordsLocale): string {
    const taka = Math.floor(paisa / 100);
    const poisha = paisa % 100;
    if (locale === 'bn') {
        return `${banglaWords(taka)} টাকা${poisha > 0 ? ` ${banglaWords(poisha)} পয়সা` : ''}`;
    }

    return `${englishWords(taka)} Taka${poisha > 0 ? ` and ${englishWords(poisha)} Poisha` : ''}`;
}

/**
 * Full words, like the server's AmountInWords::taka, paisa included:
 * 10000000000 -> "একশত কোটি টাকা মাত্র" / "One Hundred Crore Taka only".
 */
export function amountInWords(value: string | number, locale?: WordsLocale): string {
    const language = pageLocale(locale);

    return `${takaPhrase(toPaisa(value), language)}${language === 'bn' ? ' মাত্র' : ' only'}`;
}

/** The figure with lakh grouping, in the language's own digits, with no ".00" on whole taka. */
function figure(paisa: number, locale: WordsLocale): string {
    const whole = paisa % 100 === 0;
    const text = new Intl.NumberFormat('en-IN', {
        minimumFractionDigits: whole ? 0 : 2,
        maximumFractionDigits: whole ? 0 : 2,
    }).format(paisa / 100);

    return locale === 'bn' ? text.replace(/[0-9]/g, digit => BANGLA_DIGITS[Number(digit)]) : text;
}

/** "৳১,০০,০০০ (এক লাখ টাকা)": the figure and its words together, for a confirm step. */
export function amountWithWords(value: string | number, locale?: WordsLocale): string {
    const language = pageLocale(locale);
    const paisa = toPaisa(value);

    return `৳${figure(paisa, language)} (${takaPhrase(paisa, language)})`;
}
