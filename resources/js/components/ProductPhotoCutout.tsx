import { useEffect, useRef, useState } from 'react';
import { Scissors } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import {
    checkCutoutAvailability,
    releaseCutoutTool,
    removeProductPhotoBackground,
    type CutoutAvailability,
    type CutoutBlocker,
    type CutoutPrecision,
    type CutoutProgress,
} from '../shared/productPhotoCutout';
import { fitProductPhoto } from '../shared/productPhotoFit';

const BLOCKER_MESSAGES: Record<CutoutBlocker, string> = {
    old_browser: 'ppc_unsupported',
    no_graphics: 'ppc_no_graphics',
    low_memory: 'ppc_low_memory',
    tool_missing: 'ppc_tool_missing',
    no_connection: 'ppc_no_connection',
};

interface ProductPhotoCutoutProps {
    /** The photo chosen in this form, if any. */
    photo: File | null;
    /** The photo already saved on the product, used when no new one is chosen. */
    savedPhotoUrl?: string | null;
    disabled?: boolean;
    onUse: (cutout: File, original: File) => void;
    onUndo: () => void;
}

type Comparison = { original: File; originalUrl: string; cutout: File; cutoutUrl: string };

type Status =
    | { name: 'idle' }
    | { name: 'busy'; progress: CutoutProgress }
    | { name: 'review'; comparison: Comparison }
    | { name: 'in_use' }
    | { name: 'failed' };

async function downloadSavedPhoto(url: string): Promise<File> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) {
        throw new Error('The saved photo could not be loaded.');
    }
    const blob = await response.blob();
    const extension = { 'image/png': 'png', 'image/webp': 'webp' }[blob.type] ?? 'jpg';

    return new File([blob], `product-photo.${extension}`, { type: blob.type });
}

export default function ProductPhotoCutout({ photo, savedPhotoUrl = null, disabled = false, onUse, onUndo }: ProductPhotoCutoutProps) {
    const translate = useT();
    const [availability, setAvailability] = useState<CutoutAvailability | null>(null);
    const [status, setStatus] = useState<Status>({ name: 'idle' });
    const currentRun = useRef(0);
    const previewUrls = useRef<string[]>([]);
    const hasPhoto = Boolean(photo || savedPhotoUrl);

    const forgetPreviews = () => {
        previewUrls.current.forEach((url) => URL.revokeObjectURL(url));
        previewUrls.current = [];
    };

    useEffect(() => {
        if (!hasPhoto) {
            return;
        }
        let stillWanted = true;
        void checkCutoutAvailability().then((result) => {
            if (stillWanted) {
                setAvailability(result);
            }
        });

        return () => {
            stillWanted = false;
        };
    }, [hasPhoto]);

    useEffect(() => {
        currentRun.current += 1;
        forgetPreviews();
        setStatus({ name: 'idle' });
    }, [photo, savedPhotoUrl]);

    useEffect(() => () => {
        currentRun.current += 1;
        forgetPreviews();
        releaseCutoutTool();
    }, []);

    const removeBackground = async (precision: CutoutPrecision) => {
        currentRun.current += 1;
        const run = currentRun.current;
        setStatus({ name: 'busy', progress: { stage: 'getting_ready' } });
        try {
            const original = photo ?? await downloadSavedPhoto(savedPhotoUrl as string);
            // The result is saved as the product photo, so it has to fit the server's limits like any other.
            const fit = await fitProductPhoto(await removeProductPhotoBackground(original, precision, (progress) => {
                if (run === currentRun.current) {
                    setStatus({ name: 'busy', progress });
                }
            }));
            if (run !== currentRun.current) {
                return;
            }
            if (fit.outcome === 'too_large') {
                throw new Error('The photo without background is too large to save.');
            }
            const cutout = fit.photo;
            const originalUrl = URL.createObjectURL(original);
            const cutoutUrl = URL.createObjectURL(cutout);
            previewUrls.current.push(originalUrl, cutoutUrl);
            setStatus({ name: 'review', comparison: { original, originalUrl, cutout, cutoutUrl } });
        } catch {
            if (run === currentRun.current) {
                setStatus({ name: 'failed' });
            }
        }
    };

    const applyCutout = (comparison: Comparison) => {
        onUse(comparison.cutout, comparison.original);
        setStatus({ name: 'in_use' });
    };

    if (!hasPhoto || availability === null) {
        return null;
    }

    // Never hide the tool without a word: say why it cannot run here, and that the photo can go up as it is.
    if (!availability.ready) {
        return <p className="ppc-note" role="status">{translate('portal', BLOCKER_MESSAGES[availability.reason])}</p>;
    }
    const precision = availability.precision;

    const progressText = (progress: CutoutProgress): string => {
        if (progress.stage === 'downloading') {
            return translate('portal', 'ppc_downloading', { percent: String(progress.percent) });
        }

        return translate('portal', progress.stage === 'working' ? 'ppc_working' : 'ppc_getting_ready');
    };

    return (
        <div className="ppc">
            {(status.name === 'idle' || status.name === 'failed') && (
                <div className="ppc-row">
                    <button type="button" className="ppc-button" disabled={disabled} onClick={() => void removeBackground(precision)}>
                        <Scissors size={15} aria-hidden="true" />
                        {translate('portal', 'ppc_remove_background')}
                    </button>
                    {status.name === 'idle' && <span className="ppc-note">{translate('portal', 'ppc_hint')}</span>}
                    {status.name === 'failed' && <span className="ppc-note is-error" role="alert">{translate('portal', 'ppc_failed')}</span>}
                </div>
            )}

            {status.name === 'busy' && (
                <div className="ppc-row" role="status" aria-live="polite">
                    <span className="ppc-spinner" aria-hidden="true" />
                    <span className="ppc-note">{progressText(status.progress)}</span>
                </div>
            )}

            {status.name === 'review' && (
                <>
                    <div className="ppc-compare">
                        <figure>
                            <figcaption>{translate('portal', 'ppc_your_photo')}</figcaption>
                            <div className="ppc-panel"><img src={status.comparison.originalUrl} alt={translate('portal', 'ppc_your_photo')} /></div>
                        </figure>
                        <figure>
                            <figcaption>{translate('portal', 'ppc_without_background')}</figcaption>
                            <div className="ppc-panel"><img src={status.comparison.cutoutUrl} alt={translate('portal', 'ppc_without_background')} /></div>
                        </figure>
                    </div>
                    <div className="ppc-row">
                        <button type="button" className="ppc-button is-primary" onClick={() => applyCutout(status.comparison)}>{translate('portal', 'ppc_use_this')}</button>
                        <button type="button" className="ppc-button" onClick={() => setStatus({ name: 'idle' })}>{translate('portal', 'ppc_keep_mine')}</button>
                    </div>
                </>
            )}

            {status.name === 'in_use' && (
                <div className="ppc-row">
                    <span className="ppc-note">{translate('portal', 'ppc_in_use')}</span>
                    <button type="button" className="ppc-link" onClick={() => {
                        onUndo();
                        setStatus({ name: 'idle' });
                    }}>{translate('portal', 'ppc_undo')}</button>
                </div>
            )}
        </div>
    );
}
