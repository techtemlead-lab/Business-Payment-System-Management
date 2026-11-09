import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormErrors, Modal, money, type Row } from '../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../MoneyTable';
import { useT } from '../../shared/i18n/t';
import { FUNDS_COMMAND_ROOT, useHeadName, type ExpenseHead } from './FundSpendForm';

export type HeadBudget = { head_id: number; name_en: string; name_bn: string; month: string; budget: string; used: string; left_amount: string; over: boolean };

/** The spending heads, on the shared table. */
export function HeadsTable({ table }: { table: MoneyTableData<ExpenseHead> }) {
    const translate = useT();
    const [editing, setEditing] = useState<ExpenseHead | 'new' | null>(null);
    const toggle = (head: ExpenseHead) => router.patch(`${FUNDS_COMMAND_ROOT}/heads/${head.id}`, { name_en: head.name_en, name_bn: head.name_bn, for_tours: head.for_tours, is_active: !head.is_active }, { preserveScroll: true });
    const columns: MoneyColumn<ExpenseHead>[] = [
        { key: 'name_bn', title: translate('funds', 'col_name_bn'), sortKey: 'name_bn', phone: 'title', render: (head) => <><b>{head.name_bn}</b>{head.for_tours && <small className="cell-sub">{translate('funds', 'for_tours')}</small>}</> },
        { key: 'name_en', title: translate('funds', 'col_name_en'), sortKey: 'name_en', phone: 'sub', render: (head) => head.name_en },
        { key: 'active', title: translate('funds', 'col_status'), sortKey: 'active', phone: 'amount', render: (head) => translate('funds', head.is_active ? 'heads_active' : 'heads_inactive') },
        {
            key: 'actions',
            title: '',
            phone: 'sub',
            render: (head) => (
                <div className="ops-row is-inline">
                    <button type="button" className="button small" onClick={(event) => { event.stopPropagation(); setEditing(head); }}>{translate('funds', 'heads_edit')}</button>
                    <button type="button" className="button small" onClick={(event) => { event.stopPropagation(); toggle(head); }}>{translate('funds', head.is_active ? 'head_deactivate' : 'head_activate')}</button>
                </div>
            ),
        },
    ];

    return (
        <section className="tsy-block">
            <MoneyTable<ExpenseHead>
                data={table}
                prop="heads_table"
                columns={columns}
                searchPlaceholder={translate('treasury', 'search')}
                empty={translate('treasury', 'heads_empty')}
                actions={<button type="button" className="button small dark" onClick={() => setEditing('new')}><Plus size={14} aria-hidden="true" /> {translate('funds', 'heads_add')}</button>}
            />
            {editing && <HeadForm head={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </section>
    );
}

/** Budgets by head and month, on the shared table. Going over a budget only warns. */
export function BudgetsTable({ table, heads, month }: { table: MoneyTableData<HeadBudget>; heads: ExpenseHead[]; month: string }) {
    const translate = useT();
    const headName = useHeadName();
    const [settingBudget, setSettingBudget] = useState(false);
    const columns: MoneyColumn<HeadBudget>[] = [
        { key: 'name', title: translate('funds', 'budget_head'), sortKey: 'name', phone: 'title', render: (row) => <><b>{headName(row)}</b>{row.over && <small className="cell-sub text-danger">{translate('funds', 'budget_status_over')}</small>}</> },
        { key: 'month', title: translate('funds', 'budget_month'), sortKey: 'month', phone: 'sub', render: (row) => String(row.month).slice(0, 7) },
        { key: 'budget', title: translate('funds', 'budget_col_budget'), sortKey: 'budget', money: true, total: 'budget', phone: 'sub', render: (row) => money(row.budget) },
        { key: 'used', title: translate('funds', 'budget_col_used'), sortKey: 'used', money: true, total: 'used', phone: 'sub', render: (row) => money(row.used) },
        { key: 'left_amount', title: translate('funds', 'budget_col_left'), sortKey: 'left', money: true, total: 'left', phone: 'amount', render: (row) => <b>{money(row.left_amount)}</b> },
    ];

    return (
        <section className="tsy-block">
            <MoneyTable<HeadBudget>
                data={table}
                prop="budgets_table"
                columns={columns}
                searchPlaceholder={translate('treasury', 'search')}
                empty={translate('funds', 'budget_none')}
                actions={<button type="button" className="button small dark" onClick={() => setSettingBudget(true)}>{translate('treasury', 'budget_set')}</button>}
            />
            {settingBudget && <BudgetForm heads={heads} month={month} onClose={() => setSettingBudget(false)} />}
        </section>
    );
}

function BudgetForm({ heads, month, onClose }: { heads: ExpenseHead[]; month: string; onClose: () => void }) {
    const translate = useT();
    const headName = useHeadName();
    const form = useForm<{ expense_head_id: string; amount: string; month: string }>({ expense_head_id: '', amount: '', month });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(`${FUNDS_COMMAND_ROOT}/budgets`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal title={translate('treasury', 'budget_set')} onClose={onClose}>
            <form className="action-form" onSubmit={submit}>
                <label>{translate('funds', 'budget_month')}<input type="month" required value={form.data.month} onChange={(event) => form.setData('month', event.target.value)} /></label>
                <label>{translate('funds', 'budget_head')}
                    <select required value={form.data.expense_head_id} onChange={(event) => form.setData('expense_head_id', event.target.value)}>
                        <option value="">—</option>
                        {heads.filter((head) => head.is_active !== false).map((head) => <option key={head.id} value={head.id}>{headName(head)}</option>)}
                    </select>
                </label>
                <label>{translate('funds', 'budget_amount')}
                    <input inputMode="decimal" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} />
                    <small className="muted">{translate('funds', 'budget_amount_hint')}</small>
                </label>
                <FormErrors errors={form.errors} />
                <button type="submit" className="button dark" disabled={form.processing}>{translate('funds', 'budget_save')}</button>
            </form>
        </Modal>
    );
}

function HeadForm({ head, onClose }: { head: ExpenseHead | null; onClose: () => void }) {
    const translate = useT();
    const form = useForm<Row>({ name_en: head?.name_en ?? '', name_bn: head?.name_bn ?? '', for_tours: head?.for_tours ?? false, ...(head ? { is_active: head.is_active } : {}) });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (head) {
            form.patch(`${FUNDS_COMMAND_ROOT}/heads/${head.id}`, options);
        } else {
            form.post(`${FUNDS_COMMAND_ROOT}/heads`, options);
        }
    };

    return (
        <Modal title={translate('funds', head ? 'heads_edit' : 'heads_add')} onClose={onClose}>
            <form className="action-form" onSubmit={submit}>
                <label>{translate('funds', 'col_name_bn')}<input required maxLength={120} value={form.data.name_bn} onChange={(event) => form.setData('name_bn', event.target.value)} /></label>
                <label>{translate('funds', 'col_name_en')}<input required maxLength={120} value={form.data.name_en} onChange={(event) => form.setData('name_en', event.target.value)} /></label>
                <label className="ops-row is-inline"><input type="checkbox" checked={Boolean(form.data.for_tours)} onChange={(event) => form.setData('for_tours', event.target.checked)} /> {translate('funds', 'for_tours')}</label>
                <p className="muted">{translate('funds', 'for_tours_hint')}</p>
                <FormErrors errors={form.errors} />
                <div className="ops-row is-inline">
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('funds', 'save_head')}</button>
                    <button type="button" className="button small" onClick={onClose}>{translate('funds', 'cancel')}</button>
                </div>
            </form>
        </Modal>
    );
}
