import { useState, type MouseEvent } from 'react';
import { Download } from 'lucide-react';
import { businessTodayIso } from '../../business-date';
import { useT } from '../../shared/i18n/t';
import MoreMenu from '../office/MoreMenu';

type TableDates = { period: string; from: string | null; to: string | null };
type DateRange = { from: string; to: string };
type Limited = 'reports' | 'statements';
type FileChoice = { key: string; label: string; href: string; limited: Limited };
type FileGroup = { key: string; label: string; files: FileChoice[] };

/** The files this person may take, as the server allows them. */
export type DownloadChoices = { groups: { key: string; label: string; reports: string[] }[]; statements: boolean };

const REPORT_ROOT = '/commands/operations/reports';
const STATEMENT_ROOT = '/commands/operations/accounting/export';
const STATEMENT_TYPES = ['profit-loss', 'balance-sheet', 'trial-balance'];

const ALLOWED_PER_MINUTE = 15;
const MINUTE_MS = 60_000;

/** The server allows 15 files a minute for each kind. Stay under it here so a person never lands on an error page. */
const recentFiles: Record<Limited, number[]> = { reports: [], statements: [] };

function mayTakeFile(limited: Limited, now: number): boolean {
    recentFiles[limited] = recentFiles[limited].filter((taken) => now - taken < MINUTE_MS);
    if (recentFiles[limited].length >= ALLOWED_PER_MINUTE) {
        return false;
    }
    recentFiles[limited].push(now);

    return true;
}

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

/** One menu for every file a person may take, in groups. Each file covers the dates the table is showing. */
export default function DownloadsMenu({ state, choices }: { state: TableDates; choices: DownloadChoices }) {
    const translate = useT();
    const [tooMany, setTooMany] = useState(false);

    const range = fileRange(state);
    const fileName = (type: string) => translate('treasury', `dl_${type.replaceAll('-', '_')}`);
    const groups: FileGroup[] = choices.groups.map((group) => ({
        key: group.key,
        label: group.label,
        files: group.reports.map((type) => ({ key: type, label: fileName(type), href: fileHref(REPORT_ROOT, type, range), limited: 'reports' })),
    }));
    if (choices.statements) {
        groups.push({
            key: 'statements',
            label: translate('treasury', 'dl_group_statements'),
            files: STATEMENT_TYPES.map((type) => ({ key: type, label: fileName(type), href: fileHref(STATEMENT_ROOT, type, range), limited: 'statements' })),
        });
    }
    if (groups.length === 0) {
        return null;
    }
    const takeFile = (event: MouseEvent, file: FileChoice): boolean => {
        if (!mayTakeFile(file.limited, Date.now())) {
            event.preventDefault();
            setTooMany(true);

            return false;
        }

        return true;
    };

    return (
        <MoreMenu
            label={translate('treasury', 'downloads')}
            icon={<Download size={14} aria-hidden="true" />}
            note={tooMany ? translate('treasury', 'dl_too_many') : undefined}
            onClosed={() => setTooMany(false)}
            groups={groups.map((group) => ({
                key: group.key,
                label: group.label,
                items: group.files.map((file) => ({ key: file.key, label: file.label, href: file.href, onSelect: (event: MouseEvent) => takeFile(event, file) })),
            }))}
        />
    );
}
