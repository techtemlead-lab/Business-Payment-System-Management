import { useForm, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { type Row } from './UI';
import { actionAllowed } from './action-permissions';
import { useT } from '../shared/i18n/t';

export default function PartnerBenefitControls({ account }: { account: Row }) {
    const translate = useT();
    const { staffActions } = usePage<any>().props;
    const canEdit = actionAllowed(`/commands/accounts/${account.id}/benefit-controls`, staffActions, 'post');
    const existing = account.profile?.benefit_controls ?? {};
    const form = useForm({
        unconditional_salary: !!existing.unconditional_salary,
        unconditional_rank_royalty: !!existing.unconditional_rank_royalty,
        forced_royalty_rank: existing.forced_royalty_rank || 'mo',
        unconditional_rank_incentive: !!existing.unconditional_rank_incentive,
        note: existing.note || '',
        reason: '',
    });

    if (!['dealer', 'sub_dealer'].includes(account.role) || !canEdit) {
        return null;
    }

    return (
        <section className="panel" style={{ marginTop: 16, borderLeft: '4px solid #b45309' }}>
            <div className="panel-heading">
                <div>
                    <h3 style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                        <ShieldCheck size={18} /> {translate('portal', 'pbc_title')}
                    </h3>
                    <p className="muted" style={{ margin: '6px 0 0' }}>
                        {translate('portal', 'pbc_body', { name: String(account.name ?? '') })}
                    </p>
                </div>
            </div>
            <form
                className="action-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        unconditional_salary: data.unconditional_salary ? 1 : 0,
                        unconditional_rank_royalty: data.unconditional_rank_royalty ? 1 : 0,
                        unconditional_rank_incentive: data.unconditional_rank_incentive ? 1 : 0,
                    }));
                    form.post(`/commands/accounts/${account.id}/benefit-controls`, { preserveScroll: true });
                }}
            >
                <label className="registry-checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.unconditional_salary}
                        onChange={(e) => form.setData('unconditional_salary', e.target.checked)}
                    />
                    {translate('portal', 'pbc_waive_salary')}
                </label>
                <label className="registry-checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.unconditional_rank_royalty}
                        onChange={(e) => form.setData('unconditional_rank_royalty', e.target.checked)}
                    />
                    {translate('portal', 'pbc_grant_royalty')}
                </label>
                {form.data.unconditional_rank_royalty && (
                    <label>
                        {translate('portal', 'pbc_royalty_rank')}
                        <select
                            value={form.data.forced_royalty_rank}
                            onChange={(e) => form.setData('forced_royalty_rank', e.target.value)}
                        >
                            <option value="mo">MO</option>
                            <option value="am">AM</option>
                            <option value="sm">SM</option>
                            <option value="me">ME</option>
                        </select>
                    </label>
                )}
                <label className="registry-checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.unconditional_rank_incentive}
                        onChange={(e) => form.setData('unconditional_rank_incentive', e.target.checked)}
                    />
                    {translate('portal', 'pbc_grant_incentive')}
                </label>
                <label>
                    {translate('portal', 'pbc_note')}
                    <input
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        placeholder={translate('portal', 'pbc_note_ph')}
                    />
                </label>
                <label>
                    {translate('portal', 'pbc_reason')}
                    <textarea
                        required
                        rows={3}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder={translate('portal', 'pbc_reason_ph')}
                    />
                </label>
                {Object.keys(form.errors).length > 0 && (
                    <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>
                )}
                <button className="button dark" disabled={form.processing}>
                    {form.processing ? translate('portal', 'pbc_saving') : translate('portal', 'pbc_save')}
                </button>
            </form>
        </section>
    );
}
