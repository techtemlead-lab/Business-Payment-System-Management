import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { useT } from '../../../shared/i18n/t';
import { actionAllowed } from '../../action-permissions';
import type { OfficeHub } from '../../OfficeHubTabs';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../../MoneyTable';
import { Badge, FormErrors, Modal, date, label, number, type Row } from '../../UI';
import Figures from '../Figures';
import OfficePage from '../OfficePage';

type TranslateFn = ReturnType<typeof useT>;

const placeLine = (row: Row): string => [row.division, row.district, row.upazila].filter(Boolean).join(', ');

const sourceName = (source: string, translate: TranslateFn): string => {
    const word = translate('office_partners', `source_${source}`);

    return word === `source_${source}` ? label(source, translate) : word;
};

/** Dealer or sub-dealer applications: up to three cards, one toolbar and one table. A row opens the review panel. */
export default function Applications({ section, data }: { section: string; data: Row }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [opened, setOpened] = useState<Row | null>(null);
    const [rejecting, setRejecting] = useState<Row | null>(null);

    const table: MoneyTableData<Row> = { ...data.applications_table, rows: data.applications };
    const counts = data.application_counts ?? {};
    const hub = data.hub as OfficeHub | undefined;
    const cardHref = (status: string) => `${base}/${section}?status=${status}`;

    const columns: MoneyColumn<Row>[] = [
        { key: 'created_at', title: translate('office_partners', 'col_date'), sortKey: 'created_at', phone: 'sub', render: (row) => date(row.created_at) },
        { key: 'name', title: translate('portal', 'partnership_applicant'), sortKey: 'name', phone: 'title', render: (row) => <><b>{row.name}</b><small className="cell-sub">{[row.email, row.phone].filter(Boolean).join(' · ')}</small></> },
        { key: 'place', title: translate('office_partners', 'col_area'), phone: 'sub', render: (row) => <>{row.place_code || placeLine(row) || '—'}{row.place_code && placeLine(row) && <small className="cell-sub">{placeLine(row)}</small>}</> },
        { key: 'source', title: translate('portal', 'partnership_source'), sortKey: 'source', phone: 'hide', render: (row) => sourceName(row.source || 'staff', translate) },
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'sub', render: (row) => <Badge status={row.status || 'pending'} /> },
    ];

    return (
        <OfficePage title={hub?.label ?? translate('office_partners', 'applications_title')} hub={hub}>
            <Figures
                items={[
                    { key: 'pending', label: translate('portal', 'partnership_pending'), value: number(counts.pending ?? 0), text: true, href: cardHref('pending') },
                    { key: 'approved', label: translate('portal', 'partnership_approved'), value: number(counts.approved ?? 0), text: true, href: cardHref('approved') },
                    { key: 'rejected', label: translate('portal', 'partnership_rejected'), value: number(counts.rejected ?? 0), text: true, href: cardHref('rejected') },
                ]}
            />
            <MoneyTable
                data={table}
                prop="data"
                columns={columns}
                searchPlaceholder={translate('office_partners', 'search_applications')}
                onRowOpen={(row) => setOpened(row)}
                empty={translate('office_partners', 'empty_applications')}
            />

            {opened && <ReviewPanel application={opened} onClose={() => setOpened(null)} onReject={() => { setRejecting(opened); setOpened(null); }} />}
            {rejecting && <RejectDialog application={rejecting} onClose={() => setRejecting(null)} />}
        </OfficePage>
    );
}

