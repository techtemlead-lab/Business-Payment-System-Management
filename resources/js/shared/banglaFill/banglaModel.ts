import webAssemblyLoaderUrl from 'onnxruntime-web/ort-wasm-simd-threaded.asyncify.mjs?url';
import webAssemblyUrl from 'onnxruntime-web/ort-wasm-simd-threaded.asyncify.wasm?url';
import { createEnglishToBangla, type EnglishToBangla, type OrtRuntime, type OrtSession } from './indicTrans.ts';
import type { BanglaKind } from './rules.ts';

/**
 * Loads the English → Bangla model in the office browser (CR-06). The files are installed on the server by
 * `php artisan madina:install-bangla-model` and handed only to signed-in staff. The browser keeps them after
 * the first download (about 310 MB), so later pages start in a few seconds. Text never leaves the computer.
 */

/** Why the automatic Bangla is not available here. The Bangla box then simply stays for typing by hand. */
export type BanglaModelBlocker = 'not_installed' | 'old_browser' | 'low_memory' | 'no_connection';

export type BanglaModelAvailability = { ready: true } | { ready: false; reason: BanglaModelBlocker };

const modelRoot = '/commands/bangla-model/en-bn/';

/** Changing the model files means a new name here, so browsers drop the old copy. Matches config/madina.php. */
const browserCacheName = 'madina-bangla-model-1eaa43a3';

/** The model needs about 1 GB while it works; on smaller computers it could close the page and lose the form. */
const leastMemoryInGigabytes = 4;

let availability: Promise<BanglaModelAvailability> | null = null;
let translator: Promise<EnglishToBangla> | null = null;

async function detectAvailability(): Promise<BanglaModelAvailability> {
    const browser = navigator as Navigator & { deviceMemory?: number };
    if (typeof WebAssembly === 'undefined' || typeof BigInt64Array === 'undefined') {
        return { ready: false, reason: 'old_browser' };
    }
    if (browser.deviceMemory !== undefined && browser.deviceMemory < leastMemoryInGigabytes) {
        return { ready: false, reason: 'low_memory' };
    }
    const response = await fetch(`${modelRoot}tokenizer_meta.json`, { method: 'HEAD', credentials: 'same-origin' }).catch(() => null);
    if (!response) {
        return { ready: false, reason: 'no_connection' };
    }

    // A sign-in that has run out answers with the sign-in page, which is not the file.
    return response.ok && !response.redirected ? { ready: true } : { ready: false, reason: 'not_installed' };
}

/** Whether this browser, this computer and this server can write the Bangla, and if not, why. */
export function checkBanglaModel(): Promise<BanglaModelAvailability> {
    availability ??= detectAvailability().then((result) => {
        if (!result.ready && result.reason === 'no_connection') {
            // The connection may be back next time.
            availability = null;
        }

        return result;
    });

    return availability;
}

/** One model file, from the browser's own copy when it has one. */
async function modelFile(file: string): Promise<Uint8Array> {
    const url = `${modelRoot}${file}`;
    const cache = typeof caches === 'undefined' ? null : await caches.open(browserCacheName).catch(() => null);
    const kept = await cache?.match(url);
    if (kept) {
        return new Uint8Array(await kept.arrayBuffer());
    }
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok || response.redirected) {
        throw new Error(`Bangla model file ${file} could not be fetched.`);
    }
    const bytes = new Uint8Array(await response.arrayBuffer());
    await cache?.put(url, new Response(bytes, { headers: { 'Content-Type': 'application/octet-stream' } })).catch(() => undefined);

    return bytes;
}

const json = async (file: string): Promise<any> => JSON.parse(new TextDecoder().decode(await modelFile(file)));

function loadTranslator(): Promise<EnglishToBangla> {
    translator ??= (async () => {
        const [ort, { PreTrainedTokenizer }] = await Promise.all([import('onnxruntime-web/webgpu'), import('@huggingface/transformers')]);
        ort.env.wasm.wasmPaths = { mjs: webAssemblyLoaderUrl, wasm: webAssemblyUrl };
        // Several threads need a cross-origin isolated page, which the portals are not; one thread is enough here.
        ort.env.wasm.numThreads = 1;
        const options = (dataFile: string, data: Uint8Array) => ({ executionProviders: ['wasm'], externalData: [{ path: dataFile, data }] });
        const encoder = await ort.InferenceSession.create(await modelFile('encoder_model.onnx'), options('encoder_model.onnx.data', await modelFile('encoder_model.onnx.data')));
        const shared = await modelFile('decoder_shared.onnx.data');
        const decoder = await ort.InferenceSession.create(await modelFile('decoder_model.onnx'), options('decoder_shared.onnx.data', shared));
        const decoderWithPast = await ort.InferenceSession.create(await modelFile('decoder_with_past_model.onnx'), options('decoder_shared.onnx.data', shared));
        const config = await json('tokenizer_config.json');

        return createEnglishToBangla({
            ort: ort as unknown as OrtRuntime,
            encoder: encoder as unknown as OrtSession,
            decoder: decoder as unknown as OrtSession,
            decoderWithPast: decoderWithPast as unknown as OrtSession,
            sourceTokenizer: new PreTrainedTokenizer(await json('tokenizer_src.json'), config),
            targetTokenizer: new PreTrainedTokenizer(await json('tokenizer_tgt.json'), config),
            meta: await json('tokenizer_meta.json'),
            pause: () => new Promise((resolve) => setTimeout(resolve, 0)),
        });
    })().catch((error: unknown) => {
        translator = null;
        throw error;
    });

    return translator;
}

/** Starts loading while the person is still typing English, so the Bangla is ready sooner. */
export function warmUpBanglaModel(): void {
    void checkBanglaModel().then((result) => (result.ready ? loadTranslator() : null)).catch(() => undefined);
}

/** Bangla for the English text; a name is spelt out in Bangla letters, other text is translated. */
export async function englishToBangla(english: string, kind: BanglaKind): Promise<string> {
    return (await loadTranslator())(english, kind);
}
