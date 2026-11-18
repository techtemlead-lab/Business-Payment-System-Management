import React from 'react';
import { useT } from '../../shared/i18n/t';

/**
 * The transfer proof for a payout: a file, a photo from the phone camera, or a screenshot pasted
 * with Ctrl+V, so the payer is not stuck when no file chooser works on their machine (MA-06).
 */
export default function ReceiptPicker({ id, label, required, file, onChange }: { id: string; label: string; required: boolean; file: File | null; onChange: (file: File | null) => void }) {
    const translate = useT();
    const onPaste = (event: React.ClipboardEvent<HTMLDivElement>) => {
        const pasted = Array.from(event.clipboardData.files).find((item) => ['image/png', 'image/jpeg', 'application/pdf'].includes(item.type));
        if (pasted) {
            event.preventDefault();
            const extension = pasted.type === 'application/pdf' ? 'pdf' : pasted.type === 'image/png' ? 'png' : 'jpg';
            onChange(new File([pasted], pasted.name && pasted.name !== 'image.png' ? pasted.name : `receipt-${Date.now()}.${extension}`, { type: pasted.type }));
        }
    };

    return (
        <div className="hub-field" onPaste={onPaste} tabIndex={-1}>
            <label htmlFor={id}>{label}</label>
            <input
                id={id}
                type="file"
                required={required && !file}
                accept="application/pdf,image/jpeg,image/png"
                onChange={(event) => onChange(event.target.files?.[0] ?? null)}
            />
            <small className="hub-hint">{translate('payouts', 'receipt_paste_hint')}</small>
            {file && <small className="hub-hint" role="status">{translate('payouts', 'receipt_pasted', { name: file.name })}</small>}
        </div>
    );
}
