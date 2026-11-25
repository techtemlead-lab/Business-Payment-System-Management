import { useForm, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { type Row } from './UI';
import { useT } from '../shared/i18n/t';
import { rankLabel } from '../shared/rankLabel';
import { useReasonField } from '../shared/reasonFields';

const DEALER_CUSTOM_KEYS = [
    'monthly_target',
    'mo_target',
    'am_count',
    'sm_count',
    'me_count',
    'qualification_depth',
    'bike_target',
    'bike_leg_cap',
    'car_target',
    'car_leg_cap',
    'cooling_off_days',
] as const;

const SUB_DEALER_CUSTOM_KEYS = [
    'sub_dealer_royalty_target',
    'tier1_volume',
    'tier1_pct',
    'tier2_volume',
    'tier2_pct',
    'tier3_volume',
    'tier3_pct',
    'mo_target',
    'am_count',
    'sm_count',
    'me_count',
    'qualification_depth',
    'bike_target',
    'bike_leg_cap',
    'car_target',
    'car_leg_cap',
    'cooling_off_days',
] as const;

export default function PartnerBenefitControls({ account }: { account: Row }) {
    const translate = useT();
    const benefitReason = useReasonField('partner.benefit_reason');
    const benefitNote = useReasonField('partner.benefit_note');
    const { auth } = usePage<any>().props;
    // Only owners can grant no-condition benefits (server: CommandController::benefitControls).
    const canEdit = ['super_admin', 'md', 'chairman'].includes(auth?.user?.role);
    const existing = account.profile?.benefit_controls ?? {};
    const existingCustom: Record<string, string> = Object.fromEntries(
        Object.entries(existing.custom_conditions ?? {}).map(([key, value]) => [key, String(value ?? '')]),
    );
    const customKeys = account.role === 'sub_dealer' ? SUB_DEALER_CUSTOM_KEYS : DEALER_CUSTOM_KEYS;
    const form = useForm({
        unconditional_salary: !!existing.unconditional_salary,
        unconditional_rank_royalty: !!existing.unconditional_rank_royalty,
        forced_royalty_rank: existing.forced_royalty_rank || 'mo',
        unconditional_rank_incentive: !!existing.unconditional_rank_incentive,
        custom_conditions: existingCustom,
        note: existing.note || '',
        reason: '',
    });

    if (!['dealer', 'sub_dealer'].includes(account.role) || !canEdit) {
        return null;
    }

    return (
        <section className="panel pbc-panel">
            <div className="panel-heading">
                <div>
                    <h3 className="ops-row is-inline">
                        <ShieldCheck size={18} /> {translate('portal', 'pbc_title')}
                    </h3>
                    <p className="muted pbc-hint">
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
                            <option value="me">{rankLabel('me')}</option>
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
                <details className="pbc-custom" open={Object.keys(existingCustom).length > 0}>
                    <summary>{translate('portal', 'pbc_custom_title')}</summary>
                    <p className="muted pbc-hint">{translate('portal', 'pbc_custom_hint')}</p>
                    <div className="pbc-custom-grid">
                        {customKeys.map((key) => (
                            <label key={key}>
                                {translate('special_rates', `custom_fld_${key}`)}
                                <input
                                    type="text"
                                    inputMode="decimal"
                                    value={form.data.custom_conditions[key] ?? ''}
                                    placeholder={translate('portal', 'pbc_custom_default_ph')}
                                    onChange={(e) => form.setData('custom_conditions', { ...form.data.custom_conditions, [key]: e.target.value })}
                                />
                            </label>
                        ))}
                    </div>
                </details>
                {benefitNote.shown && (
                    <label>
                        {benefitNote.label(translate('portal', 'pbc_note'))}
                        <input
                            required={benefitNote.required}
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                            placeholder={benefitNote.required ? undefined : translate('portal', 'pbc_note_ph')}
                        />
                    </label>
                )}
                {benefitReason.shown && (
                    <label>
                        {benefitReason.required ? translate('portal', 'pbc_reason') : benefitReason.label(translate('reason_fields', 'box_reason'))}
                        <textarea
                            required={benefitReason.required}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder={translate('portal', 'pbc_reason_ph')}
                        />
                    </label>
                )}
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
