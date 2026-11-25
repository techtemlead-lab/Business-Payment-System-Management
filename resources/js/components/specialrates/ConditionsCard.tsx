import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, RotateCcw } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { money, number } from '../UI';
import { conditionText } from '../commissionpage/ConditionsSection';
import type { Controls, CustomizableField, Person, SpecialData } from './types';

const RANKS = ['mo', 'am', 'sm', 'me'] as const;
const BANGLA_DIGITS = '০১২৩৪৫৬৭৮৯';
const ALLOW_ZERO_MONEY = ['bike_leg_cap', 'car_leg_cap'];

function plainInput(raw: string): string {
    return raw.replace(/[০-৯]/g, (digit) => String(BANGLA_DIGITS.indexOf(digit))).replace(/[,\s]/g, '').trim();
}

function formatGeneral(field: CustomizableField): string {
    if (field.unit === 'money') {
        return /^\d+\.00$/.test(field.general) ? `৳\u00A0${number(field.general)}` : money(field.general);
    }
    if (field.unit === 'percent') {
        return `${number(field.general)}%`;
    }

    return number(field.general);
}

function customFieldProblem(field: CustomizableField, raw: string): string {
    const value = plainInput(raw);
    if (value === '') {
        return '';
    }
    if (field.unit === 'money') {
        if (!/^\d{1,11}(\.\d{1,2})?$/.test(value)) {
            return 'err_number';
        }

        return !ALLOW_ZERO_MONEY.includes(field.key) && Number(value) === 0 ? 'err_above_zero' : '';
    }
    if (field.unit === 'percent') {
        return /^\d{1,3}(\.\d{1,2})?$/.test(value) && Number(value) <= 100 ? '' : 'err_number';
    }
    if (!/^\d{1,3}$/.test(value)) {
        return 'err_whole';
    }
    const amount = Number(value);
    if (amount > 366) {
        return 'err_whole';
    }

    return field.key !== 'cooling_off_days' && amount < 1 ? 'err_at_least_one' : '';
}

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
 * The conditions of this person: the owner can lift conditions completely (condition-free)
 * or customize specific condition thresholds higher or lower for this person alone.
 */
