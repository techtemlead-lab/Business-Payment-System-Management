import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Modal, money, number } from '../UI';
import { useT } from '../../shared/i18n/t';
import { localizeDigits } from '../../business-date';
import BuyerSwitch from './BuyerSwitch';
import type { BuyerType, Condition, ConditionNumber } from './shared';

type Translate = (key: string, replace?: Record<string, string | number>) => string;

/** Targets that must stay above zero: a zero target would make everyone qualify (CommissionPolicy::TargetParameters). */
const MUST_BE_ABOVE_ZERO = ['mo_target', 'bike_target', 'car_target'];
/** Counts the engine wants at least one of (CommissionPolicy::validateSchedule). */
const AT_LEAST_ONE = ['am_count', 'sm_count', 'me_count'];
/** Award amounts may stay blank until the owner decides. */
const MAY_BE_BLANK = ['bike_award_amount', 'car_award_amount'];

/** A number as people read it: money with the taka sign, counts and times in plain digits. */
function shown(entry: ConditionNumber, t: Translate): string {
    if (entry.value === null) {
        return t('not_set');
    }
    if (entry.unit === 'money') {
        // Whole takas read better without ".00".
        return /^d+.00$/.test(entry.value) ? `৳ ${number(entry.value)}` : money(entry.value);
    }

    return number(entry.value);
}

/** The sentence for one condition, with every number taken from the live rule. */
export function conditionText(condition: Condition, t: Translate): string {
    const replace: Record<string, string> = {};
    for (const entry of condition.numbers) {
        replace[entry.param] = shown(entry, t);
    }
    const held = condition.numbers.find((entry) => entry.param === 'held_share_months');
    if (held) {
        replace.held_text = Number(held.value ?? 0) === 0 ? t('held_never') : t('held_after', { months: localizeDigits(String(Number(held.value))) });
    }
    const cooling = condition.numbers.find((entry) => entry.param === 'cooling_off_days');
    if (cooling) {
        replace.cool_text = Number(cooling.value ?? 0) === 0 ? t('cool_none') : t('cool_days', { days: localizeDigits(String(Number(cooling.value))) });
    }

    if (condition.facts?.destination) {
        replace.dest_text = t(`dest_${condition.facts.destination}`);
    }

    return t(`cond_${condition.id}_text`, replace);
}

/** What each condition needs, in plain words, for dealers or sub-dealers. */
export default function ConditionsSection({ conditions, canEdit, mode, onMode, onChange }: {
    conditions: Condition[];
    canEdit: boolean;
    mode: BuyerType;
    onMode: (type: BuyerType) => void;
    onChange: () => void;
}) {
    const translate = useT();
    const t: Translate = (key, replace = {}) => translate('commission_page', key, replace as Record<string, string>);
    const list = conditions.filter((condition) => condition.applies_to.includes(mode));

    return (
        <section className="cp-card" aria-labelledby="cp-cond-title">
            <div className="cp-card-head">
                <div>
                    <h2 id="cp-cond-title">{t('cond_section')}</h2>
                    <p className="cp-hint">{t('cond_lead')}</p>
                </div>
                <BuyerSwitch value={mode} onChange={onMode} label={t('switch_label')} />
            </div>
            {list.length === 0 ? <p className="cp-empty">{t('cond_empty')}</p> : (
                <ul className="cp-conds">
                    {list.map((condition) => (
                        <li key={condition.id}>
                            <h3>{t(`cond_${condition.id}_title`)}</h3>
                            <p>{conditionText(condition, t)}</p>
                        </li>
                    ))}
                </ul>
            )}
            {canEdit && (
                <div className="cp-actions">
                    <button type="button" className="button dark" onClick={onChange}>{t('btn_change_conditions')}</button>
                </div>
            )}
        </section>
    );
}

/** The problem with one typed number, as a lang key, or '' when it is fine. */
function numberProblem(entry: ConditionNumber, raw: string): string {
    const value = raw.trim();
    if (value === '') {
        return MAY_BE_BLANK.includes(entry.param) ? '' : 'err_number';
    }
    if (entry.unit === 'money') {
        if (!/^\d{1,11}(\.\d{1,2})?$/.test(value)) {
            return 'err_number';
        }

        return MUST_BE_ABOVE_ZERO.includes(entry.param) && Number(value) === 0 ? 'err_above_zero' : '';
    }
    if (!/^\d{1,3}$/.test(value)) {
        return 'err_whole';
    }
    const amount = Number(value);
    const limit = entry.unit === 'months' ? 120 : 366;
    if (amount > limit) {
        return 'err_whole';
    }

    return AT_LEAST_ONE.includes(entry.param) && amount < 1 ? 'err_at_least_one' : '';
}

