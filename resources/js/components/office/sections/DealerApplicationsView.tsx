import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { actionAllowed } from '../../action-permissions';
import { Badge, Modal, Pagination, Table, date, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import { commandRoot, DrawerLines, readStored, SectionTableSearch, StaffActionDialog, StoredDetails, textField, type StaffAction, type StaffField, type StaffSectionTable } from './staffSectionParts';

/** Dealer applications on the SR tabs: one table, and a row's details and review are in its drawer. */
export default function DealerApplicationsView({ table }: { table: StaffSectionTable }) {
    const translate = useT();
    const { auth, staffActions } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const [openedId, setOpenedId] = useState<number | null>(null);
    const [dialog, setDialog] = useState<StaffAction | null>(null);
    const opened = table.page.data.find((row) => row.id === openedId) ?? null;
    const applicant = (row: Row): Row => {
        const stored = readStored(row.data);

        return typeof stored === 'object' && stored !== null ? (stored as Row) : {};
    };

    const reviewFor = (row: Row): StaffAction | null => {
        if (row.status !== 'pending' || !capabilities.includes('accounts') || row.submitted_by === auth.user.id) {
            return null;
        }
        const reason: StaffField = { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true };
        const review: StaffAction = {
            title: translate('portal', 'ops_review_application'),
            action: `${commandRoot}/staff/applications/${row.id}/review`,
            fields: [
                { name: 'decision', label: translate('portal', 'ops_fld_decision'), required: true, options: ['approved', 'rejected'].map((value) => ({ value, label: translate('portal', `status_${value}`) })) },
                reason,
                textField('place_code', translate('portal', 'ops_fld_place_code_review'), 'text', false),
            ],
        };

        return actionAllowed(review.action, staffActions) ? review : null;
    };

    const openedReview = opened ? reviewFor(opened) : null;

    return (
        <>
            <SectionTableSearch table={table} />
            <Table
                searchable={false}
                printable
                totalCount={table.page.total}
                rows={table.page.data}
                empty={translate('office_staff', 'applications_empty')}
                onRowClick={(row: Row) => setOpenedId(Number(row.id))}
                rowClassName={() => 'is-link'}
                columns={[
                    { key: 'created_at', title: translate('office_staff', 'col_date'), render: (row: Row) => date(String(row.created_at)) },
                    { key: 'name', title: translate('office_staff', 'col_applicant'), render: (row: Row) => <><b>{applicant(row).name ?? '—'}</b><small className="cell-sub">{row.email}</small></> },
                    { key: 'status', title: translate('office_staff', 'col_status'), render: (row: Row) => <Badge status={String(row.status)} /> },
                ]}
            />
            <Pagination page={table.page} />

            {opened && (
                <Modal
                    title={String(applicant(opened).name ?? opened.email)}
                    variant="drawer"
                    badge={<Badge status={String(opened.status)} />}
                    onClose={() => setOpenedId(null)}
                    footer={openedReview ? <button type="button" className="button dark" onClick={() => { setOpenedId(null); setDialog(openedReview); }}>{openedReview.title}</button> : undefined}
                >
                    <DrawerLines
                        lines={[
                            { label: translate('office_staff', 'col_date'), value: date(String(opened.created_at)) },
                            { label: translate('office_staff', 'detail_email'), value: opened.email },
                            { label: translate('office_staff', 'detail_reason'), value: opened.reason },
                        ]}
                    />
                    <div className="opr-json"><StoredDetails value={readStored(opened.data)} /></div>
                </Modal>
            )}
            {dialog && <StaffActionDialog action={dialog} onClose={() => setDialog(null)} />}
        </>
    );
}
