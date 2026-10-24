import { useForm, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { type Row } from './UI';
import { actionAllowed } from './action-permissions';

export default function PartnerBenefitControls({ account }: { account: Row }) {
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
                        <ShieldCheck size={18} /> Unconditional benefit access
                    </h3>
                    <p className="muted" style={{ margin: '6px 0 0' }}>
                        Admin-only. Grant salary, rank royalty or rank incentive to {account.name} without the usual volume / rank targets. Applies immediately — no second reviewer.
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
                    Waive monthly salary volume target
                </label>
                <label className="registry-checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.unconditional_rank_royalty}
                        onChange={(e) => form.setData('unconditional_rank_royalty', e.target.checked)}
                    />
                    Grant rank royalty without rank / maintenance targets
                </label>
                {form.data.unconditional_rank_royalty && (
                    <label>
                        Royalty rank bucket
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
                    Allow rank incentive claim without maintenance targets
                </label>
                <label>
                    Internal note
                    <input
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        placeholder="Optional admin note"
                    />
                </label>
                <label>
                    Reason *
                    <textarea
                        required
                        rows={3}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="Why this partner receives unconditional benefits"
                    />
                </label>
                {Object.keys(form.errors).length > 0 && (
                    <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>
                )}
                <button className="button dark" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save benefit controls'}
                </button>
            </form>
        </section>
    );
}
