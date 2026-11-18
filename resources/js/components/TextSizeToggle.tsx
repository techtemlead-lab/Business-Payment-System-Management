import { useEffect, useState } from 'react';
import { useT } from '../shared/i18n/t';
import { applyTextSize, panelTextSize, persistTextSize, resolveTextSize, type TextSize } from '../shared/textSize';

/** A word-labelled switch between normal and larger writing, for the staff and owner panels (shared setting in shared/textSize). */
export default function TextSizeToggle() {
    const translate = useT();
    const [size, setSize] = useState<TextSize>('normal');

    useEffect(() => {
        const current = panelTextSize(resolveTextSize());
        applyTextSize(current);
        setSize(current);
    }, []);

    const large = size === 'large';
    const label = translate('office_shell', large ? 'text_size_normal' : 'text_size_large');

    return (
        <button
            type="button"
            className="text-size-toggle"
            aria-pressed={large}
            title={label}
            onClick={() => {
                const next: TextSize = large ? 'normal' : 'large';
                persistTextSize(next);
                setSize(next);
            }}
        >
            <span aria-hidden="true">{large ? 'A−' : 'A+'}</span>
            <span className="text-size-label">{label}</span>
        </button>
    );
}
