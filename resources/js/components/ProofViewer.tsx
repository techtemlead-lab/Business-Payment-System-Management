import { useEffect, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { Download, ExternalLink, X, ZoomIn, ZoomOut } from 'lucide-react';
import { useT } from '../shared/i18n/t';

/**
 * How a screenshot, receipt or scanned paper opens:
 *  - image: in a pop-up on the same page (ProofViewer);
 *  - pdf:   in a new tab, where the browser shows it;
 *  - file:  anything else; the server sends it as a download;
 *  - auto:  the page does not know the type; it is tried as a picture and, if the browser cannot show it,
 *           the pop-up offers "open in a new tab" and "download".
 */
export type ProofKind = 'image' | 'pdf' | 'file' | 'auto';

/** Works out the kind from a mime type ("image/png") or a file name ("slip.pdf"); no hint means 'auto'. */
export function proofKindOf(hint?: string | null): ProofKind {
    const text = String(hint ?? '').trim().toLowerCase();
    if (text === '') {
        return 'auto';
    }
    if (text === 'application/pdf' || text.endsWith('.pdf')) {
        return 'pdf';
    }
    if (text.startsWith('image/svg') || text.endsWith('.svg')) {
        return 'file';
    }
    if (text.startsWith('image/') || /\.(jpe?g|png|webp|gif)$/.test(text)) {
        return 'image';
    }
    if (text.includes('/') || /\.[a-z0-9]{2,5}$/.test(text)) {
        return 'file';
    }

    return 'auto';
}

/** The same file as a saved download: the server sends it with "attachment" when ?download=1 is added. */
export function proofDownloadUrl(href: string): string {
    return href + (href.includes('?') ? '&' : '?') + 'download=1';
}

/** Pop-up that shows one picture large. Tap outside, the cross or Esc closes it; the open-in-tab and download links stay in view. */
export function ProofViewer({ href, title, onClose }: { href: string; title?: string; onClose: () => void }) {
    const translate = useT();
    const ref = useRef<HTMLDialogElement>(null);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;
    const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>('loading');
    const [zoomed, setZoomed] = useState(false);

    useEffect(() => {
        const dialog = ref.current;
        // The page keeps focus here again after closing, so the person is not thrown to the top of the screen.
        const previous = document.activeElement as HTMLElement | null;
        dialog?.showModal();
        dialog?.querySelector<HTMLElement>('.pv-close')?.focus();

        return () => {
            dialog?.close();
            previous?.focus?.();
        };
    }, []);

    const onBackdrop = (event: MouseEvent<HTMLDialogElement>) => {
        if (event.target === ref.current) {
            onCloseRef.current();
        }
    };

    return (
        <dialog
            ref={ref}
            className="proof-viewer"
            aria-label={title ?? translate('portal', 'proof_viewer_title')}
            onClick={onBackdrop}
            onCancel={(event) => {
                event.preventDefault();
                onCloseRef.current();
            }}
        >
            <div className="pv-card">
                <div className="pv-bar">
                    <h2 className="pv-title">{title ?? translate('portal', 'proof_viewer_title')}</h2>
                    <button type="button" className="pv-close" aria-label={translate('portal', 'close_dialog')} title={translate('portal', 'close_dialog')} onClick={() => onCloseRef.current()}>
                        <X size={20} aria-hidden="true" />
                    </button>
                    <div className="pv-actions">
                        {status === 'ready' && (
                            <button type="button" className="pv-action" aria-pressed={zoomed} onClick={() => setZoomed((value) => !value)}>
                                {zoomed ? <ZoomOut size={18} aria-hidden="true" /> : <ZoomIn size={18} aria-hidden="true" />}
                                {translate('portal', zoomed ? 'proof_zoom_out' : 'proof_zoom_in')}
                            </button>
                        )}
                        <a className="pv-action" href={href} target="_blank" rel="noopener">
                            <ExternalLink size={18} aria-hidden="true" />{translate('portal', 'proof_open_new_tab')}
                        </a>
                        <a className="pv-action" href={proofDownloadUrl(href)} download>
                            <Download size={18} aria-hidden="true" />{translate('portal', 'proof_download')}
                        </a>
                    </div>
                </div>
                <div className={`pv-stage${zoomed ? ' is-zoomed' : ''}`}>
                    {status === 'loading' && <p className="pv-note" role="status">{translate('portal', 'proof_loading')}</p>}
                    {status === 'failed' && <p className="pv-note" role="alert">{translate('portal', 'proof_failed')}</p>}
                    {status !== 'failed' && (
                        <img
                            src={href}
                            alt={translate('portal', 'proof_image_alt')}
                            className="pv-image"
                            onLoad={() => setStatus('ready')}
                            onError={() => setStatus('failed')}
                            onClick={() => status === 'ready' && setZoomed((value) => !value)}
                        />
                    )}
                </div>
            </div>
        </dialog>
    );
}

/**
 * The one link for a screenshot, receipt or scanned paper. A picture opens in the pop-up, a PDF in a new
 * tab, anything else downloads. The link keeps its address, so a long press or a middle click still works.
 */
export function ProofLink({ href, kind = 'auto', title, className, children }: { href: string; kind?: ProofKind; title?: string; className?: string; children: ReactNode }) {
    const [open, setOpen] = useState(false);
    const popup = kind === 'image' || kind === 'auto';

    const onClick = (event: MouseEvent<HTMLAnchorElement>) => {
        if (!popup || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        setOpen(true);
    };

    return (
        <>
            <a className={className} href={href} target={kind === 'file' ? undefined : '_blank'} rel="noopener" onClick={onClick}>{children}</a>
            {open && <ProofViewer href={href} title={title} onClose={() => setOpen(false)} />}
        </>
    );
}
