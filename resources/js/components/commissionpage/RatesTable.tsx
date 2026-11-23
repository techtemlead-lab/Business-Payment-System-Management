import React, { useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { money } from '../UI';
import { localizeDigits } from '../../business-date';
import { showPercent, type Head, type PageTotals } from './shared';

/** The commissions as one calm table; a commission that is split into parts opens in place. */
export default function RatesTable({ heads, totals }: { heads: Head[]; totals: PageTotals }) {
    const translate = useT();
    const [open, setOpen] = useState<string[]>([]);
    const toggle = (key: string) => setOpen((current) => (current.includes(key) ? current.filter((item) => item !== key) : [...current, key]));
    const t = (key: string, replace: Record<string, string> = {}) => translate('commission_page', key, replace);

    return (
        <div className="cp-table-wrap">
            <table className="cp-table">
                <thead>
                    <tr>
                        <th scope="col">{t('col_name')}</th>
                        <th scope="col">{t('col_kind')}</th>
                        <th scope="col" className="is-num">{t('col_dealer')}</th>
                        <th scope="col" className="is-num">{t('col_sub_dealer')}</th>
                    </tr>
                </thead>
                <tbody>
                    {heads.length === 0 && (
                        <tr><td colSpan={4} className="cp-empty">{t('table_empty')}</td></tr>
                    )}
                    {heads.map((head, index) => {
                        const isOpen = open.includes(head.key);
                        const startsSubBlock = head.block === 'sub_only' && heads[index - 1]?.block !== 'sub_only';
                        const hasParts = head.children.length > 0;
                        const panelId = `cp-parts-${head.key}`;

                        return (
                            <React.Fragment key={head.key}>
                                {startsSubBlock && (
                                    <tr className="cp-block-row">
                                        <th scope="rowgroup" colSpan={4}>
                                            <span>{t('subblock_title')}</span>
                                            <small>{t('subblock_lead')}</small>
                                        </th>
                                    </tr>
                                )}
                                <tr className={isOpen ? 'is-open' : undefined}>
                                    <td data-label={t('col_name')} className="cp-name">
                                        {hasParts ? (
                                            <button type="button" className="cp-expand" aria-expanded={isOpen} aria-controls={panelId} onClick={() => toggle(head.key)}>
                                                <ChevronDown size={18} aria-hidden="true" />
                                                <span>{t(`head_${head.key}`)}</span>
                                                <span className="sr-only">{isOpen ? t('parts_close') : t('parts_open')}</span>
                                            </button>
                                        ) : (
                                            <span className="cp-plain">{t(`head_${head.key}`)}</span>
                                        )}
                                        {!head.is_active && <span className="cp-off">{t('off')}</span>}
                                        {head.destination !== null && <small className="cp-subline">{t('extra_goes_label')}: {t(`dest_${head.destination}`)}</small>}
                                    </td>
                                    <td data-label={t('col_kind')} className="cp-kind">{t(`kind_${head.kind}`) === t(`head_${head.key}`) ? '' : t(`kind_${head.kind}`)}</td>
                                    <td data-label={t('col_dealer')} className="is-num">
                                        {head.dealer_pct === null ? <><span aria-hidden="true">—</span><span className="sr-only">{t('not_for_dealer')}</span></> : showPercent(head.dealer_pct)}
                                    </td>
                                    <td data-label={t('col_sub_dealer')} className="is-num">{showPercent(head.sub_dealer_pct)}</td>
                                </tr>
                                {hasParts && isOpen && (
                                    <tr className="cp-parts-row">
                                        <td colSpan={4} id={panelId}>
                                            <div className="cp-parts">
                                                <p className="cp-parts-title">{t(`parts_title_${head.key}`)}</p>
                                                <ul>
                                                    {head.children.map((part) => (
                                                        <li key={part.key}>
                                                            <span>{t(`part_${head.key}_${part.key}`)}</span>
                                                            <b>{part.unit === 'money' ? money(part.pct) : showPercent(part.pct)}</b>
                                                        </li>
                                                    ))}
                                                </ul>
                                                {head.children_total !== null ? (
                                                    <p className={`cp-parts-sum${head.children_match ? ' is-ok' : ' is-bad'}`}>
                                                        {head.children_match && <Check size={16} aria-hidden="true" />}
                                                        {t(head.children_match ? 'parts_sum_ok' : 'parts_sum_bad', { sum: localizeDigits(String(parseFloat(head.children_total))), total: localizeDigits(String(parseFloat(head.percentage))) })}
                                                    </p>
                                                ) : (
                                                    <p className="cp-parts-sum">{t('parts_money_note')}</p>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </React.Fragment>
                        );
                    })}
                </tbody>
                <tfoot>
                    <tr>
                        <td data-label={t('col_name')}>{t('row_total')}</td>
                        <td data-label={t('col_kind')} className="cp-foot-note">{t('row_total_note')}</td>
                        <td data-label={t('col_dealer')} className="is-num">
                            {showPercent(totals.dealer_pct)}
                            <small>{t('limit_of', { limit: localizeDigits(String(parseFloat(totals.dealer_cap_pct))) })}</small>
                        </td>
                        <td data-label={t('col_sub_dealer')} className="is-num">
                            {showPercent(totals.sub_dealer_pct)}
                            <small>{t('limit_of', { limit: localizeDigits(String(parseFloat(totals.sub_dealer_cap_pct))) })}</small>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
}
