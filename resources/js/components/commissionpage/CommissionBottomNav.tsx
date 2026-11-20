import React from 'react';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import type { OfficeHub } from '../OfficeHubTabs';

/** The same pages as the tab strip at the top, as buttons at the bottom, so nobody has to scroll back up. */
export default function CommissionBottomNav({ hub }: { hub?: OfficeHub | null }) {
    const translate = useT();
    const others = (hub?.tabs ?? []).filter((tab) => !tab.active);
    if (others.length === 0) {
        return null;
    }

    return (
        <nav className="cp-bottom" aria-label={translate('commission_page', 'bottom_label')}>
            <p>{translate('commission_page', 'bottom_lead')}</p>
            <div>
                {others.map((tab) => (
                    <Link key={tab.key} href={tab.href} className="button">
                        {tab.label}
                        <ArrowRight size={16} aria-hidden="true" />
                    </Link>
                ))}
            </div>
        </nav>
    );
}
