import { useEffect, useRef, useState } from 'react';
import { ChevronDown, Download } from 'lucide-react';
import { businessTodayIso } from '../../business-date';
import { useT } from '../../shared/i18n/t';
import { useTreasuryViewer } from './TreasuryTabs';

type TableDates = { period: string; from: string | null; to: string | null };
type DateRange = { from: string; to: string };
type FileChoice = { key: string; label: string; href: string };

const REPORT_FILES: Array<{ type: string; label: string; financeOnly: boolean }> = [
    { type: 'orders', label: 'dl_orders', financeOnly: false },
    { type: 'collections', label: 'dl_money_received', financeOnly: true },
    { type: 'commissions', label: 'dl_commissions', financeOnly: true },
    { type: 'withdrawals', label: 'dl_withdrawals', financeOnly: true },
    { type: 'fund-spending', label: 'dl_fund_spending', financeOnly: true },
    { type: 'ledger', label: 'dl_all_movements', financeOnly: true },
];

const ACCOUNT_FILES = [
    { type: 'profit-loss', label: 'dl_profit_loss' },
    { type: 'balance-sheet', label: 'dl_balance_sheet' },
    { type: 'trial-balance', label: 'dl_trial_balance' },
];

function shiftedDays(iso: string, days: number): string {
    const moved = new Date(`${iso}T00:00:00Z`);
    moved.setUTCDate(moved.getUTCDate() + days);

    return moved.toISOString().slice(0, 10);
}

/** The dates a file covers: the dates on screen, or the last 12 months when the table shows all time. */
export function fileRange({ period, from, to }: TableDates): DateRange {
    const today = businessTodayIso();
    const daysSinceMonday = (new Date(`${today}T00:00:00Z`).getUTCDay() + 6) % 7;
    switch (period) {
        case 'today':
            return { from: today, to: today };
        case 'week':
            return { from: shiftedDays(today, -daysSinceMonday), to: today };
        case 'month':
            return { from: `${today.slice(0, 8)}01`, to: today };
        case 'year':
            return { from: `${today.slice(0, 5)}01-01`, to: today };
        case 'day':
            return { from: from ?? today, to: from ?? today };
        case 'custom':
            return { from: from ?? today, to: to ?? today };
        default:
            return { from: shiftedDays(today, -365), to: today };
    }
}

function fileHref(root: string, type: string, range: DateRange): string {
    return `${root}?${new URLSearchParams({ type, format: 'xlsx', ...range }).toString()}`;
}

/** One menu for every file the accountant takes away. Each file covers the dates the table is showing. */
export default function DownloadsMenu({ state }: { state: TableDates }) {
    const translate = useT();
    const viewer = useTreasuryViewer();
    const [open, setOpen] = useState(false);
    const holder = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const closeOutside = (event: MouseEvent) => {
            if (!holder.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const closeOnEscape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', closeOutside);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('mousedown', closeOutside);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [open]);

    const range = fileRange(state);
    const choices: FileChoice[] = [
        ...REPORT_FILES.filter((file) => viewer.finance || !file.financeOnly).map((file) => ({
            key: file.type,
            label: translate('treasury', file.label),
            href: fileHref('/commands/operations/reports', file.type, range),
        })),
        ...(viewer.finance ? ACCOUNT_FILES.map((file) => ({
            key: file.type,
            label: translate('treasury', file.label),
            href: fileHref('/commands/operations/accounting/export', file.type, range),
        })) : []),
    ];

    return (
        <div className="tsy-menu" ref={holder}>
            <button type="button" className="button small" aria-haspopup="menu" aria-expanded={open} onClick={() => setOpen((current) => !current)}>
                <Download size={14} aria-hidden="true" /> {translate('treasury', 'downloads')} <ChevronDown size={14} aria-hidden="true" />
            </button>
            {open && (
                <div className="tsy-menu-list" role="menu">
                    {choices.map((choice) => (
                        <a key={choice.key} role="menuitem" href={choice.href} onClick={() => setOpen(false)}>{choice.label}</a>
                    ))}
                </div>
            )}
        </div>
    );
}
