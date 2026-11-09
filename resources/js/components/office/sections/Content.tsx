import { useState } from 'react';
import { Plus } from 'lucide-react';
import { ActionForm, Badge, Modal, type Field, type Row } from '../../UI';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../../MoneyTable';
import OfficePage from '../OfficePage';
import { contentPageLabel, type PortalSectionProps } from '../portalShared';
import { useT } from '../../../shared/i18n/t';

type PageRow = Row & { id: number; slug: string; title: string; body: string; published: boolean };

/** "পাতার লেখা": the text of the website's information pages, one row each. */
export default function Content({ data }: PortalSectionProps) {
    const translate = useT();
    const [editing, setEditing] = useState<PageRow | 'new' | null>(null);
    const pageName = (slug: string): string => contentPageLabel(slug, translate);
    const fields: Field[] = [
        { name: 'slug', label: translate('portal', 'content_field_page'), required: true, options: ((data.page_slugs ?? []) as string[]).map((slug) => ({ value: slug, label: pageName(slug) })) },
        { name: 'title', label: translate('portal', 'content_field_title'), required: true },
        { name: 'body', label: translate('portal', 'content_field_body'), type: 'textarea', required: true },
        { name: 'published', label: translate('portal', 'content_field_publication'), required: true, options: [{ value: '1', label: translate('portal', 'content_published') }, { value: '0', label: translate('portal', 'content_draft') }] },
    ];
    const columns: MoneyColumn<PageRow>[] = [
        { key: 'slug', title: translate('portal', 'content_col_page'), sortKey: 'slug', phone: 'sub', render: (row) => pageName(row.slug) },
        { key: 'title', title: translate('portal', 'content_col_title'), sortKey: 'title', phone: 'title' },
        { key: 'published', title: translate('portal', 'enquiry_status_label'), phone: 'sub', render: (row) => <Badge status={row.published ? 'published' : 'draft'} /> },
    ];
    const close = () => setEditing(null);

    return (
        <OfficePage
            title={translate('website_admin', 'heading')}
            hub={data.hub}
            action={<button type="button" className="button dark" onClick={() => setEditing('new')}><Plus size={17} aria-hidden="true" />{translate('office_website', 'page_text_write')}</button>}
        >
            <MoneyTable<PageRow>
                data={data.pages_table as MoneyTableData<PageRow> | undefined}
                prop="data"
                columns={columns}
                searchPlaceholder={translate('office_website', 'page_text_search')}
                hideViewChips
                onRowOpen={setEditing}
                empty={translate('portal', 'content_empty_title')}
            />
            {editing !== null && (
                <Modal title={translate('portal', 'content_modal_title')} onClose={close}>
                    <ActionForm
                        action="/commands/content"
                        defaults={editing === 'new' ? { published: '0' } : { ...editing, published: editing.published ? '1' : '0' }}
                        fields={fields}
                        onDone={close}
                    />
                </Modal>
            )}
        </OfficePage>
    );
}
