import webAssemblyLoaderUrl from 'onnxruntime-web/ort-wasm-simd-threaded.asyncify.mjs?url';
import webAssemblyUrl from 'onnxruntime-web/ort-wasm-simd-threaded.asyncify.wasm?url';

export type CutoutAvailability = 'ready' | 'unsupported' | 'not_installed';

export type CutoutProgress = { stage: 'getting_ready' } | { stage: 'downloading'; percent: number } | { stage: 'working' };

type CutoutImage = { width: number; height: number; data: ArrayLike<number> };

type CutoutTool = ((image: unknown) => Promise<CutoutImage | CutoutImage[]>) & { dispose: () => Promise<void> };

const modelRoot = '/commands/product-photo-model/';
const modelName = 'cutout';

/** Pixels fainter than this are leftovers from the edge of the cut, not part of the product. */
const visibleAlpha = 24;

let availability: Promise<CutoutAvailability> | null = null;
let tool: Promise<CutoutTool> | null = null;

async function detectAvailability(): Promise<CutoutAvailability> {
    const gpu = (navigator as Navigator & { gpu?: { requestAdapter: () => Promise<{ features: Set<string> } | null> } }).gpu;
    const adapter = gpu ? await gpu.requestAdapter().catch(() => null) : null;
    // Without these the work falls back to the processor, which runs out of memory and can close the tab.
    if (!adapter || !adapter.features.has('shader-f16')) {
        return 'unsupported';
    }

    const response = await fetch(`${modelRoot}${modelName}/config.json`, { method: 'HEAD', credentials: 'same-origin' }).catch(() => null);

    return response?.ok ? 'ready' : 'not_installed';
}

/** Whether this computer and this server can remove a photo's background. */
export function checkCutoutAvailability(): Promise<CutoutAvailability> {
    availability ??= detectAvailability();

    return availability;
}

function loadTool(onProgress: (progress: CutoutProgress) => void): Promise<CutoutTool> {
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
            dtype: 'fp16',
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
export async function removeProductPhotoBackground(photo: File, onProgress: (progress: CutoutProgress) => void): Promise<File> {
    onProgress({ stage: 'getting_ready' });
    const cutOut = await loadTool(onProgress);
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
