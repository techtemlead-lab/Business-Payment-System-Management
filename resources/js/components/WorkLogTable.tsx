import { auditActionLabel, dateTime, type Row } from './UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import { useT } from '../shared/i18n/t';

/** The work log: who changed what, on the shared table. Separate from the Treasury event log. */
export default function WorkLogTable({ data }: { data: MoneyTableData<Row> }) {
    const translate = useT();
    const columns: MoneyColumn<Row>[] = [
        { key: 'created_at', title: translate('portal', 'report_col_when'), sortKey: 'created_at', phone: 'sub', render: (row) => dateTime(row.created_at) },
        { key: 'name', title: translate('portal', 'audit_actor'), sortKey: 'name', phone: 'title', render: (row) => <b>{row.name ?? '—'}</b> },
        { key: 'action', title: translate('portal', 'audit_action'), sortKey: 'action', phone: 'sub', render: (row) => auditActionLabel(String(row.action ?? ''), translate) },
        { key: 'subject', title: translate('portal', 'audit_subject'), phone: 'hide', render: (row) => row.subject ?? '—' },
    ];

    return <MoneyTable<Row> data={data} prop="data" columns={columns} searchPlaceholder={translate('portal', 'audit_recorded_activity')} empty={translate('portal', 'empty_default_title')} />;
}
