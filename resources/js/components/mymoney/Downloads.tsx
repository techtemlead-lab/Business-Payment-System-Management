import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ChevronDown, Download } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import type { Certificates } from './types';

/** The partner's files in one menu: statement, full report and certificates. */
export default function Downloads({ certificates, statementQuery }: { certificates: Certificates; statementQuery: string }) {
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

    const statement = (format: 'xlsx' | 'pdf') => `${base}/statement/${format}${statementQuery ? `?${statementQuery}` : ''}`;
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
            </div>
        </details>
    );
}
