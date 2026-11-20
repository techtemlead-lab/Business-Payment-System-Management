import React, { useState } from 'react';
import { Calculator, RefreshCw } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { money } from '../UI';
import type { OfficeHub } from '../OfficeHubTabs';
import BuyerSwitch from './BuyerSwitch';
import CommissionBottomNav from './CommissionBottomNav';
import { showPercent, xsrfToken, type BuyerType } from './shared';

interface SplitLine {
    key: string;
    pct: string;
    amount: string;
    parts: { key: string; pct: string; amount: string }[];
}

interface SplitResult {
    buyer_type: BuyerType;
    amount: string;
    lines: SplitLine[];
    total_amount: string;
    company: { pct: string; mrp: string; amount: string } | null;
    left_amount: string | null;
}

const MONEY_TEXT = /^\d{1,11}(\.\d{1,2})?$/;

/**
 * The commission calculator: type what the buyer pays and who the buyer is, and see how every commission
 * splits. It reads the live rates and posts nothing (CommissionPageModel::splitFor).
 */
export default function CommissionCalculator({ hub }: { hub?: OfficeHub }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('commission_page', key, replace);
    const [buyer, setBuyer] = useState<BuyerType>('dealer');
    const [amount, setAmount] = useState('');
    const [mrp, setMrp] = useState('');
    const [result, setResult] = useState<SplitResult | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    const calculate = async (event: React.FormEvent) => {
        event.preventDefault();
        setError('');
        if (!MONEY_TEXT.test(amount.trim()) || Number(amount) <= 0) {
            setError(t('calc_err_amount'));
            return;
        }
        if (mrp.trim() !== '' && (!MONEY_TEXT.test(mrp.trim()) || Number(mrp) <= 0)) {
            setError(t('calc_err_mrp'));
            return;
        }
        setLoading(true);
        try {
            const response = await fetch('/commands/commissions/split', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
                body: JSON.stringify({ buyer_type: buyer, amount: amount.trim(), ...(mrp.trim() !== '' ? { mrp: mrp.trim() } : {}) }),
            });
            if (response.ok) {
                setResult(await response.json());
                return;
            }
            const payload = await response.json().catch(() => null);
            setError(String(payload?.message ?? (payload?.errors ? Object.values(payload.errors).flat().join(' ') : t('calc_err_failed'))));
        } catch {
            setError(t('calc_err_reach'));
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="cp-page">
            <header className="cp-head">
                <h1>{t('calc_title')}</h1>
                <p>{t('calc_lead')}</p>
            </header>

            <section className="cp-card" aria-labelledby="cp-calc-form">
                <h2 id="cp-calc-form" className="sr-only">{t('calc_title')}</h2>
                <form className="cp-calc-form" onSubmit={calculate} noValidate>
                    <div className="cp-calc-who">
                        <span className="cp-label-text">{t('calc_buyer')}</span>
                        <BuyerSwitch value={buyer} onChange={(type) => { setBuyer(type); setResult(null); }} label={t('calc_buyer')} />
                    </div>
                    <label className="cp-label">
                        {t('calc_amount')}
                        <span className="cp-field"><input type="text" inputMode="decimal" autoComplete="off" value={amount} onChange={(event) => setAmount(event.target.value)} aria-invalid={error !== ''} /><span aria-hidden="true">৳</span></span>
                    </label>
                    <label className="cp-label">
                        {t('calc_mrp')}
                        <span className="cp-field"><input type="text" inputMode="decimal" autoComplete="off" value={mrp} onChange={(event) => setMrp(event.target.value)} /><span aria-hidden="true">৳</span></span>
                    </label>
                    <button type="submit" className="button dark" disabled={loading}>
                        {loading ? <RefreshCw size={16} className="spin" aria-hidden="true" /> : <Calculator size={16} aria-hidden="true" />}
                        {loading ? t('calc_running') : t('calc_run')}
                    </button>
                </form>
                {error !== '' && <p className="cp-error" role="alert">{error}</p>}
            </section>

            <section className="cp-card" aria-live="polite" aria-labelledby="cp-calc-result">
                <h2 id="cp-calc-result">{t('calc_result')}</h2>
                {result === null ? <p className="cp-empty">{t('calc_empty')}</p> : (
                    <>
                        <p className="cp-calc-sum">{t('calc_sum', { amount: money(result.amount), total: money(result.total_amount) })}</p>
                        <div className="cp-table-wrap">
                            <table className="cp-table cp-calc-table">
                                <thead>
                                    <tr>
                                        <th scope="col">{t('col_name')}</th>
                                        <th scope="col" className="is-num">{t('calc_col_pct')}</th>
                                        <th scope="col" className="is-num">{t('calc_col_amount')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {result.lines.map((line) => (
                                        <React.Fragment key={line.key}>
                                            <tr>
                                                <td data-label={t('col_name')} className="cp-name"><span className="cp-plain">{t(`head_${line.key}`)}</span></td>
                                                <td data-label={t('calc_col_pct')} className="is-num">{showPercent(line.pct)}</td>
                                                <td data-label={t('calc_col_amount')} className="is-num">{money(line.amount)}</td>
                                            </tr>
                                            {line.parts.map((part) => (
                                                <tr key={part.key} className="cp-calc-part">
                                                    <td data-label={t('col_name')} className="cp-name"><span className="cp-plain">{t(`part_${line.key}_${part.key}`)}</span></td>
                                                    <td data-label={t('calc_col_pct')} className="is-num">{showPercent(part.pct)}</td>
                                                    <td data-label={t('calc_col_amount')} className="is-num">{money(part.amount)}</td>
                                                </tr>
                                            ))}
                                        </React.Fragment>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td data-label={t('col_name')}>{t('calc_total')}</td>
                                        <td data-label={t('calc_col_pct')} className="is-num" />
                                        <td data-label={t('calc_col_amount')} className="is-num">{money(result.total_amount)}</td>
                                    </tr>
                                    {result.company && (
                                        <tr>
                                            <td data-label={t('col_name')}>{t('calc_company', { mrp: money(result.company.mrp) })}</td>
                                            <td data-label={t('calc_col_pct')} className="is-num">{showPercent(result.company.pct)}</td>
                                            <td data-label={t('calc_col_amount')} className="is-num">{money(result.company.amount)}</td>
                                        </tr>
                                    )}
                                    {result.left_amount !== null && (
                                        <tr>
                                            <td data-label={t('col_name')}>{t('calc_left')}</td>
                                            <td data-label={t('calc_col_pct')} className="is-num" />
                                            <td data-label={t('calc_col_amount')} className="is-num">{result.left_amount.startsWith('-') ? `- ${money(result.left_amount.slice(1))}` : money(result.left_amount)}</td>
                                        </tr>
                                    )}
                                </tfoot>
                            </table>
                        </div>
                        <p className="cp-hint">{t('calc_note')}</p>
                    </>
                )}
            </section>

            <CommissionBottomNav hub={hub} />
        </div>
    );
}
