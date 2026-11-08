import React from 'react';
import { Link } from '@inertiajs/react';
import { BellRing, Phone } from 'lucide-react';
import { date, money, number } from '../UI';
import { useT } from '../../shared/i18n/t';

export interface PartnerAlert {
    kind: string;
    values: Record<string, string | number>;
}

export interface InactivePartner {
    id: number;
    name: string;
    dealer_code: string | null;
    phone: string;
    last_active_on: string;
}

const alertHref: Record<string, string> = {
    salary_at_risk: '/purchase',
    rank_keep_at_risk: '/purchase',
    rank_close_purchases: '/purchase',
    rank_close_team: '/network',
    inactive_partners: '/network',
};

/** Values ready for the sentence: taka as money, and ranks by their full name. */
function readableValues(alert: PartnerAlert, translate: (group: string, key: string, replace?: Record<string, string>) => string): Record<string, string> {
    const values: Record<string, string> = {};
    Object.entries(alert.values).forEach(([key, value]) => {
        if (key === 'left' && alert.kind !== 'rank_close_team') {
            values[key] = money(value);
        } else if (key === 'rank' || key === 'required') {
            values[key] = translate('portal', `net_rank_${value}`);
        } else {
            values[key] = number(value);
        }
    });
    return values;
}

/** Things to act on: at-risk targets, a rank that is nearly reached, and direct partners who have gone quiet. */
export default function PartnerAlertsCard({ alerts, inactivePartners }: { alerts: PartnerAlert[]; inactivePartners: InactivePartner[] }) {
    const translate = useT();
    if (alerts.length === 0) {
        return null;
    }
    return (
        <section className="ph-card" data-testid="partner-alerts" aria-label={translate('dealer_tools', 'alerts_title')}>
            <div className="ph-card-head">
                <div>
                    <h3>{translate('dealer_tools', 'alerts_title')}</h3>
                    <p>{translate('dealer_tools', 'alerts_hint')}</p>
                </div>
                <BellRing size={18} />
            </div>
            <ul className="ptl-list-plain">
                {alerts.map((alert) => {
                    const values = readableValues(alert, translate);
                    return (
                        <li key={alert.kind}>
                            <Link className="record-link" href={alertHref[alert.kind] ?? '/'}>
                                <b>{translate('dealer_tools', `alert_${alert.kind}_title`, values)}</b>
                            </Link>
                            <p>{translate('dealer_tools', `alert_${alert.kind}_body`, values)}</p>
                        </li>
                    );
                })}
            </ul>
            {inactivePartners.length > 0 && (
                <>
                    <h4>{translate('dealer_tools', 'inactive_list_title')}</h4>
                    <ul className="ptl-list-plain">
                        {inactivePartners.map((partner) => (
                            <li key={partner.id}>
                                <b>{partner.name}</b>
                                {partner.dealer_code ? <small> · {partner.dealer_code}</small> : null}
                                <small> · {translate('dealer_tools', 'inactive_since', { date: date(partner.last_active_on) })}</small>
                                {partner.phone ? <a className="text-link" href={`tel:${partner.phone}`}> <Phone size={13} /> {partner.phone}</a> : null}
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}
