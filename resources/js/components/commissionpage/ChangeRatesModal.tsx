import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { Modal } from '../UI';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { localizeDigits } from '../../business-date';
import BuyerSwitch from './BuyerSwitch';
import {
    DESTINATIONS, SUB_DEALER_ONLY, fromBasisPoints, shareForBikePart, toBasisPoints, showPercent,
    type BuyerType, type CommissionPageData, type Head,
} from './shared';

/** What the person has typed for one commission: its percent and, when it is split, each part. */
interface RowDraft {
    pct: string;
    parts: Record<string, string>;
    /** Where the sub-dealer extra part goes; empty for every other commission. */
    destination: string;
}

const MONEY_TEXT = /^\d{1,11}(\.\d{1,2})?$/;

type Drafts = Record<string, RowDraft>;

const startDrafts = (heads: Head[]): Drafts => Object.fromEntries(heads.map((head) => [
    head.key,
    { pct: head.percentage, parts: Object.fromEntries(head.children.map((part) => [part.key, part.pct])), destination: head.destination ?? '' },
]));

/** Everything wrong with one row, as plain-language keys the modal turns into words. */
function rowProblems(head: Head, draft: RowDraft): { key: string; replace?: Record<string, string> }[] {
    const total = toBasisPoints(draft.pct);
    if (total === null) {
        return [{ key: 'err_percent' }];
    }
    if (head.children.some((part) => part.unit === 'money' && !MONEY_TEXT.test((draft.parts[part.key] ?? '').trim()))) {
        return [{ key: 'err_number' }];
    }
    const percentParts = head.children.filter((part) => part.unit === 'percent');
    if (percentParts.length === 0) {
        return [];
    }
    const parts = percentParts.map((part) => toBasisPoints(part.param === null ? '0' : draft.parts[part.key] ?? ''));
    if (parts.some((value) => value === null)) {
        return [{ key: 'err_percent' }];
    }
    if (head.key === 'car_bike_incentive') {
        const bike = parts[0] as number;
        if (bike > total) {
            return [{ key: 'err_bike_more' }];
        }

        return shareForBikePart(total, bike) === null ? [{ key: 'err_bike_split' }] : [];
    }
    const sum = (parts as number[]).reduce((all, value) => all + value, 0);

    return sum === total ? [] : [{ key: 'err_parts_sum', replace: { sum: localizeDigits(String(parseFloat(fromBasisPoints(sum)))), total: localizeDigits(String(parseFloat(fromBasisPoints(total)))) } }];
}

/**
 * Change the percents: one row per commission with a box for the new percent. A commission that is split
 * opens inside the row so its parts are changed there. Saving goes through the engine's own rule update, so
 * the owner's approval, versions and the audit log all stay as they were.
 */
