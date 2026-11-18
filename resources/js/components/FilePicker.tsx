import { useRef, useState } from 'react';
import { useT } from '../shared/i18n/t';

/**
 * A file chooser that speaks the page's language. The browser's own "Choose file / No file chosen"
 * follows the phone's language, which left English words inside Bangla screens.
 */
export default function FilePicker({ name, accept, required, disabled, onPick }: {
    name?: string;
    accept?: string;
    required?: boolean;
    disabled?: boolean;
    onPick?: (file: File | null) => void;
}) {
    const translate = useT();
    const input = useRef<HTMLInputElement>(null);
    const [fileName, setFileName] = useState('');

    return (
        <span className="file-picker">
            <input
                ref={input}
                className="file-picker-input"
                type="file"
                name={name}
                accept={accept}
                required={required}
                disabled={disabled}
                onChange={(event) => {
                    const file = event.target.files?.[0] ?? null;
                    setFileName(file?.name ?? '');
                    onPick?.(file);
                }}
            />
            <button type="button" className="button small" disabled={disabled} onClick={() => input.current?.click()}>
                {translate('partner_ux', fileName ? 'file_change' : 'file_choose')}
            </button>
            <span className="file-picker-name">{fileName || translate('partner_ux', 'file_none')}</span>
        </span>
    );
}
