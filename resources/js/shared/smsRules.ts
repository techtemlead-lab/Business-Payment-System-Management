/** True when the text has a Bangla letter (digits or ৳ alone do not count). Same rule as SmsText::hasBanglaLetters on the server. */
export function hasBanglaLetters(text: string): boolean {
    return /(?=\p{Script=Bengali})\p{L}/u.test(text);
}

/** How many SMS a Bangla text uses: 70 characters fit in one; a longer text is split into parts of 67. */
export function banglaSmsSegments(text: string): number {
    if (text.length === 0) {
        return 0;
    }
    return text.length <= 70 ? 1 : Math.ceil(text.length / 67);
}
