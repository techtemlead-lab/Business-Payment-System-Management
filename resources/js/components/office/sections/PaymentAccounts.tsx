import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import OfficePage from '../OfficePage';
import { actionAllowed } from '../../action-permissions';
import { Badge, Modal, Table, channelLabel, dateTime, type Row } from '../../UI';
import { useT } from '../../../shared/i18n/t';
import SettingsForm, { settingsBack, type SettingsField } from './SettingsForm';

const root = '/commands/operations/payment-accounts';

/** The company's bKash, Nagad, Rocket and bank numbers. A row opens its edit form; each change makes a new version. */
export default function PaymentAccounts({ paymentAccounts = [], accountHistory = [] }: { paymentAccounts?: Row[]; accountHistory?: Row[] }) {
    const translate = useT();
    const { auth, financeActive, staffActions } = usePage<any>().props;
    const capabilities: string[] = auth.capabilities;
    const [editing, setEditing] = useState<Row | 'new' | null>(null);
    const canAdd = actionAllowed(root, staffActions, 'post');
    const canEdit = (row: Row) => actionAllowed(`${root}/${row.id}`, staffActions, 'patch');
    const yesNo = [{ value: '1', label: translate('portal', 'ops_fld_yes') }, { value: '0', label: translate('portal', 'ops_fld_no') }];
    const fields: SettingsField[] = [
        { name: 'label', label: translate('portal', 'ops_fld_account_label'), required: true },
        { name: 'channel', label: translate('portal', 'ops_fld_gateway'), required: true, options: [
            { value: 'Bank', label: translate('portal', 'pay_channel_bank') },
            { value: 'bKash', label: translate('portal', 'pay_channel_bkash') },
            { value: 'Nagad', label: translate('portal', 'pay_channel_nagad') },
            { value: 'Rocket', label: translate('portal', 'pay_channel_rocket') },
        ] },
        { name: 'holder', label: translate('portal', 'ops_fld_holder'), required: true },
        { name: 'account_number', label: translate('portal', 'ops_fld_account_number'), required: true },
        { name: 'details', label: translate('portal', 'ops_fld_payment_instructions'), type: 'textarea' },
        { name: 'collects_payments', label: translate('portal', 'ops_fld_accept_collections'), required: true, options: yesNo },
        { name: 'pays_out', label: translate('portal', 'ops_fld_allow_payouts'), required: true, options: yesNo },
        { name: 'is_active', label: translate('portal', 'ops_fld_account_active'), required: true, options: yesNo },
        { name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true, place: 'payment.account_save' },
    ];
    const account = editing !== 'new' ? editing : null;

    return (
        <OfficePage
            title={translate('portal', 'payment_accounts')}
            back={settingsBack(translate)}
            action={canAdd && <button type="button" className="button dark" onClick={() => setEditing('new')}>{translate('portal', 'ops_add_payment_account')}</button>}
        >
            {!financeActive && capabilities.includes('finance') && <p className="set-line">{translate('portal', 'ops_preview_finance_notice')}</p>}
            <section className="panel table-panel">
                <Table
                    rows={paymentAccounts}
                    onRowClick={(row: Row) => { if (canEdit(row)) { setEditing(row); } }}
                    columns={[
                        { key: 'label', title: translate('portal', 'ops_account'), render: (row: Row) => <>{canEdit(row) ? <button type="button" className="text-link" onClick={(event) => { event.stopPropagation(); setEditing(row); }}><b>{row.label}</b></button> : <b>{row.label}</b>}<small className="cell-sub">{translate('portal', 'ops_version')} {row.version}</small></> },
                        { key: 'channel', title: translate('portal', 'ops_method'), render: (row: Row) => channelLabel(row.channel, translate) },
                        { key: 'holder', title: translate('portal', 'ops_holder') },
                        { key: 'account_number', title: translate('portal', 'ops_number') },
                        { key: 'is_active', title: translate('portal', 'ops_status'), render: (row: Row) => <Badge status={row.is_active ? 'active' : 'inactive'} /> },
                        { key: 'actions', title: '', render: (row: Row) => <Link className="text-link" href={`/operations/payment-accounts?account_history=${row.id}`} onClick={(event) => event.stopPropagation()}>{translate('portal', 'ops_version_history')}</Link> },
                    ]}
                />
            </section>
            {editing && (
                <Modal title={account ? translate('portal', 'ops_save_account_version') : translate('portal', 'ops_add_payment_account')} onClose={() => setEditing(null)}>
                    <SettingsForm
                        action={account ? `${root}/${account.id}` : root}
                        fields={fields}
                        defaults={account
                            ? { ...account, collects_payments: account.collects_payments ? '1' : '0', pays_out: account.pays_out ? '1' : '0', is_active: account.is_active ? '1' : '0', _method: 'patch' }
                            : { collects_payments: '1', pays_out: '0', is_active: '1' }}
                        submitLabel={account ? translate('portal', 'ops_save_account_version') : translate('portal', 'ops_add_payment_account')}
                        onDone={() => setEditing(null)}
                    />
                </Modal>
            )}
            {accountHistory.length > 0 && (
                <Modal title={translate('portal', 'ops_payment_account_history')} onClose={() => router.get('/operations/payment-accounts', {}, { preserveScroll: true })}>
                    <Table
                        tools={false}
                        rows={accountHistory}
                        columns={[
                            { key: 'version', title: translate('portal', 'ops_version') },
                            { key: 'label', title: translate('portal', 'ops_account') },
                            { key: 'account_number', title: translate('portal', 'ops_number_at_version') },
                            { key: 'recorded_at', title: translate('portal', 'ops_recorded_at'), render: (row: Row) => dateTime(row.recorded_at) },
                            { key: 'reason', title: translate('portal', 'ops_reason'), render: (row: Row) => row.reason || '—' },
                        ]}
                    />
                </Modal>
            )}
        </OfficePage>
    );
}
