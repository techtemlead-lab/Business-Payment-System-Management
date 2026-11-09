import { useState } from 'react';
import { Plus } from 'lucide-react';
import { ActionForm, Badge, Modal, date, number, type Field, type Row } from '../../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../../MoneyTable';
import OfficePage from '../OfficePage';
import type { PortalSectionProps } from '../portalShared';
import { useT } from '../../../shared/i18n/t';

type FeedbackRow = Row & { id: number; name: string; role: string; location: string; rating: number; message: string; source: string; product_name: string | null; status: string; created_at: string };

/** "গ্রাহকের মতামত": what visitors and partners wrote, waiting ones first. A row opens its status; the top button adds one received elsewhere. */
export default function Feedback({ data }: PortalSectionProps) {
    const translate = useT();
    const [moderating, setModerating] = useState<FeedbackRow | null>(null);
    const [adding, setAdding] = useState(false);
    const statusFields = (status: string): Field[] => [{
        name: 'status',
        label: translate('portal', 'enquiry_status_label'),
        required: true,
        value: status,
        hint: translate('product_facts', 'moderation_cure_hint'),
        options: [{ value: 'pending', label: translate('portal', 'feedback_pending') }, { value: 'approved', label: translate('portal', 'feedback_approved') }, { value: 'rejected', label: translate('portal', 'feedback_rejected') }],
    }];
    const addFields: Field[] = [
        { name: 'name', label: translate('portal', 'feedback_add_name'), required: true },
        { name: 'role', label: translate('portal', 'feedback_add_role') },
        { name: 'location', label: translate('portal', 'feedback_add_location') },
        { name: 'rating', label: translate('portal', 'feedback_add_rating'), required: true, value: '5', options: [5, 4, 3, 2, 1].map((stars) => ({ value: String(stars), label: `${number(stars)} ★` })) },
        { name: 'message', label: translate('portal', 'feedback_add_message'), type: 'textarea', required: true, hint: translate('portal', 'feedback_add_hint') },
    ];
    const columns: MoneyColumn<FeedbackRow>[] = [
        { key: 'created_at', title: translate('portal', 'enquiry_received'), sortKey: 'created_at', phone: 'sub', render: (row) => date(row.created_at) },
        {
            key: 'name',
            title: translate('portal', 'enquiry_contact'),
            sortKey: 'name',
            phone: 'title',
            render: (row) => (
                <>
                    <b>{row.name}</b>
                    <small className="cell-sub">{row.role} · {row.location} · {translate('portal', `feedback_source_${row.source}`)}</small>
                    {row.product_name && <small className="cell-sub">{translate('product_facts', 'review_of', { name: row.product_name })}</small>}
                </>
            ),
        },
        { key: 'rating', title: translate('portal', 'feedback_form_rating'), sortKey: 'rating', align: 'right', phone: 'hide', render: (row) => `${number(row.rating)}★` },
        { key: 'message', title: translate('portal', 'enquiry_message'), phone: 'sub', render: (row) => row.message },
        { key: 'status', title: translate('portal', 'enquiry_status'), phone: 'sub', render: (row) => <Badge status={row.status || 'pending'} /> },
    ];

    return (
        <OfficePage
            title={data.hub?.label ?? translate('website_admin', 'heading')}
            hub={data.hub}
            action={<button type="button" className="button dark" onClick={() => setAdding(true)}><Plus size={17} aria-hidden="true" />{translate('portal', 'feedback_add_title')}</button>}
        >
            <MoneyTable<FeedbackRow>
                data={data.feedback_table as MoneyTableData<FeedbackRow> | undefined}
                prop="data"
                columns={columns}
                searchPlaceholder={translate('office_website', 'feedback_search')}
                hideViewChips
                onRowOpen={setModerating}
                empty={translate('portal', 'feedback_empty')}
            />
            {moderating && (
                <Modal title={translate('portal', 'feedback_moderate')} onClose={() => setModerating(null)}>
                    <ActionForm action={`/commands/feedback/${moderating.id}`} fields={statusFields(moderating.status)} onDone={() => setModerating(null)} />
                </Modal>
            )}
            {adding && (
                <Modal title={translate('portal', 'feedback_add_title')} onClose={() => setAdding(false)}>
                    <ActionForm action="/commands/testimonials" fields={addFields} submit={translate('portal', 'feedback_add_submit')} onDone={() => setAdding(false)} />
                </Modal>
            )}
        </OfficePage>
    );
}
