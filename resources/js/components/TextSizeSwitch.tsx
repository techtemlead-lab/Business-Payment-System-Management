import { useEffect, useState } from 'react';
import { useT } from '../shared/i18n/t';
import { applyTextSize, persistTextSize, resolveTextSize, TEXT_SIZES, type TextSize } from '../shared/textSize';

/** Four "A" buttons of growing size with a word beside them, so older readers can make the text bigger in one tap. */
export default function TextSizeSwitch({ place = 'header' }: { place?: 'header' | 'menu' }) {
    const translate = useT();
    const [size, setSize] = useState<TextSize>('normal');

    useEffect(() => {
        const current = resolveTextSize();
        applyTextSize(current);
        setSize(current);
    }, []);

    return (
        <div className={`text-size-switch in-${place}`} role="group" aria-label={translate('partner_ux', 'text_size')}>
            <span className="text-size-label">{translate('partner_ux', 'text_size_short')}</span>
            {TEXT_SIZES.map((option) => (
                <button
                    key={option}
                    type="button"
                    className={`text-size-${option}${option === size ? ' active' : ''}`}
                    aria-pressed={option === size}
                    aria-label={translate('partner_ux', `text_size_${option}`)}
                    title={translate('partner_ux', `text_size_${option}`)}
                    onClick={() => {
                        persistTextSize(option);
                        setSize(option);
                    }}
                >
                    A
                </button>
            ))}
        </div>
    );
}
