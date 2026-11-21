import React from 'react';
import { money } from '../UI';
import { useT } from '../../shared/i18n/t';

export interface FundCheckRow {
    target: string;
    rate: string;
    fund_at_target: string;
    award: string | null;
    status: 'covers' | 'short' | 'not_set';
    shortfall: string | null;
    break_even_sales: string | null;
}

/** Read-only: does the money the bike and car fund collects at the target cover the award? */
export default function FundCheckPanel({ check }: { check?: Record<'bike' | 'car', FundCheckRow> }) {
    const translate = useT();
    if (!check) {
        return null;
    }
    const statusText = (row: FundCheckRow) => (row.status === 'covers'
        ? translate('reward_settings', 'fund_check_covers')
        : row.status === 'short'
            ? translate('reward_settings', 'fund_check_short', { amount: money(row.shortfall, { symbol: false }) })
            : translate('reward_settings', 'fund_check_award_missing'));

    return (
        <section className="cp-card reward-fund-check" aria-labelledby="cp-fund-title">
            <h2 id="cp-fund-title">{translate('reward_settings', 'fund_check_title')}</h2>
            <p className="cp-hint">{translate('reward_settings', 'fund_check_intro')}</p>
            <div className="cp-fund-grid">
                {(['bike', 'car'] as const).map((program) => {
                    const row = check[program];

                    return (
                        <div key={program} className="cp-fund">
                            <h3>{translate('reward_settings', `fund_check_${program}`)}</h3>
                            <dl>
                                <div><dt>{translate('reward_settings', 'fund_check_target')}</dt><dd>{money(row.target)}</dd></div>
                                <div><dt>{translate('reward_settings', 'fund_check_rate')}</dt><dd>{row.rate}%</dd></div>
                                <div><dt>{translate('reward_settings', 'fund_check_at_target')}</dt><dd>{money(row.fund_at_target)}</dd></div>
                                <div><dt>{translate('reward_settings', 'fund_check_award')}</dt><dd>{row.award === null ? translate('reward_settings', 'fund_check_not_set') : money(row.award)}</dd></div>
                                {row.break_even_sales !== null && <div><dt>{translate('reward_settings', 'fund_check_break_even')}</dt><dd>{money(row.break_even_sales)}</dd></div>}
                            </dl>
                            <p className={`cp-pill ${row.status === 'covers' ? 'is-live' : row.status === 'short' ? 'is-bad' : 'is-draft'}`} data-status={row.status}>{statusText(row)}</p>
                        </div>
                    );
                })}
            </div>
            <p className="cp-hint">{translate('reward_settings', 'fund_check_note')}</p>
        </section>
    );
}
