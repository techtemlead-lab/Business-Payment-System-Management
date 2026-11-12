import { useEffect, useRef, useState } from 'react';

/** The server refuses a product photo heavier or wider than this (ProductRegistryController::save). */
export const largestPhotoBytes = 5120 * 1024;
export const longestPhotoSide = 4096;

/** Tried in turn until the photo is light enough. After the last one the picture itself is made smaller. */
const qualitySteps = [0.9, 0.82, 0.74];
const shrinkBy = 0.85;

/** Below this a product photo is too small to be worth saving, so the person is asked for another photo. */
const smallestUsefulSide = 1000;

const extensions: Record<string, string> = { 'image/jpeg': 'jpg', 'image/webp': 'webp', 'image/png': 'png' };

export type PhotoFit = { outcome: 'unchanged' | 'made_smaller'; photo: File } | { outcome: 'too_large' };

export type PhotoFitStatus = 'idle' | 'checking' | 'making_smaller' | 'made_smaller' | 'too_large';

/** An image element turns a phone photo upright from the camera's own note, so the smaller copy is upright too. */
function openPhoto(address: string): Promise<HTMLImageElement | null> {
    return new Promise((resolve) => {
        const image = new Image();
        image.onload = () => resolve(image.naturalWidth > 0 && image.naturalHeight > 0 ? image : null);
        image.onerror = () => resolve(null);
        image.src = address;
    });
}

/**
 * A device short of memory, or a browser that guards its canvas, can hand back one flat colour without
 * saying so. That must never be saved as the product photo.
 */
function holdsPicture(canvas: HTMLCanvasElement): boolean {
    const sample = document.createElement('canvas');
    sample.width = 16;
    sample.height = 16;
    const context = sample.getContext('2d', { willReadFrequently: true });
    if (!context) {
        return false;
    }
    context.drawImage(canvas, 0, 0, sample.width, sample.height);
    const { data } = context.getImageData(0, 0, sample.width, sample.height);
    for (let index = 4; index < data.length; index += 1) {
        if (data[index] !== data[index % 4]) {
            return true;
        }
    }

    return false;
}

function drawAt(image: HTMLImageElement, width: number, height: number): HTMLCanvasElement | null {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) {
        return null;
    }
    context.imageSmoothingQuality = 'high';
    context.drawImage(image, 0, 0, width, height);

    return holdsPicture(canvas) ? canvas : null;
}

function saveAs(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/** A copy no wider than the server allows and light enough for it, with the same proportions. */
async function smallerCopy(image: HTMLImageElement, firstType: string): Promise<Blob | null> {
    const longest = Math.max(image.naturalWidth, image.naturalHeight);
    let type = firstType;
    for (let side = Math.min(longest, longestPhotoSide); ; side = Math.floor(side * shrinkBy)) {
        const scale = side / longest;
        const canvas = drawAt(image, Math.max(1, Math.round(image.naturalWidth * scale)), Math.max(1, Math.round(image.naturalHeight * scale)));
        if (!canvas) {
            return null;
        }
        try {
            for (const quality of qualitySteps) {
                const copy = await saveAs(canvas, type, quality);
                if (!copy) {
                    return null;
                }
                // A browser that cannot write WebP answers with PNG, which has no quality to lower.
                type = copy.type;
                if (copy.size <= largestPhotoBytes) {
                    return copy;
                }
                if (type === 'image/png') {
                    break;
                }
            }
        } finally {
            // Phones keep little memory for canvases, so give this one back before drawing the next.
            canvas.width = 0;
            canvas.height = 0;
        }
        if (side * shrinkBy < smallestUsefulSide) {
            return null;
        }
    }
}

/**
 * Returns the photo as it is when the server will take it. When it is too heavy or too wide, returns a
 * smaller copy with the same proportions: JPEG for a JPEG, and WebP for the rest so a clear background stays clear.
 */
export async function fitProductPhoto(photo: File, onMakingSmaller: () => void = () => undefined): Promise<PhotoFit> {
    const tooHeavy = photo.size > largestPhotoBytes;
    const address = URL.createObjectURL(photo);
    try {
        const image = await openPhoto(address);
        if (!image) {
            // This browser cannot read the file. A light one goes up as it is, for the server to judge.
            return tooHeavy ? { outcome: 'too_large' } : { outcome: 'unchanged', photo };
        }
        if (!tooHeavy && Math.max(image.naturalWidth, image.naturalHeight) <= longestPhotoSide) {
            return { outcome: 'unchanged', photo };
        }

        onMakingSmaller();
        const copy = await smallerCopy(image, photo.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp');
        if (!copy) {
            return { outcome: 'too_large' };
        }
        const name = `${photo.name.replace(/\.[^.]*$/, '') || 'product-photo'}.${extensions[copy.type] ?? 'jpg'}`;

        return { outcome: 'made_smaller', photo: new File([copy], name, { type: copy.type }) };
    } catch {
        return { outcome: 'too_large' };
    } finally {
        URL.revokeObjectURL(address);
    }
}

/**
 * For one photo box on a form. `choose` takes the file the person picked and hands the form a photo the
 * server will take, or nothing when the photo is too large and could not be made smaller. `status` says
 * what happened, for the one line under the box, and `busy` is true while the form must wait.
 */
export function useProductPhotoFit(onPhoto: (photo: File | null) => void): {
    status: PhotoFitStatus;
    busy: boolean;
    choose: (photo: File | null) => void;
    forget: () => void;
} {
    const [status, setStatus] = useState<PhotoFitStatus>('idle');
    const currentRun = useRef(0);
    const latestOnPhoto = useRef(onPhoto);
    latestOnPhoto.current = onPhoto;

    useEffect(() => () => {
        currentRun.current += 1;
    }, []);

    const forget = () => {
        currentRun.current += 1;
        setStatus('idle');
    };

    const choose = (photo: File | null) => {
        currentRun.current += 1;
        const run = currentRun.current;
        if (!photo) {
            setStatus('idle');
            onPhoto(null);

            return;
        }
        setStatus('checking');
        void fitProductPhoto(photo, () => {
            if (run === currentRun.current) {
                setStatus('making_smaller');
            }
        }).then((fit) => {
            // A newer choice, or a closed form, has taken over.
            if (run !== currentRun.current) {
                return;
            }
            setStatus(fit.outcome === 'unchanged' ? 'idle' : fit.outcome);
            latestOnPhoto.current(fit.outcome === 'too_large' ? null : fit.photo);
        });
    };

    return { status, busy: status === 'checking' || status === 'making_smaller', choose, forget };
}
