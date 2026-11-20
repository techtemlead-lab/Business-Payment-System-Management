import React from 'react';
import { useT } from '../../shared/i18n/t';
import type { BuyerType } from './shared';

/** The "ডিলার / সাব-ডিলার" switch used on the page, in both modals and on the calculator. */
export default function BuyerSwitch({ value, onChange, label }: { value: BuyerType; onChange: (type: BuyerType) => void; label: string }) {
    const translate = useT();

    return (
        <div className="cp-switch" role="group" aria-label={label}>
            {(['dealer', 'sub_dealer'] as const).map((type) => (
                <button key={type} type="button" aria-pressed={value === type} className={value === type ? 'is-on' : ''} onClick={() => onChange(type)}>
                    {translate('commission_page', type === 'dealer' ? 'buyer_dealer' : 'buyer_sub_dealer')}
                </button>
            ))}
        </div>
    );
}
