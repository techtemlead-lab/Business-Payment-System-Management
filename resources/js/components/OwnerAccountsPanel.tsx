import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Badge, Empty, FormErrors, Modal, label } from './UI';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';

export type OwnerAccount = { id: number; name: string; role: string; status: string };

/** The Super Admin makes a new MD or Chairman account here. The first password is shown once afterwards. */
function AddOwnerDialog({ onClose }: { onClose: () => void }) {
    const translate = useT();
    const addReason = useReasonField('staff.account_create');
    const form = useForm({ role: 'md', name: '', email: '', phone: '', status: 'active', confirm_leadership: false, reason: '' });

    return (
        <Modal title={translate('joining', 'owner_add_title')} onClose={onClose}>
            <form className="action-form" onSubmit={(event) => { event.preventDefault(); form.post('/commands/accounts', { preserveScroll: true, onSuccess: onClose }); }}>
                <label>{translate('joining', 'owner_role')}
                    <select value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                        <option value="md">{translate('portal', 'staff_role_md')}</option>
                        <option value="chairman">{translate('portal', 'staff_role_chairman')}</option>
                    </select>
                </label>
                <label>{translate('office_staff', 'field_full_name')}<input required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />{form.errors.name && <span className="field-error">{form.errors.name}</span>}</label>
                <label>{translate('portal', 'emp_fld_email')}<input required type="email" value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />{form.errors.email && <span className="field-error">{form.errors.email}</span>}</label>
                <label>{translate('portal', 'emp_fld_phone_login')}<input required value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />{form.errors.phone && <span className="field-error">{form.errors.phone}</span>}</label>
                <p className="field-hint">{translate('joining', 'staff_first_password_hint')}</p>
                {addReason.shown && (
                    <label>{addReason.label(translate('office_staff', 'field_reason_add'))}
                        <textarea required={addReason.required} rows={2} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    </label>
                )}
                <label className="import-confirmation">
                    <input type="checkbox" checked={form.data.confirm_leadership} onChange={(event) => form.setData('confirm_leadership', event.target.checked)} /> {translate('joining', 'owner_confirm')}
                </label>
                <FormErrors errors={form.errors} besideFields={['name', 'email', 'phone', 'reason']} />
                <div className="ops-row is-end is-loose">
                    <button type="button" className="button" onClick={onClose}>{translate('portal', 'emp_cancel')}</button>
                    <button type="submit" className="button dark" disabled={form.processing || !form.data.confirm_leadership}>{translate('portal', 'emp_create_submit')}</button>
                </div>
            </form>
        </Modal>
    );
}

/** On the employees page, for the Super Admin only: add an MD or Chairman, turn one off and on, or restore default rights. */
export default function OwnerAccountsPanel({ accounts = [] }: { accounts?: OwnerAccount[] }) {
    const translate = useT();
    const [opened, setOpened] = useState<OwnerAccount | null>(null);
    const [adding, setAdding] = useState(false);
    const form = useForm({ status: 'suspended', reason: '' });
    const statusReason = useReasonField('staff.account_edit');

    const open = (account: OwnerAccount) => {
        form.clearErrors();
        form.setData({ status: account.status === 'active' ? 'suspended' : 'active', reason: '' });
        setOpened(account);
    };

    const save = (event: React.FormEvent) => {
        event.preventDefault();
        if (opened === null) {
            return;
        }
        form.patch(`/commands/accounts/${opened.id}`, {
            preserveScroll: true,
            onSuccess: () => setOpened(null),
        });
    };

    const turningOff = opened?.status === 'active';
    const buttonText = translate('portal', turningOff ? 'owner_status_turn_off' : 'owner_status_turn_on');

    const addButton = <p><button type="button" className="button dark" onClick={() => setAdding(true)}>{translate('joining', 'owner_add_button')}</button></p>;
    if (accounts.length === 0) {
        return <>{addButton}<Empty line title={translate('portal', 'owner_status_empty')} />{adding && <AddOwnerDialog onClose={() => setAdding(false)} />}</>;
    }

    return (
        <>
            {addButton}
            {adding && <AddOwnerDialog onClose={() => setAdding(false)} />}
            <ul className="rx-list">
                {accounts.map((account) => (
                    <li key={account.id} className="rx-card">
                        <div className="rx-card-head">
                            <div>
                                <b>{account.name}</b>
                                <small className="cell-sub">{label(account.role, translate)}</small>
                            </div>
                            <span className="rx-actions">
                                <Badge status={account.status} />
                                <button type="button" className="button small" title={translate('joining', 'owner_reset_hint')} onClick={() => router.post('/commands/accounts/' + account.id + '/leadership-reset', {}, { preserveScroll: true })}>{translate('joining', 'owner_reset_rights')}</button>
                                <button type="button" className="button small" onClick={() => open(account)}>
                                    {translate('portal', account.status === 'active' ? 'owner_status_turn_off' : 'owner_status_turn_on')}
                                </button>
                            </span>
                        </div>
                    </li>
                ))}
            </ul>

            {opened && (
                <Modal title={translate('office_staff', turningOff ? 'owner_turn_off_title' : 'owner_turn_on_title', { name: opened.name })} onClose={() => setOpened(null)}>
                    <form className="action-form" onSubmit={save}>
                        <p className="muted">{translate('portal', turningOff ? 'owner_status_off_note' : 'owner_status_on_note')}</p>
                        {statusReason.shown && (
                            <label>{statusReason.label(translate('portal', 'owner_status_reason'))}
                                <textarea required={statusReason.required} maxLength={1000} rows={2} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                            </label>
                        )}
                        <FormErrors errors={form.errors} />
                        <div className="ops-row is-end is-loose">
                            <button type="button" className="button" disabled={form.processing} onClick={() => setOpened(null)}>{translate('portal', 'owner_status_cancel')}</button>
                            <button type="submit" className="button dark" disabled={form.processing}>{buttonText}</button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}
