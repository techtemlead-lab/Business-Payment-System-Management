import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { localizeDigits } from '../../business-date';
import { root } from '../office/operationsShared';
import type { Person, SpecialData } from './types';

/** "2026-11-21T10:30" as people write it, in Bangla digits on Bangla pages. */
const shownDate = (value: string): string => localizeDigits(value.replace('T', ' '));

/**
 * From when, until when, why, a plain sentence of what will happen, and the button. A special rate is proposed
 * through the engine's own schedule action, so the owner's approval and the audit log stay as they are.
 */
export default function ScheduleBox({ scope, person, rules, lines, workflow, replaces }: {
    scope: 'recipient' | 'buyer';
    person: Person;
    rules: Record<string, unknown>;
    lines: string[];
    workflow: SpecialData['workflow'];
    /** A special rate of this kind is running now; the new one replaces it. */
    replaces: boolean;
}) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const reasonField = useReasonField('money.policy_schedule');
    const [startMode, setStartMode] = useState<'now' | 'date'>('now');
    const [start, setStart] = useState('');
    const [endMode, setEndMode] = useState<'open' | 'date'>('open');
    const [end, setEnd] = useState('');
    const [reason, setReason] = useState('');
    const [tried, setTried] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const startTime = startMode === 'date' && start !== '' ? new Date(start).getTime() : Date.now();
    const endTime = endMode === 'date' && end !== '' ? new Date(end).getTime() : null;
    const problem = Object.keys(rules).length === 0 ? 'err_nothing'
        : startMode === 'date' && (start === '' || startTime < Date.now() - 60000) ? 'err_start'
            : endMode === 'date' && (end === '' || (endTime !== null && endTime <= startTime)) ? 'err_end'
                : reasonField.required && reason.trim().length < 5 ? 'err_reason' : '';
    const workText = workflow.applies_at_once ? t('after_owner') : t('after_staff');
    const sentence = t('summary', {
        from: startMode === 'now' ? t('from_now') : shownDate(start || '…'),
        name: person.name,
        until: endMode === 'open' ? t('until_open') : t('until_date', { date: shownDate(end || '…') }),
    });

    const submit = () => {
        setTried(true);
        setError('');
        if (problem !== '' || !workflow.can_propose) {
            return;
        }
        setSaving(true);
        router.post(`${root}/policy-schedules`, {
            scope,
            user_id: person.id,
            rules: rules as never,
            effective_at: startMode === 'now' ? new Date().toISOString() : start,
            ...(endMode === 'date' ? { expires_at: end } : {}),
            ...(reasonField.shown ? { reason } : {}),
        }, {
            preserveScroll: true,
            onSuccess: () => { setReason(''); setTried(false); },
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="sr-schedule">
            <h3>{t('when_title')}</h3>
            <div className="sr-when">
                <fieldset>
                    <legend>{t('from_label')}</legend>
                    <label className="sr-radio"><input type="radio" name={`from-${scope}`} checked={startMode === 'now'} onChange={() => setStartMode('now')} />{t('from_now_option')}</label>
                    <label className="sr-radio"><input type="radio" name={`from-${scope}`} checked={startMode === 'date'} onChange={() => setStartMode('date')} />{t('from_date_option')}</label>
                    {startMode === 'date' && <input type="datetime-local" value={start} onChange={(event) => setStart(event.target.value)} aria-label={t('from_label')} />}
                </fieldset>
                <fieldset>
                    <legend>{t('until_label')}</legend>
                    <label className="sr-radio"><input type="radio" name={`until-${scope}`} checked={endMode === 'open'} onChange={() => setEndMode('open')} />{t('until_open_option')}</label>
                    <label className="sr-radio"><input type="radio" name={`until-${scope}`} checked={endMode === 'date'} onChange={() => setEndMode('date')} />{t('until_date_option')}</label>
                    {endMode === 'date' && <input type="datetime-local" value={end} onChange={(event) => setEnd(event.target.value)} aria-label={t('until_label')} />}
                </fieldset>
            </div>
            {reasonField.shown && (
                <label className="cp-label sr-reason">
                    {reasonField.label(t('reason_label'))}
                    <input type="text" maxLength={1000} required={reasonField.required} value={reason} onChange={(event) => setReason(event.target.value)} />
                </label>
            )}

            <div className="sr-summary" role="note">
                <b>{t('summary_title')}</b>
                {lines.length === 0 ? <p>{t('summary_empty')}</p> : (
                    <>
                        <p>{sentence}</p>
                        <ul>{lines.map((line) => <li key={line}>{line}</li>)}</ul>
                        {replaces && <p>{t('replaces_note')}</p>}
                        <p>{workText}</p>
                    </>
                )}
            </div>

            {tried && problem !== '' && <p className="cp-error" role="alert">{t(problem)}</p>}
            {error !== '' && <p className="cp-error" role="alert">{error}</p>}
            {!workflow.can_propose && <p className="cp-hint" role="note">{t('cannot_propose')}</p>}
            <div className="cp-actions">
                <button type="button" className="button dark" disabled={saving || !workflow.can_propose} onClick={submit}>
                    {saving ? t('saving') : t(workflow.applies_at_once ? 'save_live' : 'save_draft')}
                </button>
            </div>
        </div>
    );
}
