import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { actionAllowed } from '../../action-permissions';
import { Badge, Modal, Pagination, dateTime, label, money, type Row } from '../../UI';
import type { OfficeHub } from '../../OfficeHubTabs';
import OfficePage from '../OfficePage';
import { businessMonth } from '../../../business-date';
import { useT } from '../../../shared/i18n/t';
import { commandRoot, currentView, DrawerLines, findTable, pickField, readStored, SectionTableSearch, StaffActionDialog, StaffTable, StoredDetails, textField, ViewSwitch, type StaffAction, type StaffField, type StaffSectionTable } from './staffSectionParts';

type PayrollView = 'register' | 'agreements';

export type PayrollProps = {
    tables: StaffSectionTable[];
    staff: Row[];
    paymentAccounts: Row[];
    secondApprovers?: number[];
    hub?: OfficeHub | null;
};

/** The Staff salary tab: one table per view, the two forms open as dialogs, a row's work is in its drawer. */
export default function Payroll({ tables, staff, paymentAccounts, secondApprovers = [], hub }: PayrollProps) {
    const translate = useT();
    const { auth, financeActive, staffActions } = usePage<any>().props;
    const { url } = usePage();
    const capabilities: string[] = auth.capabilities;
    const [dialog, setDialog] = useState<StaffAction | null>(null);
    const [openedId, setOpenedId] = useState<string | null>(null);

    const register = findTable(tables, 'salary_register');
    const agreements = findTable(tables, 'salary_contracts');
    const views: { key: PayrollView; label: string }[] = [
        { key: 'register', label: translate('office_staff', 'salary_view_register') },
        ...(agreements ? [{ key: 'agreements' as const, label: translate('office_staff', 'salary_view_agreements') }] : []),
    ];
    const view = currentView(url, views.map((item) => item.key), 'register');
    const opened = register?.page.data.find((row) => String(row.id) === openedId) ?? null;

    const reason: StaffField = { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true };
    const staffField = pickField(staff.filter((person) => person.role !== 'super_admin'), 'staff_id', translate('portal', 'ops_fld_staff'));

    const forms: { action: StaffAction; label: string; primary: boolean }[] = [];
    if (capabilities.includes('finance')) {
        forms.push({
            label: translate('office_staff', 'add_salary_agreement'),
            primary: false,
            action: { title: translate('portal', 'ops_create_salary_contract'), action: `${commandRoot}/payroll/contracts`, fields: [staffField, textField('effective_month', translate('portal', 'ops_fld_effective_month'), 'month'), textField('base_salary', translate('portal', 'ops_fld_base_salary'), 'number'), textField('allowance', translate('portal', 'ops_fld_allowance'), 'number'), textField('deduction', translate('portal', 'ops_fld_deduction'), 'number'), reason] },
        });
    }
    forms.push({
        label: translate('office_staff', 'prepare_month_salary'),
        primary: true,
        action: { title: translate('portal', 'ops_prepare_payroll'), action: `${commandRoot}/payroll/prepare`, fields: [textField('period_month', translate('portal', 'ops_fld_closed_salary_month'), 'month')] },
    });
    const allowedForms = forms.filter((form) => actionAllowed(form.action.action, staffActions, 'post'));

    const yes = translate('self_approval', 'confirm_yes');
    const confirmAlone: StaffField = { name: 'self_approve', label: translate('self_approval', 'confirm_label'), required: true, options: [{ value: '1', label: yes }] };
    const aloneFor = (people: number[]): boolean => auth.user.role === 'super_admin' && secondApprovers.filter((id) => !people.includes(id)).length === 0;
    const alone = (action: StaffAction): StaffAction => ({
        ...action,
        title: `${action.title} (${translate('self_approval', 'alone_tag')})`,
        fields: [...action.fields.map((field) => (field.name === 'receipt' ? { ...field, required: true } : field)), confirmAlone],
    });

    const rowActions = (row: Row): StaffAction[] => {
        const actions: StaffAction[] = [];
        if (!financeActive || !capabilities.includes('finance')) {
            return actions;
        }
        if (row.status === 'draft' && row.staff_id !== auth.user.id) {
            const review: StaffAction = {
                title: translate('portal', 'ops_review_payroll'),
                action: `${commandRoot}/payroll/${row.id}/review`,
                fields: [{ name: 'decision', label: translate('portal', 'ops_fld_decision'), required: true, options: ['approved', 'rejected'].map((value) => ({ value, label: label(value, translate) })) }, reason],
            };
            if (row.prepared_by !== auth.user.id) {
                actions.push(review);
            } else if (aloneFor([row.prepared_by, row.staff_id])) {
                actions.push(alone(review));
            }
        }
        if (row.status === 'approved' && row.staff_id !== auth.user.id) {
            const pay: StaffAction = {
                title: translate('portal', 'ops_record_salary_payment'),
                action: `${commandRoot}/payroll/${row.id}/pay`,
                fields: [pickField(paymentAccounts.filter((account) => account.is_active && account.pays_out), 'payment_account_id', translate('portal', 'ops_fld_payout_account')), textField('payment_reference', translate('portal', 'ops_fld_payment_ref')), textField('paid_at', translate('portal', 'ops_fld_paid_at'), 'datetime-local'), textField('receipt', translate('portal', 'ops_fld_payment_receipt'), 'file'), reason],
            };
            if (row.approved_by !== auth.user.id) {
                actions.push(pay);
            } else if (aloneFor([row.approved_by, row.staff_id])) {
                actions.push(alone(pay));
            }
        }

        return actions.filter((action) => actionAllowed(action.action, staffActions));
    };

    const moneyColumn = (key: string, title: string, total: boolean) => ({ key, title, money: true, total, render: (row: Row) => money(row[key]) });
    const multiPage = (table: StaffSectionTable): boolean => Number(table.page.last_page ?? 1) > 1;

    const openedActions = opened ? rowActions(opened) : [];

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
            {!financeActive && capabilities.includes('finance') && <p className="muted">{translate('portal', 'ops_preview_finance_notice')}</p>}
            <ViewSwitch views={views} active={view} fallback="register" label={translate('office_staff', 'views_label')} />

            {view === 'register' && register && (
                <>
                    <SectionTableSearch table={register} />
                    <StaffTable
                        searchable={false}
                        printable
                        totalCount={register.page.total}
                        totalLabel={multiPage(register) ? translate('portal', 'table_page_total') : undefined}
                        rows={register.page.data}
                        empty={translate('office_staff', 'salary_empty')}
                        onRowClick={(row: Row) => setOpenedId(String(row.id))}
                        rowClassName={() => 'is-link'}
                        columns={[
                            { key: 'name', title: translate('office_staff', 'col_staff'), render: (row: Row) => <b>{row.name}</b> },
                            { key: 'period_month', title: translate('office_staff', 'col_month'), render: (row: Row) => businessMonth(String(row.period_month)) },
                            moneyColumn('gross', translate('office_staff', 'col_gross'), true),
                            moneyColumn('deduction', translate('office_staff', 'col_cut'), true),
                            moneyColumn('net', translate('office_staff', 'col_net'), true),
                            { key: 'status', title: translate('office_staff', 'col_status'), render: (row: Row) => <Badge status={String(row.status)} /> },
                        ]}
                    />
                    <Pagination page={register.page} />
                </>
            )}

            {view === 'agreements' && agreements && (
                <>
                    <SectionTableSearch table={agreements} />
                    <StaffTable
                        searchable={false}
                        printable
                        totalCount={agreements.page.total}
                        rows={agreements.page.data}
                        empty={translate('office_staff', 'agreements_empty')}
                        columns={[
                            { key: 'name', title: translate('office_staff', 'col_staff'), render: (row: Row) => <><b>{row.name}</b>{row.reason && <small className="cell-sub">{row.reason}</small>}</> },
                            { key: 'effective_month', title: translate('office_staff', 'col_effective_month'), render: (row: Row) => businessMonth(String(row.effective_month)) },
                            moneyColumn('base_salary', translate('office_staff', 'col_base_salary'), false),
                            moneyColumn('allowance', translate('office_staff', 'col_allowance'), false),
                            moneyColumn('deduction', translate('office_staff', 'col_cut'), false),
                            { key: 'revision', title: translate('office_staff', 'col_change_number') },
                        ]}
                    />
                    <Pagination page={agreements.page} />
                </>
            )}

            {opened && (
                <Modal
                    title={String(opened.name)}
                    variant="drawer"
                    badge={<Badge status={String(opened.status)} />}
                    onClose={() => setOpenedId(null)}
                    footer={openedActions.length > 0 || opened.has_receipt ? (
                        <>
                            {opened.has_receipt && <a className="button" href={`${commandRoot}/receipts/payroll/${opened.id}`}>{translate('portal', 'ops_fld_private_evidence')}</a>}
                            {openedActions.map((action) => <button key={action.title} type="button" className="button dark" onClick={() => { setOpenedId(null); setDialog(action); }}>{action.title}</button>)}
                        </>
                    ) : undefined}
                >
                    <DrawerLines
                        lines={[
                            { label: translate('office_staff', 'col_month'), value: businessMonth(String(opened.period_month)) },
                            { label: translate('office_staff', 'col_gross'), value: money(opened.gross) },
                            { label: translate('office_staff', 'col_cut'), value: money(opened.deduction) },
                            { label: translate('office_staff', 'col_net'), value: money(opened.net) },
                            { label: translate('office_staff', 'detail_payment_reference'), value: opened.payment_reference },
                            { label: translate('office_staff', 'detail_paid_at'), value: opened.paid_at ? dateTime(String(opened.paid_at)) : null },
                        ]}
                    />
                    <div className="emp-cat-title">{translate('office_staff', 'detail_calculation')}</div>
                    <div className="opr-json"><StoredDetails value={readStored(opened.snapshot)} /></div>
                </Modal>
            )}

            {dialog && <StaffActionDialog action={dialog} onClose={() => setDialog(null)} />}
        </OfficePage>
    );
}
