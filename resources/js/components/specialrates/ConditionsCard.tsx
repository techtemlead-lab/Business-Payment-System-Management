import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { money } from '../UI';
import { conditionText } from '../commissionpage/ConditionsSection';
import type { Controls, Person, SpecialData } from './types';

const RANKS = ['mo', 'am', 'sm', 'me'] as const;

/** An on / off switch with a visible word, big enough to touch. */
function Switch({ on, onChange, label, disabled }: { on: boolean; onChange: (value: boolean) => void; label: string; disabled?: boolean }) {
    return (
        <button type="button" role="switch" aria-checked={on} disabled={disabled} className={`sr-switch${on ? ' is-on' : ''}`} onClick={() => onChange(!on)}>
            <span className="sr-switch-knob" aria-hidden="true" />
            <span>{label}</span>
        </button>
    );
}

/**
 * The conditions of this person. The engine can lift three for one person today (the monthly purchase minimum of the
 * dealer salary bonus, the rank royalty conditions, the rank prize conditions); the owner sets them and they work at
 * once. Every other condition is the same for everyone, and the page says so instead of pretending.
 */
export default function ConditionsCard({ special, person }: { special: SpecialData; person: Person }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const tc = (key: string, replace: Record<string, string | number> = {}) => translate('commission_page', key, replace);
    const note = useReasonField('partner.benefit_note');
    const reasonField = useReasonField('partner.benefit_reason');
    const saved: Controls = special.controls ?? { unconditional_salary: false, unconditional_rank_royalty: false, forced_royalty_rank: 'mo', unconditional_rank_incentive: false, note: null };
    const [flags, setFlags] = useState<Record<string, boolean>>({
        unconditional_salary: saved.unconditional_salary,
        unconditional_rank_royalty: saved.unconditional_rank_royalty,
        unconditional_rank_incentive: saved.unconditional_rank_incentive,
    });
    const [rank, setRank] = useState<string>(saved.forced_royalty_rank ?? 'mo');
    const [noteText, setNoteText] = useState(saved.note ?? '');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [tried, setTried] = useState(false);

    const rows = special.conditions.filter((condition) => condition.applies_to.includes(person.role as 'dealer' | 'sub_dealer'));
    const applicable = Array.from(new Set(rows.map((condition) => special.waivable[condition.id]).filter(Boolean)));
    const all = applicable.length > 0 && applicable.every((flag) => flags[flag]);
    const canChange = special.workflow.can_set_conditions && special.controls !== null && special.controls !== undefined && applicable.length > 0;
    const dirty = applicable.some((flag) => flags[flag] !== (saved as unknown as Record<string, boolean>)[flag])
        || (flags.unconditional_rank_royalty && rank !== (saved.forced_royalty_rank ?? 'mo')) || noteText.trim() !== (saved.note ?? '');
    const reasonMissing = reasonField.required && reason.trim().length < 5;

    const setAll = (value: boolean) => setFlags((current) => ({ ...current, ...Object.fromEntries(applicable.map((flag) => [flag, value])) }));
    const save = () => {
        setTried(true);
        setError('');
        if (!dirty || reasonMissing) {
            return;
        }
        setSaving(true);
        router.post(`/commands/accounts/${person.id}/benefit-controls`, {
            ...flags,
            forced_royalty_rank: rank,
            ...(note.shown ? { note: noteText } : {}),
            ...(reasonField.shown ? { reason } : {}),
        }, {
            preserveScroll: true,
            onSuccess: () => { setReason(''); setTried(false); },
            onError: (errors) => setError(Object.values(errors).join(' ')),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <section className="cp-card" aria-labelledby="sr-cond-title">
            <div className="cp-card-head">
                <div>
                    <h2 id="sr-cond-title">{t('cond_title')}</h2>
                    <p className="cp-hint">{t('cond_lead', { name: person.name })}</p>
                </div>
            </div>

            <div className="sr-master">
                <div>
                    <b>{t('master_title')}</b>
                    <small>{t('master_hint')}</small>
                </div>
                <Switch on={all} disabled={!canChange} onChange={setAll} label={t(all ? 'switch_on' : 'switch_off')} />
            </div>
            {special.controls === null || special.controls === undefined ? <p className="cp-empty">{t('cond_not_partner')}</p> : (
                <>
                    {!special.workflow.can_set_conditions && <p className="cp-hint" role="note">{t('cond_owner_only')}</p>}
                    {applicable.length === 0 && <p className="cp-hint" role="note">{t('cond_none_for_role')}</p>}
                    <ul className="cp-conds sr-conds">
                        {rows.map((condition) => {
                            const flag = special.waivable[condition.id];
                            const seats = condition.id === 'area' ? special.seats ?? [] : [];

                            return (
                                <li key={condition.id}>
                                    <div className="sr-cond-text">
                                        <h3>{tc(`cond_${condition.id}_title`)}</h3>
                                        <p>{conditionText(condition, tc)}</p>
                                        {seats.length > 0 && (
                                            <ul className="sr-seats">
                                                {seats.map((seat) => <li key={`${seat.level}-${seat.place}`}>{t('seat_line', { level: tc(`part_area_dealer_pool_${seat.level}`), place: seat.place, quota: money(seat.monthly_quota) })}</li>)}
                                            </ul>
                                        )}
                                    </div>
                                    <div className="sr-cond-control">
                                        {flag ? (
                                            <>
                                                <span className="sr-label">{t('person_value')}</span>
                                                <Switch on={flags[flag]} disabled={!canChange} onChange={(value) => setFlags((current) => ({ ...current, [flag]: value }))} label={t(flags[flag] ? 'cond_free' : 'cond_same')} />
                                                {condition.id === 'rank_join' && flags[flag] && (
                                                    <label className="sr-rank">
                                                        {t('forced_rank')}
                                                        <select value={rank} disabled={!canChange} onChange={(event) => setRank(event.target.value)}>
                                                            {RANKS.map((code) => <option key={code} value={code}>{tc(`part_rank_royalty_${code}`)}</option>)}
                                                        </select>
                                                    </label>
                                                )}
                                            </>
                                        ) : (
                                            <>
                                                <span className="sr-label">{t('person_value')}</span>
                                                <span className="cp-tag">{t('cond_general_only')}</span>
                                                <small>{t(condition.id === 'area' ? 'area_hint' : 'general_only_hint')}</small>
                                            </>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    {canChange && (
                        <div className="sr-save">
                            {note.shown && (
                                <label className="cp-label">
                                    {note.label(t('note_label'))}
                                    <input type="text" maxLength={500} required={note.required} value={noteText} onChange={(event) => setNoteText(event.target.value)} />
                                </label>
                            )}
                            {reasonField.shown && (
                                <label className="cp-label">
                                    {reasonField.label(t('reason_label'))}
                                    <input type="text" maxLength={1000} required={reasonField.required} value={reason} onChange={(event) => setReason(event.target.value)} />
                                </label>
                            )}
                            <p className="cp-hint">{t('cond_now_note')}</p>
                            {tried && !dirty && <p className="cp-error" role="alert">{t('err_no_change')}</p>}
                            {tried && reasonMissing && <p className="cp-error" role="alert">{t('err_reason')}</p>}
                            {error !== '' && <p className="cp-error" role="alert">{error}</p>}
                            <div className="cp-actions">
                                <button type="button" className="button dark" disabled={saving} onClick={save}><Check size={16} aria-hidden="true" />{saving ? t('saving') : t('cond_save')}</button>
                            </div>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
