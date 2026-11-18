import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { useT } from '../../../shared/i18n/t';
import { useReasonField } from '../../../shared/reasonFields';
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
    const [sendingBack, setSendingBack] = useState<Row | null>(null);

    const table: MoneyTableData<Row> = { ...data.applications_table, rows: data.applications };
    const counts = data.application_counts ?? {};
    const hub = data.hub as OfficeHub | undefined;
    const cardHref = (status: string) => `${base}/${section}?status=${status}`;

    const columns: MoneyColumn<Row>[] = [
        { key: 'created_at', title: translate('office_partners', 'col_date'), sortKey: 'created_at', phone: 'sub', render: (row) => date(row.created_at) },
        { key: 'name', title: translate('portal', 'partnership_applicant'), sortKey: 'name', phone: 'title', render: (row) => <><b>{row.name}</b><small className="cell-sub">{row.application_number} · {row.email}</small></> },
        { key: 'phone', title: translate('office_partners', 'detail_phone'), phone: 'hide', render: (row) => row.phone || '—' },
        { key: 'place', title: translate('office_partners', 'col_area'), sortKey: 'place', phone: 'sub', render: (row) => <>{row.partner_code || row.place_code || placeLine(row) || '—'}{(row.partner_code || row.place_code) && placeLine(row) && <small className="cell-sub">{placeLine(row)}</small>}</> },
        { key: 'source', title: translate('portal', 'partnership_source'), sortKey: 'source', phone: 'hide', render: (row) => sourceName(row.source || 'staff', translate) },
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'sub', render: (row) => <Badge status={row.status || 'pending'} /> },
        { key: 'reason', title: translate('office_partners', 'detail_reason'), sortKey: 'reason', phone: 'hide', render: (row) => row.reason || '—' },
    ];

    return (
        <OfficePage title={hub?.label ?? translate('office_partners', 'applications_title')} hub={hub}>
            <Figures
                items={[
                    { key: 'pending', label: translate('portal', 'partnership_pending'), value: number(counts.pending ?? 0), text: true, href: cardHref('pending') },
                    { key: 'correction', label: translate('joining', 'status_correction'), value: number(counts.correction ?? 0), text: true, href: cardHref('correction') },
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

            {opened && <ReviewPanel application={opened} onClose={() => setOpened(null)} onReject={() => { setRejecting(opened); setOpened(null); }} onSendBack={() => { setSendingBack(opened); setOpened(null); }} />}
            {rejecting && <ReasonDialog application={rejecting} decision="rejected" onClose={() => setRejecting(null)} />}
            {sendingBack && <ReasonDialog application={sendingBack} decision="correction" onClose={() => setSendingBack(null)} />}
        </OfficePage>
    );
}

/** Today's review dialog as a side panel. The same address, the same fields and the same permission check. */
function ReviewPanel({ application, onClose, onReject, onSendBack }: { application: Row; onClose: () => void; onReject: () => void; onSendBack: () => void }) {
    const translate = useT();
    const { auth, portal, staffActions } = usePage<any>().props;
    const reviewAddress = `/commands/operations/staff/applications/${application.id}/review`;
    const mayDecide = !(portal === 'manage' || portal === 'sadmin') || actionAllowed(reviewAddress, staffActions, 'post');
    // Staff never decide an application they sent in themselves; the owner may.
    const sentByViewer = application.submitted_by === auth?.user?.id && auth?.user?.role !== 'super_admin';
    const reviewable = application.status === 'pending' && !sentByViewer;
    const isOwnApplication = application.status === 'pending' && sentByViewer;
    const canDecide = reviewable && mayDecide;
    const review = application.review as Row | null;
    const reviewReason = useReasonField('partner.application_review');
    const form = useForm({
        decision: 'approved',
        role: application.role || 'dealer',
        place_code: application.place_code || '',
        sponsor_code: application.sponsor_code || '',
        reason: '',
        directory_visible: application.role === 'dealer',
        area_dealer: true,
        accept_place_mismatch: false,
    });
    const [picked, setPicked] = useState<PickedPlace>({ division: String(application.division_place_id ?? ''), district: String(application.district_place_id ?? ''), upazila: String(application.upazila_place_id ?? ''), union: String(application.union_place_id ?? '') });
    const isDealer = form.data.role === 'dealer';

    // What the reviewer should look at again before pressing Approve.
    const warnings: string[] = (review?.warnings ?? []).map((item: Row) => String(item.text));
    if (isDealer && review?.area_holder && picked.upazila !== '' && picked.upazila === String(application.upazila_place_id ?? '')) {
        warnings.push(translate('joining', 'check_area_holder', { name: review.area_holder.name, code: review.area_holder.code }));
    }
    const sponsor = review?.sponsor as Row | null | undefined;
    if (form.data.role === 'sub_dealer' && !String(form.data.sponsor_code).trim()) {
        warnings.push(translate('joining', 'check_sub_without_sponsor'));
    }
    if (isDealer && sponsor?.role === 'sub_dealer') {
        warnings.push(translate('joining', 'check_dealer_under_sub'));
    }
    if (sponsor?.upazila && application.upazila && String(sponsor.upazila).trim().toLowerCase() !== String(application.upazila).trim().toLowerCase()) {
        warnings.push(translate('joining', 'check_sponsor_other_upazila', { sponsor: String(sponsor.upazila), applicant: String(application.upazila) }));
    }
    const appArea = application.upazila_place_id ? String(application.upazila_place_id) : application.district_place_id ? String(application.district_place_id) : '';
    const pickedArea = application.upazila_place_id ? picked.upazila : picked.district;
    const placeDiffers = appArea !== '' && pickedArea !== '' && pickedArea !== appArea;

    const lines: [string, React.ReactNode][] = [
        [translate('joining', 'status_number'), application.application_number],
        [translate('joining', 'drawer_partner_code'), application.partner_code],
        [translate('office_partners', 'detail_email'), application.email],
        [translate('office_partners', 'detail_phone'), application.phone],
        [translate('joining', 'drawer_dob'), application.date_of_birth ? `${application.date_of_birth}${review?.age != null ? ` (${translate('joining', 'drawer_age', { years: review.age })})` : ''}` : ''],
        [translate('joining', 'nid_label'), application.nid],
        [translate('portal', 'partnership_type'), label(application.role || '', translate)],
        [translate('office_partners', 'detail_area'), placeLine(application)],
        ...(canDecide ? [] : [[translate('portal', 'col_place'), application.place_code], [translate('office_partners', 'detail_sponsor_code'), application.sponsor_code]] as [string, React.ReactNode][]),
        [translate('portal', 'partnership_source'), sourceName(application.source || 'staff', translate)],
        [translate('office_partners', 'col_date'), date(application.created_at)],
        [translate('office_partners', 'detail_reason'), application.reason],
    ];

    const footer = canDecide ? (
        <>
            <button type="button" className="button" disabled={form.processing} onClick={onReject}>{translate('office_partners', 'reject')}</button>
            <button type="button" className="button" disabled={form.processing} onClick={onSendBack}>{translate('joining', 'drawer_send_back')}</button>
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
            <p className="field-hint">{translate('joining', 'drawer_documents_note')}</p>
            {application.status === 'correction' && application.correction_note && <p className="field-hint"><b>{translate('joining', 'status_fix_needed')}:</b> {application.correction_note}</p>}
            {isOwnApplication && <p className="field-hint">{translate('office_partners', 'cannot_review_own')}</p>}
            {canDecide && warnings.length > 0 && (
                <div className="notice warning" role="alert">
                    <b>{translate('joining', 'drawer_check_title')}</b>
                    <ul>{warnings.map((text) => <li key={text}>{text}</li>)}</ul>
                </div>
            )}
            {canDecide && (
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
                        {translate('joining', 'drawer_type')}
                        <select value={form.data.role} onChange={(event) => { form.setData('role', event.target.value); form.setData('directory_visible', event.target.value === 'dealer'); }}>
                            <option value="dealer">{translate('portal', 'acct_role_dealer')}</option>
                            <option value="sub_dealer">{translate('portal', 'acct_role_sub_dealer')}</option>
                        </select>
                        <small className="field-hint">{translate('joining', form.data.role === application.role ? 'drawer_type_hint' : 'drawer_type_changed')}</small>
                        {form.errors.role && <span className="field-error" role="alert">{form.errors.role}</span>}
                    </label>
                    <PlacePicker picked={picked} onPick={(next, code) => { setPicked(next); if (code) { form.setData('place_code', code); } }} />
                    <label>
                        {translate('portal', 'partnership_place_code')}
                        <input value={form.data.place_code} onChange={(event) => form.setData('place_code', event.target.value)} />
                        <small className="field-hint">{translate('joining', form.data.role === 'sub_dealer' ? 'drawer_place_code_hint_sub' : 'drawer_place_code_hint_dealer')}</small>
                        {form.errors.place_code && <span className="field-error" role="alert">{form.errors.place_code}</span>}
                    </label>
                    {placeDiffers && (
                        <label className="import-confirmation">
                            <input type="checkbox" checked={form.data.accept_place_mismatch} onChange={(event) => form.setData('accept_place_mismatch', event.target.checked)} /> {translate('joining', 'drawer_place_mismatch')}
                        </label>
                    )}
                    <label>
                        {translate('portal', 'partnership_sponsor_code')}
                        <input value={form.data.sponsor_code} onChange={(event) => form.setData('sponsor_code', event.target.value)} />
                        <small className="field-hint">{sponsor ? translate('joining', 'drawer_sponsor_found', { name: String(sponsor.name) }) : translate('portal', 'partnership_sponsor_code_hint')}</small>
                        <small className="field-hint">{translate('joining', 'drawer_sponsor_rules')}</small>
                        {form.errors.sponsor_code && <span className="field-error" role="alert">{form.errors.sponsor_code}</span>}
                    </label>
                    {isDealer && (
                        <label className="import-confirmation">
                            <input type="checkbox" checked={form.data.area_dealer} onChange={(event) => form.setData('area_dealer', event.target.checked)} /> {translate('joining', 'drawer_area_dealer')}
                            <small className="field-hint">{translate('joining', 'drawer_area_dealer_hint')}</small>
                        </label>
                    )}
                    {(form.data.role === 'dealer' || form.data.role === 'sub_dealer') && (
                        <label className="import-confirmation">
                            <input type="checkbox" checked={form.data.directory_visible} onChange={(event) => form.setData('directory_visible', event.target.checked)} /> {translate('portal', 'acct_directory_visible')}
                            <small className="field-hint">{translate('office_partners', 'directory_visible_hint')}</small>
                        </label>
                    )}
                    {reviewReason.shown && (
                        <label>
                            {reviewReason.label(translate('office_partners', 'review_note'))}
                            <textarea rows={3} required={reviewReason.required} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                            {form.errors.reason && <span className="field-error" role="alert">{form.errors.reason}</span>}
                        </label>
                    )}
                    <FormErrors errors={form.errors} besideFields={['place_code', 'sponsor_code', 'reason', 'role']} />
                </form>
            )}
        </Modal>
    );
}

type PickedPlace = { division: string; district: string; upazila: string; union: string };
type PlaceChoice = { id: number; code: string; name: string };
const PLACE_LEVELS: (keyof PickedPlace)[] = ['division', 'district', 'upazila', 'union'];

/**
 * Division, district, upazila and union picked from the same lists the office uses, so nobody has to look a
 * code up in a long list. Picking a place fills the place code with the deepest place chosen.
 */
function PlacePicker({ picked, onPick }: { picked: PickedPlace; onPick: (next: PickedPlace, code: string | null) => void }) {
    const translate = useT();
    const [options, setOptions] = useState<Record<string, PlaceChoice[]>>({ division: [], district: [], upazila: [], union: [] });

    const load = async (level: keyof PickedPlace, parent: string): Promise<PlaceChoice[]> => {
        if (level !== 'division' && parent === '') {
            return [];
        }
        const params = new URLSearchParams({ level, ...(parent !== '' ? { parent_id: parent } : {}) });
        try {
            const response = await fetch(`/commands/place-codes/children?${params}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            const json = await response.json();

            return (json.data ?? []) as PlaceChoice[];
        } catch {
            return [];
        }
    };

    useEffect(() => {
        let alive = true;
        (async () => {
            const next: Record<string, PlaceChoice[]> = { division: await load('division', ''), district: [], upazila: [], union: [] };
            next.district = await load('district', picked.division);
            next.upazila = await load('upazila', picked.district);
            next.union = await load('union', picked.upazila);
            if (alive) {
                setOptions(next);
            }
        })();

        return () => { alive = false; };
        // The lists are loaded once for the places the application already names.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const choose = async (level: keyof PickedPlace, value: string) => {
        const index = PLACE_LEVELS.indexOf(level);
        const next: PickedPlace = { ...picked, [level]: value };
        const lists = { ...options };
        for (const lower of PLACE_LEVELS.slice(index + 1)) {
            next[lower] = '';
            lists[lower] = [];
        }
        const child = PLACE_LEVELS[index + 1];
        if (child && value !== '') {
            lists[child] = await load(child, value);
        }
        setOptions(lists);
        const deepest = [...PLACE_LEVELS].reverse().find((item) => next[item] !== '');
        const chosen = deepest ? lists[deepest].find((item) => String(item.id) === next[deepest]) : undefined;
        onPick(next, chosen?.code ?? null);
    };

    const wording: Record<keyof PickedPlace, string> = {
        division: translate('joining', 'division'),
        district: translate('joining', 'district'),
        upazila: translate('joining', 'upazila'),
        union: translate('joining', 'union'),
    };

    return (
        <div className="form-grid">
            {PLACE_LEVELS.map((level) => (
                <label key={level}>
                    {wording[level]}
                    <select value={picked[level]} onChange={(event) => { void choose(level, event.target.value); }}>
                        <option value="">{translate('joining', 'choose_place')}</option>
                        {options[level].map((item) => <option key={item.id} value={String(item.id)}>{item.name}</option>)}
                    </select>
                </label>
            ))}
        </div>
    );
}

/** Rejecting and sending back both ask for one thing: the reason the applicant will read. */
function ReasonDialog({ application, decision, onClose }: { application: Row; decision: 'rejected' | 'correction'; onClose: () => void }) {
    const translate = useT();
    const form = useForm({ decision, reason: '' });

    return (
        <Modal title={translate(decision === 'rejected' ? 'office_partners' : 'joining', decision === 'rejected' ? 'reject_title' : 'send_back_title')} onClose={onClose}>
            <form
                className="action-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.clearErrors();
                    form.post(`/commands/operations/staff/applications/${application.id}/review`, { preserveScroll: true, onSuccess: onClose });
                }}
            >
                <p className="field-hint">{translate('joining', 'reason_shown_to_applicant')}</p>
                <label>
                    {translate('office_partners', 'reject_reason')}
                    <textarea rows={3} required autoFocus maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    {form.errors.reason && <span className="field-error" role="alert">{form.errors.reason}</span>}
                </label>
                <FormErrors errors={form.errors} besideFields={['reason']} />
                <div className="ops-row">
                    <button type="button" className="button" onClick={onClose}>{translate('portal', 'cancel')}</button>
                    <button type="submit" className="button dark" disabled={form.processing}>{translate(decision === 'rejected' ? 'office_partners' : 'joining', decision === 'rejected' ? 'reject' : 'drawer_send_back')}</button>
                </div>
            </form>
        </Modal>
    );
}
