import { router, usePage } from '@inertiajs/react';
import PartnerViewSelect from './PartnerViewSelect';
import { label, type Row } from './UI';
import { useT } from '../shared/i18n/t';

/**
 * The partner's list of orders by status as one drop-down ("All (3)", "Paid (1)" ...) in place of a strip of tabs.
 * It builds the same address and query as the tab strip, so every link and saved filter keeps working.
 */
export default function PartnerStatusSelect({ counts, filters, section, statuses }: { counts: Record<string, number>; filters: Row; section: string; statuses: string[] }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const total = Object.values(counts).reduce((sum, count) => sum + Number(count || 0), 0);
    const options = [
        { value: '', label: translate('portal', 'orders_tab_all'), count: total },
        ...[...statuses, ...Object.keys(counts).filter((status) => !statuses.includes(status))].map((status) => ({
            value: status,
            label: label(status, translate),
            count: Number(counts[status] || 0),
        })),
    ];
    const go = (status: string) => {
        const query = Object.fromEntries(Object.entries({ ...filters, status }).filter(([key, value]) => value !== '' && value != null && key !== 'page'));
        router.get(`${base}/${section}`, query, { preserveState: true, preserveScroll: true });
    };

    return (
        <div className="ps-select">
            <PartnerViewSelect label={translate('portal', 'orders_filter_status')} value={String(filters.status || '')} options={options} onChange={go} />
            
        </div>
    );
}