/** Today's review dialog as a side panel. The same address, the same fields and the same permission check. */
function ReviewPanel({ application, onClose, onReject }: { application: Row; onClose: () => void; onReject: () => void }) {
    const translate = useT();
    const { auth, portal, staffActions } = usePage<any>().props;
    const reviewAddress = `/commands/operations/staff/applications/${application.id}/review`;
    const mayDecide = !(portal === 'manage' || portal === 'sadmin') || actionAllowed(reviewAddress, staffActions, 'post');
    const reviewable = application.status === 'pending' && application.submitted_by !== auth?.user?.id;
    const isOwnApplication = application.status === 'pending' && application.submitted_by === auth?.user?.id;
    const form = useForm({ decision: 'approved', place_code: application.place_code || '', sponsor_code: application.sponsor_code || '', reason: '' });

    const lines: [string, React.ReactNode][] = [
        [translate('office_partners', 'detail_email'), application.email],
        [translate('office_partners', 'detail_phone'), application.phone],
        [translate('portal', 'partnership_type'), label(application.role || '', translate)],
        [translate('office_partners', 'detail_area'), placeLine(application)],
        [translate('portal', 'col_place'), application.place_code],
        [translate('office_partners', 'detail_sponsor_code'), application.sponsor_code],
        [translate('portal', 'partnership_source'), sourceName(application.source || 'staff', translate)],
        [translate('office_partners', 'col_date'), date(application.created_at)],
        [translate('office_partners', 'detail_reason'), application.reason],
    ];

    const footer = reviewable && mayDecide ? (
        <>
            <button type="button" className="button" disabled={form.processing} onClick={onReject}>{translate('office_partners', 'reject')}</button>
            <button type="submit" form="application-approve" className="button dark" disabled={form.processing}>{translate('office_partners', 'approve')}</button>
        </>
    ) : undefined;

    return (
        <Modal variant="drawer" title={application.name} badge={<Badge status={application.status || 'pending'} />} onClose={onClose} footer={footer}>
            <dl className="office-facts">
                {lines.filter(([, value]) => value !== null && value !== undefined && value !== '').map(([name, value]) => (
                    <div key={name}><dt>{name}</dt><dd>{value}</dd></div>
                ))}
            </dl>
            {application.message && <p className="prose">{application.message}</p>}
            {isOwnApplication && <p className="field-hint">{translate('office_partners', 'cannot_review_own')}</p>}
            {reviewable && mayDecide && (
                <form
                    id="application-approve"
                    className="action-form"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.clearErrors();
                        form.post(reviewAddress, { preserveScroll: true, onSuccess: onClose });
                    }}
                >
                    <label>
                        {translate('portal', 'partnership_place_code')}
                        <input value={form.data.place_code} onChange={(event) => form.setData('place_code', event.target.value)} />
                        <small className="field-hint">{translate('portal', 'partnership_place_code_hint')}</small>
                        {form.errors.place_code && <span className="field-error" role="alert">{form.errors.place_code}</span>}
                    </label>
                    <label>
                        {translate('portal', 'partnership_sponsor_code')}
                        <input value={form.data.sponsor_code} onChange={(event) => form.setData('sponsor_code', event.target.value)} />
                        <small className="field-hint">{translate('portal', 'partnership_sponsor_code_hint')}</small>
                        {form.errors.sponsor_code && <span className="field-error" role="alert">{form.errors.sponsor_code}</span>}
                    </label>
                    <label>
                        {translate('office_partners', 'review_note')}
                        <textarea rows={3} required maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                        {form.errors.reason && <span className="field-error" role="alert">{form.errors.reason}</span>}
                    </label>
                    <FormErrors errors={form.errors} besideFields={['place_code', 'sponsor_code', 'reason']} />
                </form>
            )}
        </Modal>
    );
}

/** Rejecting asks for one thing: the reason. The same address as approving. */
function RejectDialog({ application, onClose }: { application: Row; onClose: () => void }) {
    const translate = useT();
    const form = useForm({ decision: 'rejected', reason: '' });

    return (
        <Modal title={translate('office_partners', 'reject_title')} onClose={onClose}>
            <form
                className="action-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.clearErrors();
                    form.post(`/commands/operations/staff/applications/${application.id}/review`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <label>
                    {translate('office_partners', 'reject_reason')}
                    <textarea rows={3} required autoFocus maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    {form.errors.reason && <span className="field-error" role="alert">{form.errors.reason}</span>}
                </label>
                <FormErrors errors={form.errors} besideFields={['reason']} />
                <div className="ops-row">
                    <button type="button" className="button" onClick={onClose}>{translate('portal', 'cancel')}</button>
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('office_partners', 'reject')}</button>
                </div>
            </form>
        </Modal>
    );
}
