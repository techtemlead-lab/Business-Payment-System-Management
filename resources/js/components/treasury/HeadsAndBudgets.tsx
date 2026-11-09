import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormErrors, Modal, Table, money, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { FUNDS_COMMAND_ROOT, useHeadName, type ExpenseHead } from './FundSpendForm';

export type HeadBudget = { head_id: number; name_en: string; name_bn: string; budget: string; used: string; left: string; over: boolean };

/** The list of spending heads and this month's budget for each. Going over a budget only warns. */
export default function HeadsAndBudgets({ heads, budgets, month }: { heads: ExpenseHead[]; budgets: HeadBudget[]; month: string }) {
    const translate = useT();
    const headName = useHeadName();
    const [editing, setEditing] = useState<ExpenseHead | 'new' | null>(null);
    const [settingBudget, setSettingBudget] = useState(false);
    const toggle = (head: ExpenseHead) => router.patch(`${FUNDS_COMMAND_ROOT}/heads/${head.id}`, { name_en: head.name_en, name_bn: head.name_bn, for_tours: head.for_tours, is_active: !head.is_active }, { preserveScroll: true });
    const changeMonth = (value: string) => {
        if (value) {
            router.get('/treasury/work', { tab: 'heads', budget_month: value }, { preserveState: true, preserveScroll: true });
        }
    };

    return (
        <>
            <section className="tsy-block">
                <div className="tsy-block-head">
                    <h2>{translate('treasury', 'heads_title')}</h2>
                    <button type="button" className="button dark" onClick={() => setEditing('new')}><Plus size={16} aria-hidden="true" /> {translate('funds', 'heads_add')}</button>
                </div>
                <div className="panel table-panel">
                    <Table
                        tools={false}
                        rows={heads}
                        empty={translate('treasury', 'heads_empty')}
                        emptyBody=""
                        phoneColumns={['name_bn', 'action']}
                        columns={[
                            { key: 'name_bn', title: translate('funds', 'col_name_bn'), render: (head: ExpenseHead) => <><b>{head.name_bn}</b>{head.for_tours && <small className="cell-sub">{translate('funds', 'for_tours')}</small>}</> },
                            { key: 'name_en', title: translate('funds', 'col_name_en') },
                            { key: 'status', title: translate('funds', 'col_status'), render: (head: ExpenseHead) => translate('funds', head.is_active ? 'heads_active' : 'heads_inactive') },
                            {
                                key: 'action',
                                title: '',
                                render: (head: ExpenseHead) => (
                                    <div className="ops-row is-inline">
                                        <button type="button" className="button small" onClick={() => setEditing(head)}>{translate('funds', 'heads_edit')}</button>
                                        <button type="button" className="button small" onClick={() => toggle(head)}>{translate('funds', head.is_active ? 'head_deactivate' : 'head_activate')}</button>
                                    </div>
                                ),
                            },
                        ]}
                    />
                </div>
            </section>
            <section className="tsy-block">
                <div className="tsy-block-head">
                    <h2>{translate('funds', 'budget_title')}</h2>
                    <div className="tsy-block-tools">
                        <label className="tsy-month">
                            <span className="sr-only">{translate('funds', 'budget_month')}</span>
                            <input type="month" value={month} aria-label={translate('funds', 'budget_month')} onChange={(event) => changeMonth(event.target.value)} />
                        </label>
                        <button type="button" className="button small" onClick={() => setSettingBudget(true)}>{translate('funds', 'budget_save')}</button>
                    </div>
                </div>
                <div className="panel table-panel">
                    <Table
                        tools={false}
                        rows={budgets}
                        empty={translate('funds', 'budget_none')}
                        emptyBody=""
                        phoneColumns={['head', 'left']}
                        columns={[
                            { key: 'head', title: translate('funds', 'budget_head'), render: (row: HeadBudget) => <><b>{headName(row)}</b>{row.over && <small className="cell-sub text-danger">{translate('funds', 'budget_status_over')}</small>}</> },
                            { key: 'budget', title: translate('funds', 'budget_col_budget'), money: true, render: (row: HeadBudget) => money(row.budget) },
                            { key: 'used', title: translate('funds', 'budget_col_used'), money: true, render: (row: HeadBudget) => money(row.used) },
                            { key: 'left', title: translate('funds', 'budget_col_left'), money: true, render: (row: HeadBudget) => <b>{money(row.left)}</b> },
                        ]}
                    />
                </div>
            </section>
            {editing && <HeadForm head={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
            {settingBudget && <BudgetForm heads={heads} month={month} onClose={() => setSettingBudget(false)} />}
        </>
    );
}

function BudgetForm({ heads, month, onClose }: { heads: ExpenseHead[]; month: string; onClose: () => void }) {
    const translate = useT();
    const headName = useHeadName();
    const form = useForm<{ expense_head_id: string; amount: string }>({ expense_head_id: '', amount: '' });
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, month }));
        form.post(`${FUNDS_COMMAND_ROOT}/budgets`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal title={translate('funds', 'budget_title')} onClose={onClose}>
            <form className="action-form" onSubmit={submit}>
                <label>{translate('funds', 'budget_head')}
                    <select required value={form.data.expense_head_id} onChange={(event) => form.setData('expense_head_id', event.target.value)}>
                        <option value="">—</option>
                        {heads.filter((head) => head.is_active).map((head) => <option key={head.id} value={head.id}>{headName(head)}</option>)}
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
