import { useForm, usePage } from '@inertiajs/react';
import OfficePage from '../OfficePage';
import { actionAllowed } from '../../action-permissions';
import { money, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import SettingsForm, { settingsBack, type SettingsField } from './SettingsForm';

const root = '/commands/operations';

/** Owner and finance money rules: one column, one thin line between the forms, each with its own reason and Save. */
export default function FinanceSettings({ withdrawalSetting, autoApproval, kycBeforeFirstWithdrawal, fullPaymentWhenOrdering }: Row) {
    const translate = useT();
    const { auth, financeActive, staffActions } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const withdrawalAction = `${root}/settings/withdrawal-minimum`;
    const canEditWithdrawal = actionAllowed(withdrawalAction, staffActions, 'post');
    const withdrawalFields: SettingsField[] = [
        { name: 'amount', label: translate('portal', 'ops_fld_min_withdrawal'), type: 'number', required: true },
        { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true },
    ];

    return (
        <OfficePage title={translate('portal', 'finance_settings')} back={settingsBack(translate)}>
            {!financeActive && capabilities.includes('finance') && <p className="set-line">{translate('portal', 'ops_preview_finance_notice')}</p>}
            <div className="set-sheet">
                {autoApproval && <AutoApprovalBlock setting={autoApproval} />}
                {fullPaymentWhenOrdering && <OwnerSwitchBlock
                    setting={fullPaymentWhenOrdering}
                    action={`${root}/settings/full-payment-when-ordering`}
                    title={translate('portal', 'fpo_title')}
                    switchLabel={translate('portal', 'fpo_switch')}
                    onLabel={translate('portal', 'fpo_on')}
                    offLabel={translate('portal', 'fpo_off')}
                    ownerOnly={translate('portal', 'fpo_owner_only')}
                    tour="full-payment-when-ordering"
                />}
                {kycBeforeFirstWithdrawal && <OwnerSwitchBlock
                    setting={kycBeforeFirstWithdrawal}
                    action={`${root}/settings/kyc-before-first-withdrawal`}
                    title={translate('portal', 'kyc_wd_title')}
                    switchLabel={translate('portal', 'kyc_wd_switch')}
                    onLabel={translate('portal', 'kyc_wd_on')}
                    offLabel={translate('portal', 'kyc_wd_off')}
                    ownerOnly={translate('portal', 'kyc_wd_owner_only')}
                />}
                {withdrawalSetting && (
                    <section className="set-block">
                        <h2>{translate('portal', 'ops_withdrawal_policy')}</h2>
                        {canEditWithdrawal ? (
                            <SettingsForm
                                action={withdrawalAction}
                                fields={withdrawalFields}
                                defaults={{ amount: withdrawalSetting.amount, version: withdrawalSetting.version }}
                                submitLabel={translate('portal', 'ops_save_withdrawal_minimum')}
                            />
                        ) : (
                            <p className="set-line">{translate('portal', 'ops_withdrawal_policy_body', { amount: String(money(withdrawalSetting.amount)) })}</p>
                        )}
                    </section>
                )}
            </div>
        </OfficePage>
    );
}

/** Automatic payouts: one main switch, and each payout type can stay automatic or wait for a person. */
function AutoApprovalBlock({ setting }: { setting: Row }) {
    const translate = useT();
    const { portal, staffActions } = usePage<any>().props;
    const action = `${root}/settings/auto-approval`;
    const items = ['salary', 'royalty', 'rank_incentive'];
    const form = useForm<Row>({ enabled: Boolean(setting.enabled), items: { ...(setting.items || {}) }, version: setting.version ?? 0, reason: '' });
    const canEdit = !((portal === 'manage' || portal === 'sadmin') && !actionAllowed(action, staffActions, 'post'));
    const setItem = (item: string, value: boolean) => form.setData('items', { ...form.data.items, [item]: value });

    return (
        <section className="set-block auto-approval-panel">
            <h2>{translate('portal', 'aa_title')}</h2>
            <form onSubmit={(event) => { event.preventDefault(); form.post(action, { preserveScroll: true, onSuccess: () => form.setData('reason', '') }); }}>
                <label className="aa-switch">
                    <input type="checkbox" role="switch" checked={form.data.enabled} disabled={!canEdit} onChange={(event) => form.setData('enabled', event.target.checked)} />
                    <span><b>{translate('portal', 'aa_master')}</b><small>{translate('portal', form.data.enabled ? 'aa_master_on' : 'aa_master_off')}</small></span>
                </label>
                <fieldset className="aa-items" disabled={form.data.enabled || !canEdit}>
                    <legend>{translate('portal', 'aa_items_legend')}</legend>
                    {items.map((item) => (
                        <label key={item} className="aa-item">
                            <input type="checkbox" checked={form.data.enabled || Boolean(form.data.items?.[item])} onChange={(event) => setItem(item, event.target.checked)} />
                            <span>{translate('portal', `aa_item_${item}`)}</span>
                            <small>{form.data.enabled || form.data.items?.[item] ? translate('portal', 'aa_state_auto') : translate('portal', 'aa_state_manual')}</small>
                        </label>
                    ))}
                    <p className="aa-note">{translate('portal', 'aa_car_bike_note')}</p>
                </fieldset>
                {canEdit && <ReasonAndSave value={form.data.reason} errors={form.errors} processing={form.processing} onChange={(reason) => form.setData('reason', reason)} />}
            </form>
        </section>
    );
}

/** A yes-or-no rule only an owner may change. Everyone else sees it switched off for editing. */
function OwnerSwitchBlock({ setting, action, title, switchLabel, onLabel, offLabel, ownerOnly, tour }: {
    setting: Row;
    action: string;
    title: string;
    switchLabel: string;
    onLabel: string;
    offLabel: string;
    ownerOnly: string;
    tour?: string;
}) {
    const form = useForm<Row>({ enabled: Boolean(setting.enabled), version: setting.version ?? 0, reason: '' });
    const canEdit = Boolean(setting.can_edit);

    return (
        <section className="set-block auto-approval-panel" data-tour={tour}>
            <h2>{title}</h2>
            <form onSubmit={(event) => { event.preventDefault(); form.post(action, { preserveScroll: true, onSuccess: () => form.setData('reason', '') }); }}>
                <label className="aa-switch">
                    <input type="checkbox" role="switch" checked={form.data.enabled} disabled={!canEdit} onChange={(event) => form.setData('enabled', event.target.checked)} />
                    <span><b>{switchLabel}</b><small>{form.data.enabled ? onLabel : offLabel}</small></span>
                </label>
                {!canEdit && <p className="aa-note">{ownerOnly}</p>}
                {canEdit && <ReasonAndSave value={form.data.reason} errors={form.errors} processing={form.processing} onChange={(reason) => form.setData('reason', reason)} />}
            </form>
        </section>
    );
}

function ReasonAndSave({ value, errors, processing, onChange }: { value: string; errors: Record<string, unknown>; processing: boolean; onChange: (reason: string) => void }) {
    const translate = useT();

    return (
        <>
            <label className="aa-reason">{translate('portal', 'change_reason_label')}<textarea rows={2} required value={value} onChange={(event) => onChange(event.target.value)} /></label>
            {Object.values(errors).map((error) => <span key={String(error)} className="field-error" role="alert">{String(error)}</span>)}
            <button className="button dark set-save" disabled={processing}>{processing ? translate('portal', 'saving') : translate('portal', 'save_changes')}</button>
        </>
    );
}
