import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Modal } from '../UI';
import { useT } from '../../shared/i18n/t';
import { toBasisPoints } from './shared';

/** Mirrors CommissionPageController::NewHeadKinds. */
const KINDS = ['cashback', 'referral', 'd2d', 'area', 'salary', 'rank', 'incentive', 'marketing', 'rewards', 'other'];

/**
 * Add a new commission. The engine pays only the commissions written into it, so this files a written
 * draft for the office to review; it changes no live rate and pays nobody.
 */
export default function NewCommissionModal({ onClose }: { onClose: () => void }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('commission_page', key, replace);
    const [name, setName] = useState('');
    const [kind, setKind] = useState('other');
    const [dealer, setDealer] = useState('0');
    const [sub, setSub] = useState('0');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [tried, setTried] = useState(false);

    const dealerPoints = toBasisPoints(dealer);
    const subPoints = toBasisPoints(sub);
    const problem = name.trim() === '' ? 'err_name' : dealerPoints === null || subPoints === null ? 'err_percent' : dealerPoints + subPoints === 0 ? 'err_new_head_rate' : '';

    const save = () => {
        setTried(true);
        setError('');
        if (problem !== '') {
            return;
        }
        setSaving(true);
        router.post('/commands/commissions/new-head', { name: name.trim(), kind, dealer_pct: dealer.trim(), sub_dealer_pct: sub.trim() }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onFinish: () => setSaving(false),
        });
    };

    const footer = (
        <div className="cp-modal-foot">
            <button type="button" className="button" onClick={onClose}>{t('cancel')}</button>
            <button type="button" className="button dark" disabled={saving} onClick={save}>{saving ? t('saving') : t('new_save')}</button>
        </div>
    );

    return (
        <Modal title={t('new_title')} onClose={onClose} footer={footer}>
            <div className="cp-modal">
                <p className="cp-hint">{t('new_note')}</p>
                <label className="cp-label">
                    {t('new_name')}
                    <input type="text" maxLength={80} value={name} onChange={(event) => setName(event.target.value)} />
                </label>
                <label className="cp-label">
                    {t('col_kind')}
                    <select value={kind} onChange={(event) => setKind(event.target.value)}>
                        {KINDS.map((item) => <option key={item} value={item}>{t(`kind_${item}`)}</option>)}
                    </select>
                </label>
                <div className="cp-two">
                    <label className="cp-label">
                        {t('new_dealer_pct')}
                        <span className="cp-field"><input type="text" inputMode="decimal" autoComplete="off" value={dealer} onChange={(event) => setDealer(event.target.value)} /><span aria-hidden="true">%</span></span>
                    </label>
                    <label className="cp-label">
                        {t('new_sub_pct')}
                        <span className="cp-field"><input type="text" inputMode="decimal" autoComplete="off" value={sub} onChange={(event) => setSub(event.target.value)} /><span aria-hidden="true">%</span></span>
                    </label>
                </div>
                {tried && problem !== '' && <p className="cp-error" role="alert">{t(problem)}</p>}
                {error && <p className="cp-error" role="alert">{error}</p>}
            </div>
        </Modal>
    );
}