export default function ConditionsCard({ special, person }: { special: SpecialData; person: Person }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const tc = (key: string, replace: Record<string, string | number> = {}) => translate('commission_page', key, replace);
    const note = useReasonField('partner.benefit_note');
    const reasonField = useReasonField('partner.benefit_reason');
    const saved: Controls = special.controls ?? {
        unconditional_salary: false,
        unconditional_rank_royalty: false,
        forced_royalty_rank: 'mo',
        unconditional_rank_incentive: false,
        custom_conditions: {},
        note: null,
    };
    const savedCustom = useMemo<Record<string, string>>(
        () => Object.fromEntries(Object.entries(saved.custom_conditions ?? {}).map(([key, value]) => [key, String(value)])),
        [saved.custom_conditions],
    );
    const [flags, setFlags] = useState<Record<string, boolean>>({
        unconditional_salary: saved.unconditional_salary,
        unconditional_rank_royalty: saved.unconditional_rank_royalty,
        unconditional_rank_incentive: saved.unconditional_rank_incentive,
    });
    const [rank, setRank] = useState<string>(saved.forced_royalty_rank ?? 'mo');
    const [customValues, setCustomValues] = useState<Record<string, string>>(savedCustom);
    const [noteText, setNoteText] = useState(saved.note ?? '');
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const [tried, setTried] = useState(false);

    const rows = special.conditions.filter((condition) => condition.applies_to.includes(person.role as 'dealer' | 'sub_dealer'));
    const applicable = Array.from(new Set(rows.map((condition) => special.waivable[condition.id]).filter(Boolean)));
    const all = applicable.length > 0 && applicable.every((flag) => flags[flag]);
    const customizable = special.customizable ?? {};
    const allCustomFields = useMemo(() => Object.values(customizable).flat(), [customizable]);
    const canChange = special.workflow.can_set_conditions && special.controls !== null && special.controls !== undefined && (applicable.length > 0 || allCustomFields.length > 0);

    const customProblems = useMemo(() => {
        const found: Record<string, string> = {};
        for (const field of allCustomFields) {
            const problem = customFieldProblem(field, customValues[field.key] ?? '');
            if (problem !== '') {
                found[field.key] = problem;
            }
        }

        return found;
    }, [allCustomFields, customValues]);

    const tierProblem = useMemo(() => {
        if (person.role !== 'sub_dealer') {
            return '';
        }
        const subFields = customizable.sub_salary ?? [];
        if (subFields.length === 0) {
            return '';
        }
        const hasAnyCustomTier = subFields.some((field) => plainInput(customValues[field.key] ?? '') !== '');
        if (!hasAnyCustomTier || subFields.some((field) => customProblems[field.key] !== undefined)) {
            return '';
        }
        const val = (key: string) => {
            const raw = plainInput(customValues[key] ?? '');
            const def = subFields.find((field) => field.key === key)?.general ?? '0';

            return Number(raw !== '' ? raw : def);
        };
        const maxPct = Number(special.sub_dealer_salary_max_pct ?? '100');
        if (val('tier2_volume') <= val('tier1_volume') || val('tier3_volume') <= val('tier2_volume') || val('tier2_pct') < val('tier1_pct') || val('tier3_pct') < val('tier2_pct') || val('tier3_pct') > maxPct) {
            return 'err_salary_tiers';
        }

        return '';
    }, [person.role, customizable, customValues, customProblems, special.sub_dealer_salary_max_pct]);

    const customDirty = useMemo(() => {
        const keys = new Set([...Object.keys(savedCustom), ...allCustomFields.map((field) => field.key)]);
        for (const key of keys) {
            const next = plainInput(customValues[key] ?? '');
            const prev = plainInput(savedCustom[key] ?? '');
            if (next === prev) {
                continue;
            }
            if (next === '' || prev === '' || Number(next) !== Number(prev)) {
                return true;
            }
        }

        return false;
    }, [allCustomFields, customValues, savedCustom]);

    const dirty = applicable.some((flag) => flags[flag] !== (saved as unknown as Record<string, boolean>)[flag])
        || (flags.unconditional_rank_royalty && rank !== (saved.forced_royalty_rank ?? 'mo'))
        || customDirty
        || noteText.trim() !== (saved.note ?? '');
    const reasonMissing = reasonField.required && reason.trim().length < 5;

    const setAll = (value: boolean) => setFlags((current) => ({ ...current, ...Object.fromEntries(applicable.map((flag) => [flag, value])) }));
    const suffix = (unit: CustomizableField['unit']) => (unit === 'money' ? '৳' : unit === 'days' ? tc('unit_days') : unit === 'count' ? tc('unit_count') : '%');

    const save = () => {
        setTried(true);
        setError('');
        if (!dirty || reasonMissing || Object.keys(customProblems).length > 0 || tierProblem !== '') {
            return;
        }
        const customPayload: Record<string, string> = {};
        for (const field of allCustomFields) {
            const next = plainInput(customValues[field.key] ?? '');
            if (next !== '') {
                customPayload[field.key] = next;
            }
        }
        setSaving(true);
        router.post(`/commands/accounts/${person.id}/benefit-controls`, {
            ...flags,
            forced_royalty_rank: rank,
            custom_conditions: customPayload,
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
                    {applicable.length === 0 && allCustomFields.length === 0 && <p className="cp-hint" role="note">{t('cond_none_for_role')}</p>}
                    <ul className="cp-conds sr-conds">
                        {rows.map((condition) => {
                            const flag = special.waivable[condition.id];
                            const seats = condition.id === 'area' ? special.seats ?? [] : [];
                            const fields = customizable[condition.id] ?? [];
                            const isWaived = Boolean(flag && flags[flag]);

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
                                        {fields.length > 0 && !isWaived && (
                                            <div className="sr-custom-fields">
                                                <b className="sr-custom-heading">{t('custom_section_title')}</b>
                                                {fields.map((field) => {
                                                    const currentVal = customValues[field.key] ?? '';
                                                    const cleaned = plainInput(currentVal);
                                                    const problem = customProblems[field.key];
                                                    const hasCustom = cleaned !== '';
                                                    const diff = hasCustom && !problem
                                                        ? (Number(cleaned) > Number(field.general) ? 'higher' : Number(cleaned) < Number(field.general) ? 'lower' : 'same')
                                                        : null;
                                                    const inputId = `sr-custom-${condition.id}-${field.key}`;

                                                    return (
                                                        <div key={field.key} className={`sr-custom-row${problem && tried ? ' has-error' : ''}`}>
                                                            <div className="sr-custom-meta">
                                                                <label htmlFor={inputId}>{t(`custom_fld_${field.key}`)}</label>
                                                                <small>{t('col_general')}: {formatGeneral(field)}</small>
                                                            </div>
                                                            <div className="sr-custom-input">
                                                                <span className="cp-field">
                                                                    <input
                                                                        id={inputId}
                                                                        type="text"
                                                                        inputMode="decimal"
                                                                        autoComplete="off"
                                                                        disabled={!canChange}
                                                                        placeholder={field.general}
                                                                        value={currentVal}
                                                                        aria-invalid={Boolean(problem) && tried}
                                                                        onChange={(event) => setCustomValues((current) => ({ ...current, [field.key]: event.target.value }))}
                                                                    />
                                                                    <span aria-hidden="true">{suffix(field.unit)}</span>
                                                                </span>
                                                                {diff && diff !== 'same' && (
                                                                    <span className={`cp-pill sr-custom-diff is-${diff}`}>{t(`state_${diff}`)}</span>
                                                                )}
                                                                {hasCustom && canChange && (
                                                                    <button
                                                                        type="button"
                                                                        className="sr-reset"
                                                                        onClick={() => setCustomValues((current) => ({ ...current, [field.key]: '' }))}
                                                                    >
                                                                        <RotateCcw size={14} aria-hidden="true" />
                                                                        {t('reset')}
                                                                    </button>
                                                                )}
                                                            </div>
                                                            {problem && tried && <p className="cp-error" role="alert">{tc(problem)}</p>}
                                                        </div>
                                                    );
                                                })}
                                                {condition.id === 'sub_salary' && tierProblem !== '' && tried && (
                                                    <p className="cp-error" role="alert">{tc(tierProblem)}</p>
                                                )}
                                            </div>
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
                                                <span className="cp-tag">{fields.length > 0 ? t('cond_custom_or_same') : t('cond_general_only')}</span>
                                                <small>{t(fields.length > 0 ? 'custom_hint' : (condition.id === 'area' ? 'area_hint' : 'general_only_hint'))}</small>
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
