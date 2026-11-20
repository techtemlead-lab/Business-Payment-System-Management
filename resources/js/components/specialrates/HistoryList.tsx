import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { Modal, dateTime } from '../UI';
import { root } from '../office/operationsShared';
import type { ScheduleItem } from './types';

type Action = { kind: 'approved' | 'rejected' | 'cancelled' | 'end'; item: ScheduleItem };

const CHIP: Record<string, string> = { draft: 'is-draft', active: 'is-live', upcoming: 'is-live', ended: '', expired: '', rejected: 'is-bad', cancelled: '' };

/** The current and past special rates, and the drafts waiting for the owner, each with only the actions the viewer may take. */
export default function HistoryList({ items, title, hint, showPerson, empty }: { items: ScheduleItem[]; title: string; hint?: string; showPerson?: boolean; empty: string }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const [action, setAction] = useState<Action | null>(null);

    return (
        <section className="cp-card" aria-labelledby="sr-history-title">
            <div className="cp-card-head">
                <div>
                    <h2 id="sr-history-title">{title}</h2>
                    {hint && <p className="cp-hint">{hint}</p>}
                </div>
            </div>
            {items.length === 0 ? <p className="cp-empty">{empty}</p> : (
                <ul className="sr-history">
                    {items.map((item) => (
                        <li key={item.id} className={`sr-item state-${item.state}`}>
                            <div className="sr-item-head">
                                <span className={`cp-pill ${CHIP[item.state] ?? ''}`}>{t(`state_${item.state}`)}</span>
                                <b>{t(`scope_${item.scope}`)}</b>
                                {showPerson && item.person_name && <span className="sr-person">{item.person_name}</span>}
                            </div>
                            {item.lines.length > 0 && <ul className="sr-lines">{item.lines.map((line) => <li key={line}>{line}</li>)}</ul>}
                            <p className="sr-meta">
                                {t('item_from', { date: dateTime(item.effective_at) })} · {item.expires_at ? t('item_until', { date: dateTime(item.expires_at) }) : t('item_open')}
                                {item.proposed_by_name && ` · ${t('item_by', { name: item.proposed_by_name })}`}
                            </p>
                            {item.reason && <p className="sr-meta">{t('item_reason', { reason: item.reason })}</p>}
                            {item.review_reason && <p className="sr-meta">{t('item_review', { name: item.reviewed_by_name ?? '', reason: item.review_reason })}</p>}
                            {(item.can_review || item.can_cancel || item.can_end) && (
                                <div className="sr-actions">
                                    {item.can_review && <button type="button" className="button dark" onClick={() => setAction({ kind: 'approved', item })}>{t('act_approve')}</button>}
                                    {item.can_review && <button type="button" className="button" onClick={() => setAction({ kind: 'rejected', item })}>{t('act_reject')}</button>}
                                    {item.can_cancel && <button type="button" className="button" onClick={() => setAction({ kind: 'cancelled', item })}>{t('act_cancel')}</button>}
                                    {item.can_end && <button type="button" className="button" onClick={() => setAction({ kind: 'end', item })}>{t('act_end')}</button>}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {action && <ActionModal action={action} onClose={() => setAction(null)} />}
        </section>
    );
}

function ActionModal({ action, onClose }: { action: Action; onClose: () => void }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const reasonField = useReasonField('money.policy_schedule_review');
    const [reason, setReason] = useState('');
    const [endMode, setEndMode] = useState<'now' | 'date' | 'open'>('now');
    const [end, setEnd] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [tried, setTried] = useState(false);
    const isEnd = action.kind === 'end';
    const reasonMissing = reasonField.required && reason.trim().length < 5;
    const endMissing = isEnd && endMode === 'date' && end === '';

    const send = () => {
        setTried(true);
        setError('');
        if (reasonMissing || endMissing) {
            return;
        }
        setSaving(true);
        const url = `${root}/policy-schedules/${action.item.id}/${isEnd ? 'end' : 'review'}`;
        const payload = isEnd
            ? { mode: endMode === 'now' ? 'stop_now' : 'set_end', ...(endMode === 'date' ? { expires_at: end } : {}), ...(reasonField.shown ? { reason } : {}) }
            : { decision: action.kind, ...(reasonField.shown ? { reason } : {}) };
        router.post(url, payload as never, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Modal title={t(`modal_${action.kind}`)} onClose={onClose} footer={(
            <div className="cp-modal-foot">
                <button type="button" className="button" onClick={onClose}>{t('cancel')}</button>
                <button type="button" className="button dark" disabled={saving} onClick={send}>{saving ? t('saving') : t(`do_${action.kind}`)}</button>
            </div>
        )}>
            <div className="cp-modal">
                <ul className="sr-lines">{action.item.lines.map((line) => <li key={line}>{line}</li>)}</ul>
                {isEnd && (
                    <fieldset className="sr-when">
                        <legend>{t('until_label')}</legend>
                        <label className="sr-radio"><input type="radio" name="end-mode" checked={endMode === 'now'} onChange={() => setEndMode('now')} />{t('end_now_option')}</label>
                        <label className="sr-radio"><input type="radio" name="end-mode" checked={endMode === 'date'} onChange={() => setEndMode('date')} />{t('until_date_option')}</label>
                        {endMode === 'date' && <input type="datetime-local" value={end} onChange={(event) => setEnd(event.target.value)} aria-label={t('until_label')} />}
                        <label className="sr-radio"><input type="radio" name="end-mode" checked={endMode === 'open'} onChange={() => setEndMode('open')} />{t('until_open_option')}</label>
                    </fieldset>
                )}
                {reasonField.shown && (
                    <label className="cp-label">
                        {reasonField.label(t('reason_label'))}
                        <input type="text" maxLength={1000} required={reasonField.required} value={reason} onChange={(event) => setReason(event.target.value)} />
                    </label>
                )}
                {tried && reasonMissing && <p className="cp-error" role="alert">{t('err_reason')}</p>}
                {tried && endMissing && <p className="cp-error" role="alert">{t('err_end')}</p>}
                {error !== '' && <p className="cp-error" role="alert">{error}</p>}
            </div>
        </Modal>
    );
}