/** Change the numbers behind the conditions; each number says what it looks at. */
export function ConditionsModal({ conditions, startWith, appliesAtOnce, onClose }: { conditions: Condition[]; startWith: BuyerType; appliesAtOnce: boolean; onClose: () => void }) {
    const translate = useT();
    const t: Translate = (key, replace = {}) => translate('commission_page', key, replace as Record<string, string>);
    const [mode, setMode] = useState<BuyerType>(startWith);
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(conditions.flatMap((condition) => condition.numbers.map((entry) => [`${condition.rule}.${entry.param}`, entry.value ?? '']))));
    const [saving, setSaving] = useState(false);
    const [serverError, setServerError] = useState('');
    const [tried, setTried] = useState(false);
    const list = conditions.filter((condition) => condition.applies_to.includes(mode) && condition.numbers.length > 0);

    const problems = useMemo(() => {
        const found: Record<string, string> = {};
        for (const condition of conditions) {
            for (const entry of condition.numbers) {
                const key = `${condition.rule}.${entry.param}`;
                const problem = numberProblem(entry, values[key] ?? '');
                if (problem !== '') {
                    found[key] = problem;
                }
            }
        }

        return found;
    }, [conditions, values]);

    const changed = useMemo(() => {
        const rules: Record<string, { version: number; parameters: Record<string, string> }> = {};
        for (const condition of conditions) {
            for (const entry of condition.numbers) {
                const key = `${condition.rule}.${entry.param}`;
                const next = (values[key] ?? '').trim();
                if (next !== (entry.value ?? '') && problems[key] === undefined) {
                    rules[condition.rule] ??= { version: condition.version, parameters: {} };
                    rules[condition.rule].parameters[entry.param] = next;
                }
            }
        }

        return rules;
    }, [conditions, values, problems]);

    const save = () => {
        setTried(true);
        setServerError('');
        if (Object.keys(problems).length > 0 || Object.keys(changed).length === 0) {
            return;
        }
        setSaving(true);
        router.post('/commands/commissions/rules', { rules: changed as never }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setServerError(Object.values(errors).join(' ')),
            onFinish: () => setSaving(false),
        });
    };

    const suffix = (unit: ConditionNumber['unit']) => (unit === 'money' ? '৳' : unit === 'days' ? t('unit_days') : unit === 'months' ? t('unit_months') : unit === 'count' ? t('unit_count') : '%');
    const footer = (
        <div className="cp-modal-foot">
            <button type="button" className="button" onClick={onClose}>{t('cancel')}</button>
            <button type="button" className="button dark" disabled={saving} onClick={save}>{saving ? t('saving') : t('save_conditions')}</button>
        </div>
    );

    return (
        <Modal title={t('cond_change_title')} onClose={onClose} variant="wide" footer={footer}>
            <div className="cp-modal">
                <div className="cp-modal-top">
                    <BuyerSwitch value={mode} onChange={setMode} label={t('switch_label')} />
                    <p className="cp-state" role="note">
                        <span className={`cp-pill ${appliesAtOnce ? 'is-live' : 'is-draft'}`}>{t(appliesAtOnce ? 'status_active' : 'status_draft')}</span>
                        {t(appliesAtOnce ? 'publish_owner' : 'publish_staff')}
                    </p>
                </div>
                <dl className="cp-legend">
                    {(['own', 'team', 'each'] as const).map((kind) => (
                        <div key={kind}><dt><span className={`cp-tag is-${kind}`}>{t(`controls_${kind}`)}</span></dt><dd>{t(`controls_hint_${kind}`)}</dd></div>
                    ))}
                </dl>
                {list.length === 0 && <p className="cp-empty">{t('cond_no_numbers')}</p>}
                {list.map((condition) => (
                    <fieldset className="cp-cond-group" key={condition.id}>
                        <legend>{t(`cond_${condition.id}_title`)}</legend>
                        {condition.numbers.map((entry) => {
                            const key = `${condition.rule}.${entry.param}`;
                            const id = `cp-num-${condition.id}-${entry.param}`;
                            const problem = problems[key];

                            return (
                                <div className={`cp-num${problem && tried ? ' has-error' : ''}`} key={entry.param}>
                                    <label htmlFor={id}>{t(`num_${entry.param}`)}</label>
                                    <span className={`cp-tag is-${entry.controls}`}>{t(`controls_${entry.controls}`)}</span>
                                    <span className="cp-field">
                                        <input
                                            id={id}
                                            type="text"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            value={values[key] ?? ''}
                                            placeholder={MAY_BE_BLANK.includes(entry.param) ? t('not_set') : undefined}
                                            aria-invalid={Boolean(problem) && tried}
                                            aria-describedby={problem && tried ? `${id}-err` : undefined}
                                            onChange={(event) => setValues((current) => ({ ...current, [key]: event.target.value }))}
                                        />
                                        <span aria-hidden="true">{suffix(entry.unit)}</span>
                                    </span>
                                    {problem && tried && <p className="cp-error" id={`${id}-err`} role="alert">{t(problem)}</p>}
                                </div>
                            );
                        })}
                    </fieldset>
                ))}
                {tried && Object.keys(changed).length === 0 && Object.keys(problems).length === 0 && <p className="cp-error" role="alert">{t('err_no_change')}</p>}
                {serverError && <p className="cp-error" role="alert">{serverError}</p>}
            </div>
        </Modal>
    );
}
