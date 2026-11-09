import { useState } from 'react';
import { ActionForm, Badge, Modal, date, label, type Row } from '../../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../../MoneyTable';
import OfficePage from '../OfficePage';
import { supportTopicLabel, type PortalSectionProps } from '../portalShared';
import { useT } from '../../../shared/i18n/t';

type EnquiryRow = Row & { id: number; name: string; phone: string; topic: string | null; message: string; status: string; tracking_number: string | null; product_name: string | null; batch_number: string | null; created_at: string };

/** "ওয়েবসাইটের জিজ্ঞাসা": messages sent from the website. A row opens its status. */
export default function Enquiries({ data }: PortalSectionProps) {
    const translate = useT();
    const [updating, setUpdating] = useState<EnquiryRow | null>(null);
    const columns: MoneyColumn<EnquiryRow>[] = [
        { key: 'created_at', title: translate('portal', 'enquiry_received'), sortKey: 'created_at', phone: 'sub', render: (row) => date(row.created_at) },
        {
            key: 'name',
            title: translate('portal', 'enquiry_contact'),
            sortKey: 'name',
            phone: 'title',
            render: (row) => <>{row.name}<small className="cell-sub">{row.phone}</small></>,
        },
        {
            key: 'topic',
            title: translate('portal', 'enquiry_topic'),
            phone: 'hide',
            render: (row) => <>{supportTopicLabel(String(row.topic ?? ''), translate)}{row.tracking_number ? <small className="cell-sub">{row.tracking_number}</small> : null}</>,
        },
        {
            key: 'message',
            title: translate('portal', 'enquiry_message'),
            phone: 'sub',
            render: (row) => <>{row.message}{(row.product_name || row.batch_number) ? <small className="cell-sub">{[row.product_name, row.batch_number ? `${translate('portal', 'enquiry_batch_number')}: ${row.batch_number}` : ''].filter(Boolean).join(' · ')}</small> : null}</>,
        },
        { key: 'status', title: translate('portal', 'enquiry_status'), sortKey: 'status', phone: 'sub', render: (row) => <Badge status={row.status || 'new'} /> },
    ];

    return (
        <OfficePage title={data.hub?.label ?? translate('office_website', 'messages_title')} hub={data.hub}>
            <MoneyTable<EnquiryRow>
                data={data.enquiries_table as MoneyTableData<EnquiryRow> | undefined}
                prop="data"
                columns={columns}
                searchPlaceholder={translate('office_website', 'enquiries_search')}
                hideViewChips
                onRowOpen={setUpdating}
                empty={translate('portal', 'enquiry_empty')}
            />
            {updating && (
                <Modal title={translate('portal', 'enquiry_update_title')} onClose={() => setUpdating(null)}>
                    <ActionForm
                        action={`/commands/enquiries/${updating.id}`}
                        fields={[{ name: 'status', label: translate('portal', 'enquiry_status_label'), required: true, value: updating.status, options: ['new', 'contacted', 'closed'].map((value) => ({ value, label: label(value, translate) })) }]}
                        onDone={() => setUpdating(null)}
                    />
                </Modal>
            )}
        </OfficePage>
    );
}
