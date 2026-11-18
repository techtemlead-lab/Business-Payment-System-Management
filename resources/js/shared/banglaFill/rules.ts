/**
 * Rules for filling a Bangla box from its English twin (CR-06). Pure functions, so they are tested
 * without a browser or the model (tests/Unit/bangla-fill.test.mjs).
 */

/** What the English box holds: a person's or place's name is spelt out, anything else is translated. */
export type BanglaKind = 'name' | 'text';

/** The longest English text sent to the model at once; longer text is translated sentence by sentence. */
export const MaxSentenceLength = 400;

/**
 * Whether the Bangla box may be filled: only when English was typed and the Bangla box is still empty.
 * A Bangla value someone typed or corrected is never replaced.
 */
export function shouldFill(english: string, bangla: string): boolean {
    return english.trim() !== '' && bangla.trim() === '' && /[A-Za-z]/.test(english);
}

/** Splits text into sentences the model handles well, keeping line breaks as their own pieces. */
export function sentences(text: string): string[] {
    const pieces: string[] = [];
    for (const line of text.replace(/\r/g, '').split('\n')) {
        if (line.trim() === '') {
            pieces.push('\n');
            continue;
        }
        const parts = line.match(/[^.!?]+(?:[.!?]+|$)/g) ?? [line];
        for (const part of parts) {
            const sentence = part.trim();
            if (sentence === '') {
                continue;
            }
            // A run-on line without full stops is cut at commas so no piece is too long for the model.
            if (sentence.length > MaxSentenceLength) {
                pieces.push(...sentence.split(/(?<=,)\s+/).map((chunk) => chunk.trim()).filter(Boolean));
            } else {
                pieces.push(sentence);
            }
        }
        pieces.push('\n');
    }
    while (pieces.at(-1) === '\n') {
        pieces.pop();
    }

    return pieces;
}

/** Puts translated sentences back together with the original line breaks. */
export function joinSentences(pieces: string[]): string {
    return pieces.join(' ').replace(/ ?\n ?/g, '\n').replace(/[ ]{2,}/g, ' ').trim();
}

/**
 * The model learnt Bangla mostly from Indian text, so some common names come out in West Bengal spelling.
 * These are the spellings used in Bangladesh. Only whole words are changed, and only in names.
 */
const BangladeshNameSpellings: Record<string, string> = {
    'মহম্মদ': 'মোহাম্মদ',
    'মহম্মাদ': 'মোহাম্মদ',
    'মুহম্মদ': 'মুহাম্মদ',
    'আকতার': 'আক্তার',
    'আখতার': 'আক্তার',
    'আখতারুজ্জামান': 'আক্তারুজ্জামান',
    'বেগাম': 'বেগম',
    'চৌধুরি': 'চৌধুরী',
    'হুসেন': 'হোসেন',
    'হুসাইন': 'হোসাইন',
    'হোসেইন': 'হোসেন',
    'মিঁয়া': 'মিয়া',
    'উদ্দীন': 'উদ্দিন',
};

export function bangladeshSpelling(name: string): string {
    return name
        .split(/(\s+|[.,-])/)
        .map((word) => BangladeshNameSpellings[word] ?? word)
        .join('');
}

/** Devanagari → Bangla: the model writes every Indian language in one shared script; Bangla sits 0x80 higher. */
export function devanagariToBangla(text: string): string {
    let out = '';
    for (const character of text) {
        const point = character.codePointAt(0) ?? 0;
        const offset = point - 0x0900;
        // The full stops । and ॥ are shared by both scripts and stay as they are.
        out += offset >= 0 && offset <= 0x6f && point !== 0x0964 && point !== 0x0965 ? String.fromCodePoint(0x0980 + offset) : character;
    }

    return out;
}

/** The model reads English split into words and punctuation, the way it was trained. */
export function prepareEnglish(text: string): string {
    return text
        .replace(/[`´‘‚’]/g, "'")
        .replace(/[„“”«»]/g, '"')
        .replace(/[–—]/g, '-')
        .replace(/([.,!?;:()"])/g, ' $1 ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Joins the model's words back into written Bangla: no space before punctuation or after an opening
 * bracket, and Bangla digits (৫ বছর, not 5 বছর).
 */
export function tidyBangla(text: string): string {
    return text
        .replace(/\s+([.,!?;:)\]।])/g, '$1')
        .replace(/([(\[])\s+/g, '$1')
        .replace(/\s+/g, ' ')
        .replace(/[0-9]/g, (digit) => String.fromCodePoint(0x09e6 + Number(digit)))
        .trim();
}
