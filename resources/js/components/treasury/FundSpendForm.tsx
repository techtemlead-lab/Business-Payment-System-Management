import { useForm, usePage } from '@inertiajs/react';
import { DateInput, FormErrors, Modal, money, type Row } from '../UI';
import { useT } from '../../shared/i18n/t';
import { useReasonField } from '../../shared/reasonFields';
import { randomUuid } from '../../shared/randomUuid';
import { businessTodayIso } from '../../business-date';
import type { FundCardData } from './FundCard';

export type ExpenseHead = { id: number; name_en: string; name_bn: string; is_active?: boolean; for_tours: boolean };
export type FundSpendOptions = { heads: ExpenseHead[]; methods: string[]; available: string };

export const FUNDS_COMMAND_ROOT = '/commands/operations/company-funds';

/** A head's name in the page's language. */
export function useHeadName() {
    const translate = useT();
    const { locale } = usePage<any>().props;

    return (head: { name_en?: string | null; name_bn?: string | null } | Row | null | undefined): string => (locale === 'bn' ? head?.name_bn : head?.name_en) || translate('funds', 'no_head');
}

/** Take money out of one fund. Sends the same form as before, to the same address. */
export default function FundSpendForm({ card, spend, onClose }: { card: FundCardData; spend: FundSpendOptions; onClose: () => void }) {
    const translate = useT();
    const headName = useHeadName();
    const spendNote = useReasonField('money.fund_spend_note');
    const form = useForm<Row>({ fund: card.key, expense_head_id: '', amount: '', spent_on: businessTodayIso(), payee: '', payment_method: '', reference: '', note: '', receipt: null, request_key: randomUuid() });
    const usableHeads = spend.heads.filter((head) => head.is_active !== false && (card.key !== 'tour' || head.for_tours));
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(`${FUNDS_COMMAND_ROOT}/spends`, { preserveScroll: true, forceFormData: true, onSuccess: onClose });
    };

    return (
        <Modal title={translate('funds', 'form_title', { fund: card.name })} onClose={onClose}>
            <form className="action-form" onSubmit={submit}>
                <p className="muted">{translate('funds', 'form_available', { amount: money(spend.available) })}</p>
                {card.key === 'tour' && <p className="muted">{translate('funds', 'tour_rule')}</p>}
                <label>{translate('funds', 'fld_head')}
                    <select required value={form.data.expense_head_id} onChange={(event) => form.setData('expense_head_id', event.target.value)}>
                        <option value="">{translate('funds', 'choose_head')}</option>
                        {usableHeads.map((head) => <option key={head.id} value={head.id}>{headName(head)}</option>)}
                    </select>
                </label>
                <label>{translate('funds', 'fld_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} /></label>
                <label>{translate('funds', 'fld_date')}<DateInput required value={form.data.spent_on} onChange={(event) => form.setData('spent_on', event.target.value)} /></label>
                <label>{translate('funds', 'fld_payee')}<input required maxLength={150} value={form.data.payee} onChange={(event) => form.setData('payee', event.target.value)} /></label>
                <label>{translate('funds', 'fld_method')}
                    <select required value={form.data.payment_method} onChange={(event) => form.setData('payment_method', event.target.value)}>
                        <option value="">{translate('funds', 'choose_method')}</option>
                        {spend.methods.map((method) => <option key={method} value={method}>{translate('funds', `method_${method}`)}</option>)}
                    </select>
                </label>
                <label>{translate('funds', 'fld_reference')}<input maxLength={100} required={form.data.payment_method !== 'cash'} value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} /></label>
                <label>{translate('funds', 'fld_receipt')}<input type="file" accept=".pdf,.jpg,.jpeg,.png" onChange={(event) => form.setData('receipt', event.target.files?.[0] ?? null)} /></label>
                {spendNote.shown && <label>{spendNote.label(translate('funds', 'col_note'))}<textarea required={spendNote.required} maxLength={1000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} /></label>}
                <FormErrors errors={form.errors} />
                <div className="ops-row is-inline">
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('funds', 'save_spend')}</button>
                    <button type="button" className="button small" onClick={onClose}>{translate('funds', 'cancel')}</button>
                </div>
            </form>
        </Modal>
    );
}
