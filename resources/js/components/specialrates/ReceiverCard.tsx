import React, { useMemo, useState } from 'react';
import { RotateCcw } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { localizeDigits } from '../../business-date';
import { fromBasisPoints, showPercent, toBasisPoints } from '../commissionpage/shared';
import ScheduleBox from './ScheduleBox';
import type { Person, SpecialData } from './types';

interface Source {
    category: string;
    pct: string;
}

const EMPTY_SOURCE: Source = { category: '', pct: '' };

/**
 * What this person EARNS: the general rate of eight commissions next to the person's own, one box each. A rate above
 * the general one needs a money source (the company share, or other commissions). A rate below it needs none.
 */
export default function ReceiverCard({ special, person }: { special: SpecialData; person: Person }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('special_rates', key, replace);
    const lines = special.receiver_lines;
    const current = special.receiver_current?.rates ?? {};
    const [values, setValues] = useState<Record<string, string>>(() => Object.fromEntries(lines.map((line) => [line.category, current[line.category]?.percentage ?? line.pct])));
    const firstFunded = Object.values(current).find((rate) => rate.funding_mode);
    const [mode, setMode] = useState<'company' | 'allocations'>(firstFunded?.funding_mode ?? 'company');
    const [sources, setSources] = useState<Record<string, Source[]>>(() => Object.fromEntries(lines.map((line) => {
        const saved = Object.entries(current[line.category]?.funding_sources ?? {}).map(([category, pct]) => ({ category, pct: String(pct) }));

        return [line.category, saved.length > 0 ? saved : [{ ...EMPTY_SOURCE }]];
    })));

    const state = (category: string, general: string) => {
        const typed = toBasisPoints(values[category] ?? '');
        const base = toBasisPoints(general) ?? 0;
        if (typed === null) {
            return { kind: 'invalid' as const, typed: 0, base };
        }

        return { kind: typed === base ? 'same' as const : typed > base ? 'higher' as const : 'lower' as const, typed, base };
    };

    const result = useMemo(() => {
        const rules: Record<string, Record<string, unknown>> = {};
        const summary: string[] = [];
        const problems: Record<string, string> = {};
        for (const line of lines) {
            const row = state(line.category, line.pct);
            if (row.kind === 'invalid') {
                problems[line.category] = 'err_percent';
                continue;
            }
            if (row.kind === 'same') {
                continue;
            }
            const rule: Record<string, unknown> = { percentage: fromBasisPoints(row.typed) };
            if (row.kind === 'higher') {
                rule.funding_mode = mode;
                if (mode === 'allocations') {
                    const picked = sources[line.category] ?? [];
                    const need = row.typed - row.base;
                    const cats = picked.map((source) => source.category);
                    const points = picked.map((source) => toBasisPoints(source.pct));
                    if (picked.some((source) => source.category === '' || source.category === line.category) || new Set(cats).size !== cats.length || points.some((value) => value === null || value === 0)) {
                        problems[line.category] = 'err_sources';
                    } else if ((points as number[]).reduce((all, value) => all + value, 0) !== need) {
                        problems[line.category] = 'err_sources_sum';
                    }
                    rule.funding_sources = Object.fromEntries(picked.map((source) => [source.category, source.pct.trim()]));
                }
            }
            rules[line.category] = rule;
            summary.push(`${t(`receiver_${line.category}`)}: ${showPercent(fromBasisPoints(row.typed))} (${t('general')}: ${showPercent(line.pct)}${row.kind === 'higher' ? `, ${t(mode === 'company' ? 'funding_company' : 'funding_allocations')}` : ''})`);
        }

        return { rules, summary, problems };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [values, mode, sources, lines]);

    const anyHigher = lines.some((line) => state(line.category, line.pct).kind === 'higher');
    const hasProblem = Object.keys(result.problems).length > 0;
    const setSource = (category: string, index: number, patch: Partial<Source>) => setSources((currentSources) => ({
        ...currentSources,
        [category]: currentSources[category].map((source, position) => (position === index ? { ...source, ...patch } : source)),
    }));

    return (
        <section className="cp-card" aria-labelledby="sr-receiver-title">
            <div className="cp-card-head">
                <div>
                    <h2 id="sr-receiver-title">{t('receiver_title')}</h2>
                    <p className="cp-hint">{t('receiver_lead', { name: person.name })}</p>
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
                        {lines.map((line) => {
                            const row = state(line.category, line.pct);
                            const id = `sr-rate-${line.category}`;
                            const problem = result.problems[line.category];
                            const picked = sources[line.category] ?? [];

                            return (
                                <React.Fragment key={line.category}>
                                    <tr className={row.kind === 'higher' || row.kind === 'lower' ? `sr-changed is-${row.kind}` : undefined}>
                                        <td data-label={t('col_commission')} className="cp-name"><label htmlFor={id} className="cp-plain">{t(`receiver_${line.category}`)}</label></td>
                                        <td data-label={t('col_general')} className="is-num">{showPercent(line.pct)}</td>
                                        <td data-label={t('col_person', { name: person.name })} className="is-num">
                                            <span className="sr-cell">
                                                <span className="cp-field">
                                                    <input id={id} type="text" inputMode="decimal" autoComplete="off" value={values[line.category]} aria-invalid={row.kind === 'invalid' || Boolean(problem)}
                                                        onChange={(event) => setValues((all) => ({ ...all, [line.category]: event.target.value }))} />
                                                    <span aria-hidden="true">%</span>
                                                </span>
                                                {row.kind === 'higher' && <span className="cp-pill is-live">{t('state_higher')}</span>}
                                                {row.kind === 'lower' && <span className="cp-pill is-draft">{t('state_lower')}</span>}
                                                {(row.kind === 'higher' || row.kind === 'lower') && (
                                                    <button type="button" className="sr-reset" onClick={() => setValues((all) => ({ ...all, [line.category]: line.pct }))}>
                                                        <RotateCcw size={15} aria-hidden="true" />{t('reset')}
                                                    </button>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                    {row.kind === 'higher' && mode === 'allocations' && (
                                        <tr className="sr-sources-row">
                                            <td colSpan={3}>
                                                <div className="sr-sources">
                                                    <p className="cp-hint">{t('sources_need', { pct: localizeDigits(String(parseFloat(fromBasisPoints(row.typed - row.base)))), name: t(`receiver_${line.category}`) })}</p>
                                                    {picked.map((source, index) => (
                                                        <div className="sr-source" key={index}>
                                                            <select value={source.category} aria-label={t('source_label')} onChange={(event) => setSource(line.category, index, { category: event.target.value })}>
                                                                <option value="">{t('source_pick')}</option>
                                                                {special.funding_sources.filter((category) => category !== line.category).map((category) => <option key={category} value={category}>{t(`source_${category}`)}</option>)}
                                                            </select>
                                                            <span className="cp-field">
                                                                <input type="text" inputMode="decimal" autoComplete="off" value={source.pct} aria-label={t('source_pct')} onChange={(event) => setSource(line.category, index, { pct: event.target.value })} />
                                                                <span aria-hidden="true">%</span>
                                                            </span>
                                                        </div>
                                                    ))}
                                                    <button type="button" className="button" onClick={() => setSources((all) => ({ ...all, [line.category]: [...picked, { ...EMPTY_SOURCE }] }))}>{t('source_add')}</button>
                                                </div>
                                            </td>
                                        </tr>
                                    )}
                                    {problem && <tr className="sr-problem-row"><td colSpan={3}><p className="cp-error" role="alert">{t(problem)}</p></td></tr>}
                                </React.Fragment>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {anyHigher && (
                <fieldset className="sr-funding">
                    <legend>{t('funding_title')}</legend>
                    <label className="sr-radio"><input type="radio" name="sr-funding" checked={mode === 'company'} onChange={() => setMode('company')} />{t('funding_company')}<small>{t('funding_company_hint')}</small></label>
                    <label className="sr-radio"><input type="radio" name="sr-funding" checked={mode === 'allocations'} onChange={() => setMode('allocations')} />{t('funding_allocations')}<small>{t('funding_allocations_hint')}</small></label>
                </fieldset>
            )}
            <p className="cp-hint">{t('receiver_note')}</p>

            <ScheduleBox scope="recipient" person={person} rules={hasProblem ? {} : result.rules} lines={result.summary} workflow={special.workflow} replaces={Boolean(special.receiver_current)} />
        </section>
    );
}
