import { useForm, usePage, Link } from '@inertiajs/react';
import { Percent } from 'lucide-react';
import { type Row } from './UI';
import { actionAllowed } from './action-permissions';

const editableKeys = [
    { key: 'own_dealer_cashback', label: 'Own purchase cashback %' },
    { key: 'direct_referral', label: 'Direct referral %' },
    { key: 'd2d_multitier', label: 'D2D multitier pool %' },
    { key: 'area_dealer_pool', label: 'Area dealer pool %' },
    { key: 'dealer_monthly_salary', label: 'Dealer monthly salary pool %' },
    { key: 'rank_royalty', label: 'Rank royalty pool %' },
] as const;

export default function PartnerCommissionOverride({ account }: { account: Row }) {
    const { staffActions } = usePage<any>().props;
    const canPropose = actionAllowed('/commands/operations/policy-schedules', staffActions, 'post');
    const form = useForm({
        scope: 'buyer',
        user_id: String(account.id),
        effective_at: '',
        expires_at: '',
        reason: '',
        own_dealer_cashback: '',
        direct_referral: '',
        d2d_multitier: '',
        area_dealer_pool: '',
        dealer_monthly_salary: '',
        rank_royalty: '',
    });

    if (!['dealer', 'sub_dealer'].includes(account.role) || !canPropose) {
        return null;
    }

    return (
        <section className="panel" style={{ marginTop: 16, borderLeft: '4px solid #0f766e' }}>
            <div className="panel-heading">
                <div>
                    <h3 style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                        <Percent size={18} /> Custom commission plan
                    </h3>
                    <p className="muted" style={{ margin: '6px 0 0' }}>
                        Global rates are edited in Commission Studio. Use this to propose a buyer-specific plan for {account.name}. A second finance reviewer must approve the schedule before it applies.
                    </p>
                </div>
                <Link className="text-link" href="/operations/policy-schedules">Open schedules ↗</Link>
            </div>
            <form
                className="action-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    const rules: Record<string, { percentage: string }> = {};
                    for (const item of editableKeys) {
                        const value = String(form.data[item.key] ?? '').trim();
                        if (value !== '') {
                            rules[item.key] = { percentage: value };
                        }
                    }
                    if (Object.keys(rules).length === 0) {
                        form.setError('reason', 'Enter at least one customized percentage.');
                        return;
                    }
                    form.transform(() => ({
                        scope: 'buyer',
                        user_id: account.id,
                        effective_at: form.data.effective_at,
                        expires_at: form.data.expires_at || null,
                        reason: form.data.reason,
                        rules,
                    }));
                    form.post('/commands/operations/policy-schedules', { preserveScroll: true });
                }}
            >
                <div className="form-grid">
                    {editableKeys.map((item) => (
                        <label key={item.key}>
                            {item.label}
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={form.data[item.key]}
                                onChange={(e) => form.setData(item.key, e.target.value)}
                                placeholder="Leave blank to keep global"
                            />
                        </label>
                    ))}
                    <label>
                        Effective from (Dhaka) *
                        <input
                            type="datetime-local"
                            required
                            value={form.data.effective_at}
                            onChange={(e) => form.setData('effective_at', e.target.value)}
                        />
                    </label>
                    <label>
                        Expires (optional)
                        <input
                            type="datetime-local"
                            value={form.data.expires_at}
                            onChange={(e) => form.setData('expires_at', e.target.value)}
                        />
                    </label>
                </div>
                <label>
                    Proposal reason *
                    <textarea
                        required
                        rows={3}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="Why this partner needs a different commission distribution"
                    />
                </label>
                {Object.keys(form.errors).length > 0 && (
                    <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>
                )}
                <button className="button dark" disabled={form.processing}>
                    {form.processing ? 'Submitting…' : 'Propose partner commission plan'}
                </button>
            </form>
        </section>
    );
}