export default function ChangeRatesModal({ page, startWith, onClose }: { page: CommissionPageData; startWith: BuyerType; onClose: () => void }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('commission_page', key, replace);
    const reasonField = useReasonField('money.commission_rules');
    const [mode, setMode] = useState<BuyerType>(startWith);
    const [drafts, setDrafts] = useState<Drafts>(() => startDrafts(page.heads));
    const [open, setOpen] = useState<string[]>([]);
    const [reason, setReason] = useState('');
    const [saving, setSaving] = useState(false);
    const [serverError, setServerError] = useState('');
    const [tried, setTried] = useState(false);

    const shown = page.heads.filter((head) => mode === 'sub_dealer' || !SUB_DEALER_ONLY.includes(head.key));
    const problems = useMemo(() => Object.fromEntries(page.heads.map((head) => [head.key, rowProblems(head, drafts[head.key])])), [page.heads, drafts]);
    const totalOf = (keys: (head: Head) => boolean) => page.heads.filter(keys).reduce((sum, head) => {
        const points = head.is_active ? toBasisPoints(drafts[head.key].pct) : 0;

        return sum + (points ?? 0);
    }, 0);
    const dealerTotal = totalOf((head) => !SUB_DEALER_ONLY.includes(head.key));
    const subTotal = totalOf(() => true);
    const dealerCap = Math.round(parseFloat(page.totals.dealer_cap_pct) * 100);
    const subCap = Math.round(parseFloat(page.totals.sub_dealer_cap_pct) * 100);
    const overCap = dealerTotal > dealerCap || subTotal > subCap;
    const hasProblem = Object.values(problems).some((list) => list.length > 0);

    const changed = useMemo(() => {
        const rules: Record<string, Record<string, unknown>> = {};
        for (const head of page.heads) {
            const draft = drafts[head.key];
            const total = toBasisPoints(draft.pct);
            if (total === null) {
                continue;
            }
            const entry: Record<string, unknown> = {};
            if (total !== toBasisPoints(head.percentage)) {
                entry.percentage = fromBasisPoints(total);
            }
            const parameters: Record<string, string> = {};
            for (const part of head.children) {
                if (part.param === null) {
                    continue;
                }
                if (part.unit === 'money') {
                    const typed = (draft.parts[part.key] ?? '').trim();
                    if (Number(typed) !== Number(part.pct)) {
                        parameters[part.param] = typed;
                    }
                    continue;
                }
                if (head.key === 'car_bike_incentive') {
                    // The saved value is the bike's share of the fund, not the bike's percent of the order.
                    const bike = toBasisPoints(draft.parts.bike) ?? 0;
                    const share = shareForBikePart(total, bike);
                    if (share !== null && (bike !== toBasisPoints(part.pct) || total !== toBasisPoints(head.percentage))) {
                        parameters[part.param] = fromBasisPoints(share);
                    }
                    continue;
                }
                const next = toBasisPoints(draft.parts[part.key]) ?? 0;
                if (next !== toBasisPoints(part.pct)) {
                    parameters[part.param] = fromBasisPoints(next);
                }
            }
            if (head.key === 'additional_rank' && draft.destination !== (head.destination ?? '')) {
                parameters.destination = draft.destination;
            }
            if (Object.keys(parameters).length > 0) {
                entry.parameters = parameters;
            }
            if (Object.keys(entry).length > 0) {
                rules[head.key] = { version: head.version, ...entry };
            }
        }

        return rules;
    }, [page.heads, drafts]);
    const nothingChanged = Object.keys(changed).length === 0;
    const reasonMissing = reasonField.required && reason.trim() === '';

    const setPct = (key: string, value: string) => setDrafts((current) => ({ ...current, [key]: { ...current[key], pct: value } }));
    const setPart = (key: string, part: string, value: string) => setDrafts((current) => ({ ...current, [key]: { ...current[key], parts: { ...current[key].parts, [part]: value } } }));
    const setDestination = (key: string, value: string) => setDrafts((current) => ({ ...current, [key]: { ...current[key], destination: value } }));
    const toggle = (key: string) => setOpen((current) => (current.includes(key) ? current.filter((item) => item !== key) : [...current, key]));

    const save = () => {
        setTried(true);
        setServerError('');
        if (hasProblem || overCap || nothingChanged || reasonMissing) {
            return;
        }
        setSaving(true);
        router.post('/commands/commissions/rules', { rules: changed as never, ...(reasonField.shown ? { reason } : {}) }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => setServerError(Object.values(errors).join(' ')),
            onFinish: () => setSaving(false),
        });
    };

    const errorText = (list: { key: string; replace?: Record<string, string> }[]) => list.map((item) => t(item.key, item.replace)).join(' ');
    const footer = (
        <div className="cp-modal-foot">
            <button type="button" className="button" onClick={onClose}>{t('cancel')}</button>
            <button type="button" className="button dark" disabled={saving} onClick={save}>
                <Check size={16} aria-hidden="true" />
                {saving ? t('saving') : t(page.workflow.applies_at_once ? 'save_live' : 'save_draft')}
            </button>
        </div>
    );

    return (
        <Modal title={t('change_title')} onClose={onClose} variant="wide" footer={footer}>
            <div className="cp-modal">
                <div className="cp-modal-top">
                    <BuyerSwitch value={mode} onChange={setMode} label={t('switch_label')} />
                    <p className="cp-state" role="note">
                        <span className={`cp-pill ${page.workflow.applies_at_once ? 'is-live' : 'is-draft'}`}>{t(page.workflow.applies_at_once ? 'status_active' : 'status_draft')}</span>
                        {t(page.workflow.applies_at_once ? 'publish_owner' : 'publish_staff')}
                    </p>
                </div>
                {mode === 'sub_dealer' && <p className="cp-hint">{t('shared_rates_note')}</p>}

                <ul className="cp-edit-list">
                    {shown.map((head, index) => {
                        const draft = drafts[head.key];
                        const startsSubBlock = head.block === 'sub_only' && shown[index - 1]?.block !== 'sub_only';
                        const list = problems[head.key];
                        const isOpen = open.includes(head.key);
                        const inputId = `cp-pct-${head.key}`;
                        const errorId = `cp-err-${head.key}`;
                        const showError = list.length > 0 && (tried || toBasisPoints(draft.pct) !== toBasisPoints(head.percentage) || head.children.some((part) => (draft.parts[part.key] ?? '') !== part.pct));

                        return (
                            <React.Fragment key={head.key}>
                            {startsSubBlock && (
                                <li className="cp-edit-block">
                                    <b>{t('subblock_title')}</b>
                                    <small>{t('subblock_lead')}</small>
                                </li>
                            )}
                            <li className={`cp-edit-item${showError ? ' has-error' : ''}`}>
                                <div className="cp-edit-row">
                                    <div className="cp-edit-name">
                                        {head.children.length > 0 ? (
                                            <button type="button" className="cp-expand" aria-expanded={isOpen} aria-controls={`cp-edit-parts-${head.key}`} onClick={() => toggle(head.key)}>
                                                <ChevronDown size={18} aria-hidden="true" />
                                                <span>{t(`head_${head.key}`)}</span>
                                            </button>
                                        ) : (
                                            <label htmlFor={inputId}>{t(`head_${head.key}`)}</label>
                                        )}
                                        <small>{t(SUB_DEALER_ONLY.includes(head.key) ? 'only_sub_dealer' : 'both_buyers')}</small>
                                        {head.sub_name && <small>{t('sub_name_line', { name: t(`sub_name_${head.key}`) })}</small>}
                                    </div>
                                    <div className="cp-field">
                                        <input
                                            id={inputId}
                                            type="text"
                                            inputMode="decimal"
                                            autoComplete="off"
                                            value={draft.pct}
                                            aria-label={`${t(`head_${head.key}`)} (%)`}
                                            aria-invalid={showError}
                                            aria-describedby={showError ? errorId : undefined}
                                            onChange={(event) => setPct(head.key, event.target.value)}
                                        />
                                        <span aria-hidden="true">%</span>
                                    </div>
                                </div>
                                {head.children.length > 0 && isOpen && (
                                    <div className="cp-edit-parts" id={`cp-edit-parts-${head.key}`}>
                                        <p className="cp-hint">{t(`parts_title_${head.key}`)}</p>
                                        {head.children.map((part) => {
                                            const partId = `cp-part-${head.key}-${part.key}`;
                                            const total = toBasisPoints(draft.pct) ?? 0;
                                            const bike = toBasisPoints(draft.parts.bike ?? '') ?? 0;
                                            const derived = head.key === 'car_bike_incentive' && part.key === 'car';

                                            return (
                                                <div className="cp-edit-row is-part" key={part.key}>
                                                    <label htmlFor={partId}>{t(`part_${head.key}_${part.key}`)}</label>
                                                    <div className="cp-field">
                                                        <input
                                                            id={partId}
                                                            type="text"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            readOnly={derived}
                                                            value={derived ? fromBasisPoints(Math.max(0, total - bike)) : draft.parts[part.key]}
                                                            onChange={(event) => setPart(head.key, part.key, event.target.value)}
                                                        />
                                                        <span aria-hidden="true">{part.unit === 'money' ? '৳' : '%'}</span>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                        {head.key !== 'car_bike_incentive' && head.children_total !== null && list.length === 0 && (
                                            <p className="cp-parts-sum is-ok">
                                                {t('parts_sum_now', {
                                                    sum: localizeDigits(String(parseFloat(fromBasisPoints(head.children.reduce((all, part) => all + (toBasisPoints(draft.parts[part.key] ?? '') ?? 0), 0))))),
                                                    total: localizeDigits(String(parseFloat(fromBasisPoints(toBasisPoints(draft.pct) ?? 0)))),
                                                })}
                                            </p>
                                        )}
                                    </div>
                                )}
                                {head.destination !== null && (
                                    <div className="cp-edit-dest">
                                        <label htmlFor="cp-dest-extra">{t('dest_pick')}</label>
                                        <select id="cp-dest-extra" value={draft.destination} onChange={(event) => setDestination(head.key, event.target.value)}>
                                            {DESTINATIONS.map((option) => <option key={option} value={option}>{t(`dest_${option}`)}</option>)}
                                        </select>
                                        <small>{t('dest_note')}</small>
                                    </div>
                                )}
                                {showError && <p className="cp-error" id={errorId} role="alert">{errorText(list)}</p>}
                            </li>
                            </React.Fragment>
                        );
                    })}
                </ul>

                <dl className="cp-totals" aria-live="polite">
                    <div className={dealerTotal > dealerCap ? 'is-bad' : ''}>
                        <dt>{t('total_dealer')}</dt>
                        <dd>{showPercent(fromBasisPoints(dealerTotal))} <small>{t('limit_of', { limit: localizeDigits(String(dealerCap / 100)) })}</small></dd>
                    </div>
                    <div className={subTotal > subCap ? 'is-bad' : ''}>
                        <dt>{t('total_sub_dealer')}</dt>
                        <dd>{showPercent(fromBasisPoints(subTotal))} <small>{t('limit_of', { limit: localizeDigits(String(subCap / 100)) })}</small></dd>
                    </div>
                </dl>
                {overCap && <p className="cp-error" role="alert">{t('err_cap')}</p>}
                {tried && nothingChanged && <p className="cp-error" role="alert">{t('err_no_change')}</p>}

                {reasonField.shown && (
                    <label className="cp-reason">
                        {reasonField.label(t('reason_label'))}
                        <input type="text" maxLength={500} required={reasonField.required} value={reason} onChange={(event) => setReason(event.target.value)} />
                    </label>
                )}
                {tried && reasonMissing && <p className="cp-error" role="alert">{t('err_reason')}</p>}
                {serverError && <p className="cp-error" role="alert">{serverError}</p>}
            </div>
        </Modal>
    );
}
