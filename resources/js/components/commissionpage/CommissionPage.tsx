import React, { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import { number } from '../UI';
import type { OfficeHub } from '../OfficeHubTabs';
import RatesTable from './RatesTable';
import ChangeRatesModal from './ChangeRatesModal';
import NewCommissionModal from './NewCommissionModal';
import ConditionsSection, { ConditionsModal } from './ConditionsSection';
import CommissionBottomNav from './CommissionBottomNav';
import FundCheckPanel, { type FundCheckRow } from './FundCheckPanel';
import type { BuyerType, CommissionPageData } from './shared';

type OpenModal = 'rates' | 'new' | 'conditions' | null;

/**
 * "কমিশন রুলস অ্যান্ড রেট": the commissions as one table, the conditions to receive them, and buttons to change
 * either. The numbers are read from the live commission rules; nothing here changes how a commission is paid.
 */
export default function CommissionPage({ page, hub, fundCheck }: { page?: CommissionPageData; hub?: OfficeHub; fundCheck?: Record<'bike' | 'car', FundCheckRow> }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('commission_page', key, replace);
    const { staffActions } = usePage<any>().props;
    const [modal, setModal] = useState<OpenModal>(null);
    const [mode, setMode] = useState<BuyerType>('dealer');

    if (!page) {
        return <div className="cp-page"><p className="cp-empty" role="status">{t('loading')}</p></div>;
    }
    // Both the person's finance permission and the server's own check decide; the server checks again on save.
    const canEdit = page.workflow.can_edit && (staffActions?.finance?.edit ?? true);
    const waiting = page.workflow.drafts_waiting;

    return (
        <div className="cp-page">
            <header className="cp-head">
                <h1>{t('page_title')}</h1>
                <p>{t('page_lead')}</p>
            </header>

            <section className="cp-card" aria-labelledby="cp-rates-title">
                <div className="cp-card-head">
                    <div>
                        <h2 id="cp-rates-title">{t('section_rates')}</h2>
                        <p className="cp-hint">{t('rates_lead')}</p>
                    </div>
                </div>
                <p className="cp-state" role="status">
                    <span className="cp-pill is-live">{t('status_active')}</span>
                    {t('status_active_line')}
                    {waiting > 0 && (
                        <>
                            {' '}
                            <span className="cp-pill is-draft">{t('status_draft')}</span>
                            {t('status_draft_line', { count: number(waiting) })}{' '}
                            <a className="text-link" href="/operations/policy-schedules">{t('status_draft_link')}</a>
                        </>
                    )}
                </p>
                {!canEdit && <p className="cp-hint" role="note">{t('view_only')}</p>}
                <RatesTable heads={page.heads} totals={page.totals} />
                {canEdit && (
                    <div className="cp-actions">
                        <button type="button" className="button dark" onClick={() => setModal('rates')}>{t('btn_change_rates')}</button>
                        <button type="button" className="button" onClick={() => setModal('new')}>{t('btn_new')}</button>
                    </div>
                )}
            </section>

            <ConditionsSection conditions={page.conditions} canEdit={canEdit} mode={mode} onMode={setMode} onChange={() => setModal('conditions')} />

            <FundCheckPanel check={fundCheck} />

            <CommissionBottomNav hub={hub} />

            {modal === 'rates' && <ChangeRatesModal page={page} startWith={mode} onClose={() => setModal(null)} />}
            {modal === 'new' && <NewCommissionModal onClose={() => setModal(null)} />}
            {modal === 'conditions' && <ConditionsModal conditions={page.conditions} startWith={mode} appliesAtOnce={page.workflow.applies_at_once} onClose={() => setModal(null)} />}
        </div>
    );
}
