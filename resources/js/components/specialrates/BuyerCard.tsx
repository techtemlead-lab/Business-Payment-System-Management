import React, { useMemo, useState } from 'react';
import { ChevronDown, RotateCcw } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { money } from '../UI';
import { SUB_DEALER_ONLY, fromBasisPoints, showPercent, toBasisPoints, type Head } from '../commissionpage/shared';
import { poolTotals, rowProblems, ruleEntry, startDrafts, type Drafts, type RowDraft } from '../commissionpage/rateDraft';
import ScheduleBox from './ScheduleBox';
import type { Person, SpecialData } from './types';

/**
 * The plan on this person's own PURCHASES: every commission with the general rate next to the person's own, the same
 * parts and the same sum checks as the commission page. The engine writes the whole plan, so a commission left as the
 * general rate simply follows the general rate at the time the special rate starts.
 */
export default function BuyerCard({ special, person }: { special: SpecialData; person: Person }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const tc = (key: string, replace: Record<string, string> = {}) => translate('commission_page', key, replace);
    const heads = special.heads.filter((head) => person.role === 'sub_dealer' || !SUB_DEALER_ONLY.includes(head.key));
    const [drafts, setDrafts] = useState<Drafts>(() => startDrafts(special.buyer_current?.heads ?? special.heads));
    const [open, setOpen] = useState<string[]>([]);
    const base = useMemo(() => startDrafts(special.heads), [special.heads]);

    const setPct = (key: string, value: string) => setDrafts((all) => ({ ...all, [key]: { ...all[key], pct: value } }));
    const setPart = (key: string, part: string, value: string) => setDrafts((all) => ({ ...all, [key]: { ...all[key], parts: { ...all[key].parts, [part]: value } } }));
    const reset = (key: string) => setDrafts((all) => ({ ...all, [key]: { ...base[key] } }));
    const differs = (head: Head, draft: RowDraft) => toBasisPoints(draft.pct) !== toBasisPoints(head.percentage)
        || head.children.some((part) => part.param !== null && (part.unit === 'money'
            ? Number(draft.parts[part.key]) !== Number(part.pct)
            : toBasisPoints(draft.parts[part.key] ?? '') !== toBasisPoints(part.pct)));

    const result = useMemo(() => {
        const rules: Record<string, Record<string, unknown>> = {};
        const summary: string[] = [];
        const problems: Record<string, ReturnType<typeof rowProblems>> = {};
        for (const head of special.heads) {
            const draft = drafts[head.key];
            const list = rowProblems(head, draft);
            if (list.length > 0) {
                problems[head.key] = list;
                continue;
            }
            const different = Object.keys(ruleEntry(head, head, draft, false)).length > 0;
            // The whole plan is written, so a commission set back to the general rate really goes back.
            rules[head.key] = ruleEntry(head, head, draft, false, true);
            if (!different) {
                continue;
            }
            const typed = toBasisPoints(draft.pct) ?? 0;
            if (typed !== toBasisPoints(head.percentage)) {
                summary.push(`${tc(`head_${head.key}`)}: ${showPercent(fromBasisPoints(typed))} (${t('general')}: ${showPercent(head.percentage)})`);
            }
            for (const part of head.children) {
                const now = (draft.parts[part.key] ?? '').trim();
                if (part.unit === 'percent' && head.key !== 'car_bike_incentive' && toBasisPoints(now) !== toBasisPoints(part.pct)) {
                    summary.push(`${tc(`head_${head.key}`)} · ${tc(`part_${head.key}_${part.key}`)}: ${showPercent(now)} (${t('general')}: ${showPercent(part.pct)})`);
                } else if (part.unit === 'money' && Number(now) !== Number(part.pct)) {
                    summary.push(`${tc(`head_${head.key}`)} · ${tc(`part_${head.key}_${part.key}`)}: ${money(now)} (${t('general')}: ${money(part.pct)})`);
                } else if (head.key === 'car_bike_incentive' && part.key === 'bike' && toBasisPoints(now) !== toBasisPoints(part.pct)) {
                    summary.push(`${tc(`head_${head.key}`)} · ${tc('part_car_bike_incentive_bike')}: ${showPercent(now)} (${t('general')}: ${showPercent(part.pct)})`);
                }
            }
        }

        // Nothing differs from the general plan: there is no special plan to write (to remove one, stop it in the list).
        return { rules: summary.length === 0 ? {} : rules, summary, problems };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [drafts, special.heads]);
    const caps = poolTotals(special.heads, drafts, special.totals);
    const blocked = Object.keys(result.problems).length > 0 || caps.over;

    return (
        <section className="cp-card" aria-labelledby="sr-buyer-title">
            <div className="cp-card-head">
                <div>
                    <h2 id="sr-buyer-title">{t('buyer_title')}</h2>
                    <p className="cp-hint">{t('buyer_lead', { name: person.name })}</p>
                </div>
            </div>
            <div className="cp-table-wrap">
                <table className="cp-table sr-table">
                    <thead>
                        <tr>
                            <th scope="col">{t('col_commission')}</th>
                            <th scope="col" className="is-num">{t('col_general')}</th>
                            <th scope="col" className="is-num">{t('col_person', { name: person.name })}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {heads.map((head, index) => {
                            const draft = drafts[head.key];
                            const isOpen = open.includes(head.key);
                            const changed = differs(head, draft);
                            const list = result.problems[head.key] ?? [];
                            const startsBlock = head.block === 'sub_only' && heads[index - 1]?.block !== 'sub_only';

                            return (
                                <React.Fragment key={head.key}>
                                    {startsBlock && <tr className="cp-block-row"><th scope="rowgroup" colSpan={3}><span>{tc('subblock_title')}</span></th></tr>}
                                    <tr className={changed ? 'sr-changed is-higher' : undefined}>
                                        <td data-label={t('col_commission')} className="cp-name">
                                            {head.children.length > 0 ? (
                                                <button type="button" className="cp-expand" aria-expanded={isOpen} onClick={() => setOpen((all) => (all.includes(head.key) ? all.filter((key) => key !== head.key) : [...all, head.key]))}>
                                                    <ChevronDown size={18} aria-hidden="true" /><span>{tc(`head_${head.key}`)}</span>
                                                </button>
                                            ) : <label htmlFor={`sr-buy-${head.key}`} className="cp-plain">{tc(`head_${head.key}`)}</label>}
                                        </td>
                                        <td data-label={t('col_general')} className="is-num">{showPercent(head.percentage)}</td>
                                        <td data-label={t('col_person', { name: person.name })} className="is-num">
                                            <span className="sr-cell">
                                                <span className="cp-field">
                                                    <input id={`sr-buy-${head.key}`} type="text" inputMode="decimal" autoComplete="off" value={draft.pct} aria-invalid={list.length > 0} onChange={(event) => setPct(head.key, event.target.value)} />
                                                    <span aria-hidden="true">%</span>
                                                </span>
                                                {changed && <span className="cp-pill is-live">{t('state_changed')}</span>}
                                                {changed && <button type="button" className="sr-reset" onClick={() => reset(head.key)}><RotateCcw size={15} aria-hidden="true" />{t('reset')}</button>}
                                            </span>
                                        </td>
                                    </tr>
                                    {head.children.length > 0 && isOpen && head.children.map((part) => {
                                        const derived = head.key === 'car_bike_incentive' && part.key === 'car';
                                        const total = toBasisPoints(draft.pct) ?? 0;
                                        const bike = toBasisPoints(draft.parts.bike ?? '') ?? 0;

                                        return (
                                            <tr key={part.key} className="sr-part-row">
                                                <td data-label={t('col_commission')} className="cp-name"><span className="cp-plain">{tc(`part_${head.key}_${part.key}`)}</span></td>
                                                <td data-label={t('col_general')} className="is-num">{part.unit === 'money' ? money(part.pct) : showPercent(part.pct)}</td>
                                                <td data-label={t('col_person', { name: person.name })} className="is-num">
                                                    <span className="cp-field">
                                                        <input type="text" inputMode="decimal" autoComplete="off" readOnly={derived} aria-label={tc(`part_${head.key}_${part.key}`)}
                                                            value={derived ? fromBasisPoints(Math.max(0, total - bike)) : draft.parts[part.key]} onChange={(event) => setPart(head.key, part.key, event.target.value)} />
                                                        <span aria-hidden="true">{part.unit === 'money' ? '৳' : '%'}</span>
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                    {list.length > 0 && <tr className="sr-problem-row"><td colSpan={3}><p className="cp-error" role="alert">{list.map((problem) => tc(problem.key, problem.replace)).join(' ')}</p></td></tr>}
                                </React.Fragment>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr className={caps.over ? 'is-bad' : undefined}>
                            <td data-label={t('col_commission')}>{tc('row_total')}</td>
                            <td data-label={t('col_general')} className="is-num">{showPercent(special.totals.dealer_pct)}<small>{tc('limit_of', { limit: String(parseFloat(special.totals.dealer_cap_pct)) })}</small></td>
                            <td data-label={t('col_person', { name: person.name })} className="is-num">{showPercent(fromBasisPoints(person.role === 'sub_dealer' ? caps.subTotal : caps.dealerTotal))}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            {caps.over && <p className="cp-error" role="alert">{tc('err_cap')}</p>}
            <p className="cp-hint">{t('buyer_note')}</p>

            <ScheduleBox scope="buyer" person={person} rules={blocked ? {} : result.rules} lines={result.summary} workflow={special.workflow} replaces={Boolean(special.buyer_current)} />
        </section>
    );
}
