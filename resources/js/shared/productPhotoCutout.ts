import webAssemblyLoaderUrl from 'onnxruntime-web/ort-wasm-simd-threaded.asyncify.mjs?url';
import webAssemblyUrl from 'onnxruntime-web/ort-wasm-simd-threaded.asyncify.wasm?url';

/** The small file needs graphics that can do half-precision maths; the full-size file runs on the rest. */
export type CutoutPrecision = 'fp16' | 'fp32';

/** Why this browser or device cannot remove a background. Each one has its own plain message. */
export type CutoutBlocker = 'old_browser' | 'no_graphics' | 'low_memory' | 'tool_missing' | 'no_connection';

export type CutoutAvailability = { ready: true; precision: CutoutPrecision } | { ready: false; reason: CutoutBlocker };

export type CutoutProgress = { stage: 'getting_ready' } | { stage: 'downloading'; percent: number } | { stage: 'working' };

type CutoutImage = { width: number; height: number; data: ArrayLike<number> };

type CutoutTool = ((image: unknown) => Promise<CutoutImage | CutoutImage[]>) & { dispose: () => Promise<void> };

type GraphicsAdapter = { features: { has: (name: string) => boolean } };

type BrowserGraphics = { requestAdapter: () => Promise<GraphicsAdapter | null> };

const modelRoot = '/commands/product-photo-model/';
const modelName = 'cutout';

/** The full-size file, kept on a server only when it was installed on purpose. */
const fullPrecisionFile = 'onnx/model.onnx';

/** Below this the work can use up the device's memory and close the page, losing the form. */
const leastMemoryInGigabytes = 4;

/** Pixels fainter than this are leftovers from the edge of the cut, not part of the product. */
const visibleAlpha = 24;

let availability: Promise<CutoutAvailability> | null = null;
let tool: Promise<CutoutTool> | null = null;

async function fileOnServer(file: string): Promise<'there' | 'missing' | 'unreachable'> {
    const response = await fetch(`${modelRoot}${modelName}/${file}`, { method: 'HEAD', credentials: 'same-origin' }).catch(() => null);
    if (!response) {
        return 'unreachable';
    }

    // A sign-in that has run out answers with the sign-in page, which is not the file.
    return response.ok && !response.redirected ? 'there' : 'missing';
}

async function detectAvailability(): Promise<CutoutAvailability> {
    const browser = navigator as Navigator & { gpu?: BrowserGraphics; deviceMemory?: number };
    // Older browsers, and some apps that open links in their own window, have no graphics access at all.
    if (!browser.gpu || typeof OffscreenCanvas === 'undefined') {
        return { ready: false, reason: 'old_browser' };
    }
    // The work must run on the graphics chip. On the processor it runs out of memory and can close the page.
    const adapter = await browser.gpu.requestAdapter().catch(() => null);
    if (!adapter) {
        return { ready: false, reason: 'no_graphics' };
    }
    if (browser.deviceMemory !== undefined && browser.deviceMemory < leastMemoryInGigabytes) {
        return { ready: false, reason: 'low_memory' };
    }

    const toolFiles = await fileOnServer('config.json');
    if (toolFiles !== 'there') {
        return { ready: false, reason: toolFiles === 'missing' ? 'tool_missing' : 'no_connection' };
    }
    if (adapter.features.has('shader-f16')) {
        return { ready: true, precision: 'fp16' };
    }

    return (await fileOnServer(fullPrecisionFile)) === 'there' ? { ready: true, precision: 'fp32' } : { ready: false, reason: 'no_graphics' };
}

/** Whether this browser, this device and this server can remove a photo's background, and if not, why. */
export function checkCutoutAvailability(): Promise<CutoutAvailability> {
    availability ??= detectAvailability().then((result) => {
        if (!result.ready && result.reason === 'no_connection') {
            // The connection may be back the next time a photo is chosen.
            availability = null;
        }

        return result;
    });

    return availability;
}

function loadTool(precision: CutoutPrecision, onProgress: (progress: CutoutProgress) => void): Promise<CutoutTool> {
    tool ??= (async () => {
        const { pipeline, env } = await import('@huggingface/transformers');
        env.allowRemoteModels = false;
        env.allowLocalModels = true;
        env.localModelPath = modelRoot;
        env.useBrowserCache = true;
        // The package's own WebAssembly cache loads scripts from blob: addresses, which the site's security rules refuse.
        env.useWasmCache = false;
        if (env.backends.onnx.wasm) {
            env.backends.onnx.wasm.wasmPaths = { mjs: webAssemblyLoaderUrl, wasm: webAssemblyUrl };
        }

        const created = await pipeline('background-removal', modelName, {
            device: 'webgpu',
            dtype: precision,
            progress_callback: (info: { status: string; file?: string; progress?: number }) => {
                if (info.status === 'progress' && info.file?.endsWith('.onnx')) {
                    onProgress({ stage: 'downloading', percent: Math.round(info.progress ?? 0) });
                }
            },
        });

        return created as unknown as CutoutTool;
    })().catch((error: unknown) => {
        tool = null;
        throw error;
    });

    return tool;
}

/** Frees the graphics memory the tool holds. It reloads from the browser's cache next time. */
export function releaseCutoutTool(): void {
    const current = tool;
    tool = null;
    void current?.then((loaded) => loaded.dispose()).catch(() => undefined);
}

function keptArea({ width, height, data }: CutoutImage): { left: number; top: number; width: number; height: number } | null {
    let top = height;
    let left = width;
    let right = -1;
    let bottom = -1;
    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
            if (data[(y * width + x) * 4 + 3] > visibleAlpha) {
                top = Math.min(top, y);
                left = Math.min(left, x);
                right = Math.max(right, x);
                bottom = Math.max(bottom, y);
            }
        }
    }

    return right < 0 ? null : { left, top, width: right - left + 1, height: bottom - top + 1 };
}

/** Returns the product on a transparent background, trimmed to the product's own edges. */
export async function removeProductPhotoBackground(photo: File, precision: CutoutPrecision, onProgress: (progress: CutoutProgress) => void): Promise<File> {
    onProgress({ stage: 'getting_ready' });
    const cutOut = await loadTool(precision, onProgress);
    const { RawImage } = await import('@huggingface/transformers');
    const image = await RawImage.fromBlob(photo);

    onProgress({ stage: 'working' });
    const output = await cutOut(image);
    const result = Array.isArray(output) ? output[0] : output;
    const area = keptArea(result);
    if (!area) {
        throw new Error('No product was found in the photo.');
    }

    const full = new OffscreenCanvas(result.width, result.height);
    full.getContext('2d')?.putImageData(new ImageData(new Uint8ClampedArray(result.data), result.width, result.height), 0, 0);
    const trimmed = new OffscreenCanvas(area.width, area.height);
    trimmed.getContext('2d')?.drawImage(full, area.left, area.top, area.width, area.height, 0, 0, area.width, area.height);

    const blob = await trimmed.convertToBlob({ type: 'image/webp', quality: 0.92 });

    return new File([blob], `product-photo-cutout.${blob.type === 'image/webp' ? 'webp' : 'png'}`, { type: blob.type });
}
