/**
 * Money and counts are never animated. The old count-up showed made-up in-between amounts
 * (for example ৳89,93,185.98 while the real figure was another) for most of a second, and an
 * older dealer reading the screen then took them as true. Only the digit helper is kept.
 */
const BANGLA_ZERO = 0x09e6;

export function toLatin(text: string): string {
    return text.replace(/[০-৯]/g, (digit) => String(digit.charCodeAt(0) - BANGLA_ZERO));
}
