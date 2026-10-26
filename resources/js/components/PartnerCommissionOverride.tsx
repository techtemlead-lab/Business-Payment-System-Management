import { useForm, usePage, Link } from '@inertiajs/react';
import { Percent } from 'lucide-react';
import { type Row } from './UI';
import { actionAllowed } from './action-permissions';
import { useT } from '../shared/i18n/t';

const editableKeys = [
    { key: 'own_dealer_cashback', labelKey: 'pco_own' },
    { key: 'direct_referral', labelKey: 'pco_direct' },
    { key: 'd2d_multitier', labelKey: 'pco_d2d' },
    { key: 'area_dealer_pool', labelKey: 'pco_area' },
    { key: 'dealer_monthly_salary', labelKey: 'pco_salary' },
    { key: 'rank_royalty', labelKey: 'pco_rank' },
] as const;

export default function PartnerCommissionOverride({ account }: { account: Row }) {
    const translate = useT();
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
                        <Percent size={18} /> {translate('portal', 'pco_title')}
                    </h3>
                    <p className="muted" style={{ margin: '6px 0 0' }}>
                        {translate('portal', 'pco_hint', { name: String(account.name) })}
                    </p>
                </div>
                <Link className="text-link" href="/operations/policy-schedules">{translate('portal', 'pco_schedules')}</Link>
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
                        form.setError('reason', translate('portal', 'pco_need_one'));
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
                            {translate('portal', item.labelKey)}
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={form.data[item.key]}
                                onChange={(e) => form.setData(item.key, e.target.value)}
                                placeholder={translate('portal', 'pco_blank')}
                            />
                        </label>
                    ))}
                    <label>
                        {translate('portal', 'pco_effective')}
                        <input
                            type="datetime-local"
                            required
                            value={form.data.effective_at}
                            onChange={(e) => form.setData('effective_at', e.target.value)}
                        />
                    </label>
                    <label>
                        {translate('portal', 'pco_expires')}
                        <input
                            type="datetime-local"
                            value={form.data.expires_at}
                            onChange={(e) => form.setData('expires_at', e.target.value)}
                        />
                    </label>
                </div>
                <label>
                    {translate('portal', 'pco_reason')}
                    <textarea
                        required
                        rows={3}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder={translate('portal', 'pco_reason_ph')}
                    />
                </label>
                {Object.keys(form.errors).length > 0 && (
                    <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>
                )}
                <button className="button dark" disabled={form.processing}>
                    {form.processing ? translate('portal', 'pco_submitting') : translate('portal', 'pco_submit')}
                </button>
            </form>
        </section>
    );
}
