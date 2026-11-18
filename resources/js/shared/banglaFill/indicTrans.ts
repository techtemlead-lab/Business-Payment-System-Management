import { type BanglaKind, bangladeshSpelling, devanagariToBangla, joinSentences, prepareEnglish, sentences, tidyBangla } from './rules.ts';

/**
 * English → Bangla with AI4Bharat's IndicTrans2 (distilled 200M, MIT licence), from the INT8 ONNX export
 * hari31416/indictrans2-en-indic-dist-200M-ONNX-int8 (MIT). Everything runs on the office computer: the text
 * never leaves it. This file has no browser-only code, so the same code is checked in Node.
 */

/** The small part of onnxruntime this code uses (onnxruntime-web in the browser, also in Node). */
export type OrtTensor = { dims: readonly number[]; data: ArrayLike<number> | ArrayLike<bigint> };
export type OrtSession = {
    inputNames: readonly string[];
    outputNames: readonly string[];
    run: (feeds: Record<string, unknown>) => Promise<Record<string, OrtTensor>>;
};
export type OrtRuntime = {
    Tensor: new (type: 'int64', data: BigInt64Array, dims: number[]) => unknown;
};
export type BanglaTokenizer = {
    encode: (text: string) => number[] | { ids: number[] };
    decode: (ids: number[], options: { skip_special_tokens: boolean }) => string;
};

export type EnglishToBanglaParts = {
    ort: OrtRuntime;
    encoder: OrtSession;
    decoder: OrtSession;
    decoderWithPast: OrtSession;
    sourceTokenizer: BanglaTokenizer;
    targetTokenizer: BanglaTokenizer;
    meta: { src_dict_size: number; tgt_dict_size: number; unk_id: number };
    /** Lets the page breathe between words so typing elsewhere is not frozen. */
    pause?: () => Promise<void>;
};

const StartAndEndOfText = 2;
const MostWordsPerSentence = 160;

export type EnglishToBangla = (english: string, kind: BanglaKind) => Promise<string>;

export function createEnglishToBangla(parts: EnglishToBanglaParts): EnglishToBangla {
    const { ort, encoder, decoder, decoderWithPast, sourceTokenizer, targetTokenizer, meta } = parts;
    const layers = (decoder.outputNames.length - 1) / 4;
    const int64 = (values: number[], dims: number[]) => new ort.Tensor('int64', BigInt64Array.from(values.map((value) => BigInt(value))), dims);
    const idsOf = (encoded: number[] | { ids: number[] }) => (Array.isArray(encoded) ? encoded : encoded.ids);

    async function sentence(english: string): Promise<string> {
        const ids = idsOf(sourceTokenizer.encode(`eng_Latn ben_Beng ${prepareEnglish(english)}`)).map((id) => (id < meta.src_dict_size ? id : meta.unk_id));
        const mask = int64(ids.map(() => 1), [1, ids.length]);
        const encoded = await encoder.run({ input_ids: int64(ids, [1, ids.length]), attention_mask: mask });
        let past: OrtTensor[] = [];
        let next = StartAndEndOfText;
        const words: number[] = [];
        for (let step = 0; step < MostWordsPerSentence; step++) {
            const input = int64([next], [1, 1]);
            const session = step === 0 ? decoder : decoderWithPast;
            const feeds: Record<string, unknown> = { input_ids: input, encoder_attention_mask: mask };
            if (step === 0) {
                feeds.encoder_hidden_states = encoded.last_hidden_state;
            } else {
                for (let layer = 0; layer < layers; layer++) {
                    feeds[`past_key_values.${layer}.decoder.key`] = past[layer * 4];
                    feeds[`past_key_values.${layer}.decoder.value`] = past[layer * 4 + 1];
                    feeds[`past_key_values.${layer}.encoder.key`] = past[layer * 4 + 2];
                    feeds[`past_key_values.${layer}.encoder.value`] = past[layer * 4 + 3];
                }
            }
            const result = await session.run(feeds);
            const logits = result[session.outputNames[0]];
            past = session.outputNames.slice(1).map((name) => result[name]);
            next = likeliest(logits);
            if (next === StartAndEndOfText) {
                break;
            }
            words.push(next);
            await parts.pause?.();
        }

        return tidyBangla(devanagariToBangla(targetTokenizer.decode(words.map((id) => (id < meta.tgt_dict_size ? id : meta.unk_id)), { skip_special_tokens: true })));
    }

    return async (english, kind) => {
        if (kind === 'name') {
            return bangladeshSpelling(await sentence(english.trim()));
        }
        const translated: string[] = [];
        for (const piece of sentences(english)) {
            translated.push(piece === '\n' ? '\n' : await sentence(piece));
        }

        return joinSentences(translated);
    };
}

/** The word the model is surest of: the highest score in the last position. */
function likeliest(logits: OrtTensor): number {
    const vocabulary = logits.dims[2];
    const scores = logits.data as ArrayLike<number>;
    const start = scores.length - vocabulary;
    let best = 0;
    for (let index = 1; index < vocabulary; index++) {
        if (scores[start + index] > scores[start + best]) {
            best = index;
        }
    }

    return best;
}
