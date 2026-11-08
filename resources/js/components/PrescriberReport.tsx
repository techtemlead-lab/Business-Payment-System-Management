import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { DateInput, Empty, money } from './UI';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type PrescriberReportData = {
    from: string;
    to: string;
    grouped_by: 'day' | 'month';
    prescriptions: number;
    patients: number;
    billed: string;
    discount: string;
    clinic: { sales: string; manufacturer_share: string; clinic_fund: string } | null;
    rows: {
        period: string;
        prescriptions: number;
        patients: number;
        billed: string;
        sales?: string;
        manufacturer_share?: string;
        clinic_fund?: string;
    }[];
};

/** Prescriptions, patients and billed totals for a chosen period. */
export function PrescriberReportTab({ report, translate }: { report: PrescriberReportData; translate: Translate }) {
    const { portalBase } = usePage<any>().props;
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const clinic = report.clinic;

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h2>{translate('prescription', 'mr_title')}</h2>
                    <p className="muted">{translate('prescription', clinic ? 'mr_intro_clinic' : 'mr_intro_partner')}</p>
                </div>
            </div>
            <form className="rx-report-filter" onSubmit={(e) => { e.preventDefault(); router.get(`${portalBase ?? ''}/prescriptions`, { from, to, tab: 'my-report' }, { preserveScroll: true }); }}>
                <label>{translate('prescription', 'report_from')}<DateInput value={from} onChange={(e) => setFrom(e.target.value)} /></label>
                <label>{translate('prescription', 'report_to')}<DateInput value={to} min={from} onChange={(e) => setTo(e.target.value)} /></label>
                <button className="button dark">{translate('prescription', 'report_apply')}</button>
            </form>
            <div className="rx-stats">
                <div><small>{translate('prescription', 'mr_prescriptions')}</small><b>{report.prescriptions}</b></div>
                <div><small>{translate('prescription', 'mr_patients')}</small><b>{report.patients}</b></div>
                {clinic ? (
                    <>
                        <div><small>{translate('prescription', 'mr_sales')}</small><b>{money(clinic.sales)}</b></div>
                        <div><small>{translate('prescription', 'report_manufacturer')}</small><b>{money(clinic.manufacturer_share)}</b></div>
                        <div><small>{translate('prescription', 'report_clinic')}</small><b className={Number(clinic.clinic_fund) < 0 ? 'is-bad' : undefined}>{money(clinic.clinic_fund)}</b></div>
                    </>
                ) : (
                    <>
                        <div><small>{translate('prescription', 'mr_billed')}</small><b>{money(report.billed)}</b></div>
                        <div><small>{translate('prescription', 'mr_discount')}</small><b>{money(report.discount)}</b></div>
                    </>
                )}
            </div>
            {report.rows.length === 0 ? (
                <Empty title={translate('prescription', 'mr_empty')} />
            ) : (
                <div className="table-wrap">
                    <table className="rx-table">
                        <thead><tr>
                            <th>{translate('prescription', report.grouped_by === 'month' ? 'mr_month' : 'report_day')}</th>
                            <th className="num">{translate('prescription', 'mr_prescriptions')}</th>
                            <th className="num">{translate('prescription', 'mr_patients')}</th>
                            {clinic ? (
                                <>
                                    <th className="num">{translate('prescription', 'mr_sales')}</th>
                                    <th className="num">{translate('prescription', 'report_manufacturer')}</th>
                                    <th className="num">{translate('prescription', 'report_clinic')}</th>
                                </>
                            ) : (
                                <th className="num">{translate('prescription', 'mr_billed')}</th>
                            )}
                        </tr></thead>
                        <tbody>
                            {report.rows.map((row) => (
                                <tr key={row.period}>
                                    <td>{row.period}</td>
                                    <td className="num">{row.prescriptions}</td>
                                    <td className="num">{row.patients}</td>
                                    {clinic ? (
                                        <>
                                            <td className="num">{money(row.sales ?? '0')}</td>
                                            <td className="num">{money(row.manufacturer_share ?? '0')}</td>
                                            <td className="num">{money(row.clinic_fund ?? '0')}</td>
                                        </>
                                    ) : (
                                        <td className="num">{money(row.billed)}</td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}
