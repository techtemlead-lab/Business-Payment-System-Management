import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { actionAllowed } from '../../action-permissions';
import { Modal, Pagination, Table, date, label, money, type Row } from '../../UI';
import type { OfficeHub } from '../../OfficeHubTabs';
import OfficePage from '../OfficePage';
import { businessMonth } from '../../../business-date';
import { useT } from '../../../shared/i18n/t';
import DealerApplicationsView from './DealerApplicationsView';
import { commandRoot, currentView, DrawerLines, findTable, pickField, SectionTableSearch, StaffActionDialog, textField, ViewSwitch, type StaffAction, type StaffSectionTable } from './staffSectionParts';

type SrView = 'areas' | 'targets' | 'applications';

export type StaffOperationsProps = {
    tables: StaffSectionTable[];
    staff: Row[];
    hub?: OfficeHub | null;
};

const areaPath = (row: Row): string => [row.division, row.district, row.upazila, row.union].filter(Boolean).join(' / ');

/** The Staff "SR areas and targets" tab: one table per view, forms in dialogs, removal in the drawer. */
export default function StaffOperations({ tables, staff, hub }: StaffOperationsProps) {
    const translate = useT();
    const { staffActions } = usePage<any>().props;
    const { url } = usePage();
    const [dialog, setDialog] = useState<StaffAction | null>(null);
    const [openedId, setOpenedId] = useState<number | null>(null);

    const areas = findTable(tables, 'territories');
    const targets = findTable(tables, 'monthly_targets');
    const applications = findTable(tables, 'dealer_applications');
    const views: { key: SrView; label: string }[] = [
        { key: 'areas', label: translate('office_staff', 'sr_view_areas') },
        { key: 'targets', label: translate('office_staff', 'sr_view_targets') },
        ...(applications ? [{ key: 'applications' as const, label: translate('office_staff', 'sr_view_applications') }] : []),
    ];
    const view = currentView(url, views.map((item) => item.key), 'areas');
    const opened = areas?.page.data.find((row) => row.id === openedId) ?? null;

    const salesReps = staff.filter((person) => person.field_sales);
    const salesRepField = pickField(salesReps, 'staff_id', translate('portal', 'ops_fld_sales_rep'));
    const forms: { label: string; primary: boolean; action: StaffAction }[] = [
        { label: translate('office_staff', 'give_area'), primary: true, action: { title: translate('portal', 'ops_assign_territory'), action: `${commandRoot}/staff/territories`, fields: [salesRepField, textField('place_code', translate('portal', 'ops_fld_place_code'))] } },
        { label: translate('office_staff', 'set_target'), primary: false, action: { title: translate('portal', 'ops_set_monthly_target'), action: `${commandRoot}/staff/targets`, fields: [salesRepField, textField('period_month', translate('portal', 'ops_fld_target_month'), 'month'), textField('target', translate('portal', 'ops_fld_sales_target'), 'number'), textField('bonus', translate('portal', 'ops_fld_bonus'), 'number'), textField('version', translate('portal', 'ops_fld_version_revise'), 'number', false)] } },
    ];
    const allowedForms = forms.filter((form) => actionAllowed(form.action.action, staffActions, 'post'));

    const removeAction = (row: Row): StaffAction | null => {
        if (!row.is_active) {
            return null;
        }
        const action: StaffAction = { title: translate('portal', 'ops_remove_territory'), action: `${commandRoot}/staff/territories/${row.id}/remove`, fields: [{ name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true }] };

        return actionAllowed(action.action, staffActions) ? action : null;
    };
    const openedRemove = opened ? removeAction(opened) : null;
    const yesNo = (value: unknown): string => translate('portal', value ? 'ops_fld_yes' : 'ops_fld_no');

    return (
        <OfficePage
            title={translate('office_staff', 'page_title')}
            hub={hub}
            action={allowedForms.length > 0 ? (
                <div className="ops-row is-wrap">
                    {allowedForms.map((form) => <button key={form.label} type="button" className={form.primary ? 'button dark' : 'button'} onClick={() => setDialog(form.action)}>{form.label}</button>)}
                </div>
            ) : undefined}
        >
            <ViewSwitch views={views} active={view} fallback="areas" label={translate('office_staff', 'views_label')} />

            {view === 'areas' && areas && (
                <>
                    <SectionTableSearch table={areas} />
                    <Table
                        searchable={false}
                        printable
                        totalCount={areas.page.total}
                        rows={areas.page.data}
                        empty={translate('office_staff', 'areas_empty')}
                        onRowClick={(row: Row) => setOpenedId(Number(row.id))}
                        rowClassName={() => 'is-link'}
                        columns={[
                            { key: 'name', title: translate('office_staff', 'col_sales_rep'), render: (row: Row) => <b>{row.name}</b> },
                            { key: 'area', title: translate('office_staff', 'col_area'), render: (row: Row) => areaPath(row) || '—' },
                            { key: 'level', title: translate('office_staff', 'col_level'), render: (row: Row) => (row.level ? label(String(row.level), translate) : '—') },
                            { key: 'is_active', title: translate('office_staff', 'col_active'), render: (row: Row) => yesNo(row.is_active) },
                            { key: 'created_at', title: translate('office_staff', 'col_date'), render: (row: Row) => date(String(row.created_at)) },
                        ]}
                    />
                    <Pagination page={areas.page} />
                </>
            )}

            {view === 'targets' && targets && (
                <>
                    <SectionTableSearch table={targets} />
                    <Table
                        searchable={false}
                        printable
                        totalCount={targets.page.total}
                        rows={targets.page.data}
                        empty={translate('office_staff', 'targets_empty')}
                        columns={[
                            { key: 'name', title: translate('office_staff', 'col_sales_rep'), render: (row: Row) => <b>{row.name}</b> },
                            { key: 'period_month', title: translate('office_staff', 'col_month'), render: (row: Row) => businessMonth(String(row.period_month)) },
                            { key: 'target', title: translate('office_staff', 'col_target'), money: true, render: (row: Row) => money(row.target) },
                            { key: 'bonus', title: translate('office_staff', 'col_bonus'), money: true, render: (row: Row) => money(row.bonus) },
                            { key: 'version', title: translate('office_staff', 'col_change_number') },
                        ]}
                    />
                    <Pagination page={targets.page} />
                </>
            )}

            {view === 'applications' && applications && <DealerApplicationsView table={applications} />}

            {opened && (
                <Modal
                    title={String(opened.name)}
                    variant="drawer"
                    onClose={() => setOpenedId(null)}
                    footer={openedRemove ? <button type="button" className="button dark" onClick={() => { setOpenedId(null); setDialog(openedRemove); }}>{openedRemove.title}</button> : undefined}
                >
                    <DrawerLines
                        lines={[
                            { label: translate('office_staff', 'col_area'), value: areaPath(opened) || '—' },
                            { label: translate('office_staff', 'col_level'), value: opened.level ? label(String(opened.level), translate) : null },
                            { label: translate('office_staff', 'col_active'), value: yesNo(opened.is_active) },
                            { label: translate('office_staff', 'col_date'), value: date(String(opened.created_at)) },
                        ]}
                    />
                </Modal>
            )}

            {dialog && <StaffActionDialog action={dialog} onClose={() => setDialog(null)} />}
        </OfficePage>
    );
}
