import { Fragment, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronDown, Download } from 'lucide-react';
import { businessTodayIso } from '../../business-date';
import { useT } from '../../shared/i18n/t';
import type { Certificates, ReserveReceipt, StatementTable } from './types';

function mondayOf(isoDay: string): string {
    const day = new Date(`${isoDay}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() - ((day.getUTCDay() + 6) % 7));

    return day.toISOString().slice(0, 10);
}

/** The statement file takes dates, so the view on the table is turned into the same dates. */
function statementDates({ period, from, to }: StatementTable['state'], firstYear: number | undefined): Record<string, string> {
    const today = businessTodayIso();
    switch (period) {
        case 'custom':
            return from && to ? { from, to } : {};
        case 'day':
            return from ? { from, to: from } : {};
        case 'today':
            return { from: today, to: today };
        case 'week':
            return { from: mondayOf(today), to: today };
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today };
        case 'year':
            return { from: `${today.slice(0, 4)}-01-01`, to: today };
        default:
            return firstYear ? { from: `${firstYear}-01-01`, to: today } : {};
    }
}

/** The partner's files in one menu: statement, full report, certificates and printed receipts. */
export default function Downloads({ certificates, receipts, view }: { certificates: Certificates; receipts: ReserveReceipt[]; view: StatementTable['state'] }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const menu = useRef<HTMLDetailsElement>(null);
    const [year, setYear] = useState(String(certificates.years[0] ?? ''));

    useEffect(() => {
        const closeOnOutside = (event: MouseEvent) => {
            if (menu.current?.open && !menu.current.contains(event.target as Node)) {
                menu.current.open = false;
            }
        };
        document.addEventListener('click', closeOnOutside);

        return () => document.removeEventListener('click', closeOnOutside);
    }, []);

    const dates = new URLSearchParams(statementDates(view, certificates.years[certificates.years.length - 1])).toString();
    const statement = (format: 'xlsx' | 'pdf') => `${base}/statement/${format}${dates ? `?${dates}` : ''}`;
    const item = (href: string, text: string) => (
        <a href={href} target="_blank" rel="noopener noreferrer"><Download size={15} aria-hidden="true" /> {text}</a>
    );

    return (
        <details ref={menu} className="mm-downloads">
            <summary className="button small">{translate('my_money', 'downloads')} <ChevronDown size={15} aria-hidden="true" /></summary>
            <div className="mm-downloads-menu">
                {item(statement('xlsx'), translate('portal', 'stmt_download_excel'))}
                {item(statement('pdf'), translate('portal', 'stmt_download_pdf'))}
                {item(`${base}/full-report/pdf`, translate('my_money', 'full_report_pdf'))}
                {certificates.years.length > 0 && (
                    <div className="mm-downloads-year">
                        <label>
                            <span className="sr-only">{translate('my_money', 'certificate_year')}</span>
                            <select aria-label={translate('my_money', 'certificate_year')} value={year} onChange={(event) => setYear(event.target.value)}>
                                {certificates.years.map((choice) => <option key={choice} value={choice}>{choice}</option>)}
                            </select>
                        </label>
                        {item(`${base}/income-certificate/pdf?year=${year}`, translate('portal', 'download_income_certificate'))}
                    </div>
                )}
                {certificates.rank && item(`${base}/rank-certificate/pdf`, translate('portal', 'download_rank_certificate'))}
                {receipts.map((receipt) => (
                    <Fragment key={receipt.id}>{item(`${base}/payment-receipts/${receipt.id}/print`, translate('my_money', 'receipt_number', { number: receipt.number }))}</Fragment>
                ))}
            </div>
        </details>
    );
}
