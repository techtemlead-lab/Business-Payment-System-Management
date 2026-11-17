import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, GitFork } from 'lucide-react';
import Shell from '../components/Shell';
import AccountPasswordManager from '../components/AccountPasswordManager';
import { Badge, date, money, number, type Row } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Target = {
    kind: 'salary' | 'rank' | 'royalty' | 'incentive';
    unit: 'money' | 'count';
    label: string;
    hint: string;
    current: string;
    default_target: string;
    target: string;
    override: string | null;
    note: string | null;
    percent: number;
    remaining: string;
    has_target: boolean;
};

/** Money for taka targets, a plain number for people targets. */
const amount = (unit: Target['unit'], value: string) => (unit === 'money' ? money(value) : number(value));

/** The month before or after "YYYY-MM". */
function shiftMonth(month: string, step: number): string {
    const [year, mon] = month.split('-').map(Number);
    const moved = new Date(Date.UTC(year, mon - 1 + step, 1));

    return `${moved.getUTCFullYear()}-${String(moved.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** A titled group of label and value lines; empty values are left out so the page stays short. */
function Facts({ title, lines, note }: { title: string; lines: [string, React.ReactNode][]; note?: string }) {
    const shown = lines.filter(([, value]) => value !== null && value !== undefined && value !== '');

    return (
        <section className="panel">
            <h2>{title}</h2>
            {shown.length > 0 ? (
                <dl className="office-facts">
                    {shown.map(([name, value]) => <div key={name}><dt>{name}</dt><dd>{value}</dd></div>)}
                </dl>
            ) : null}
            {note && <p className="muted">{note}</p>}
        </section>
    );
}

/**
 * One dealer's whole file for the office: every stored fact, the password for owners, the monthly
 * targets with the owner's own number for the month, and a way into the dealer's team below.
 */
export default function PartnerProfile({ sheet, month, targets, canSetTargets, canManagePassword }: { sheet: Row; month: string; targets: Target[]; canSetTargets: boolean; canManagePassword: boolean }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('partner_file', key, replace);
    const { portalBase: base = '', auth } = usePage<any>().props;
    const isDealer = sheet.role === 'dealer';
    const listPath = `${base}/${isDealer ? 'accounts' : 'sub-dealers'}`;
    const yesNo = (value: boolean) => t(value ? 'yes' : 'no');
    const identity = sheet.identity ?? {};
    const contact = sheet.contact ?? {};
    const place = sheet.place ?? {};
    const kyc = sheet.kyc ?? {};
    const network = sheet.network ?? {};
    const sponsor = network.sponsor;
    const monthText = new Intl.DateTimeFormat(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${month}-01T00:00:00Z`));
    const canResetOnly = canManagePassword && !['super_admin', 'md', 'chairman'].includes(auth?.user?.role);

    return (
        <Shell section="accounts">
            <Head title={`${sheet.name} · ${t('page_title')}`} />
            <div className="partner-profile">
                <header className="panel">
                    <Link className="text-link" href={listPath}><ArrowLeft size={16} aria-hidden="true" /> {t('back_to_list')}</Link>
                    <div className="ops-row is-between is-wrap">
                        <div>
                            <h1>{sheet.name}</h1>
                            <p className="muted">{[identity.dealer_code, t(isDealer ? 'type_dealer' : 'type_sub_dealer')].filter(Boolean).join(' · ')}</p>
                        </div>
                        <span className="ops-row">{sheet.locked && <span className="badge tone-warn">{t('locked_badge')}</span>}<Badge status={sheet.status} /></span>
                    </div>
                    <div className="ops-row is-wrap">
                        <Link className="button dark" href={`${base}/network-tree?tree_root=${sheet.id}`}><GitFork size={15} aria-hidden="true" /> {t('open_team_tree')}</Link>
                        <Link className="button" href={`${listPath}?q=${encodeURIComponent(identity.dealer_code || sheet.name)}`}>{t('open_edit')}</Link>
                        <Link className="button" href={`${base}/accounts/${sheet.id}/team-orders`}>{t('open_team_orders')}</Link>
                    </div>
                </header>

                <Facts title={t('sec_identity')} lines={[
                    [t('f_name_bn'), identity.name_bn],
                    [t('f_dealer_code'), identity.dealer_code],
                    [t('f_refer_code'), identity.refer_code],
                    [t('f_shop'), identity.shop_name],
                    [t('f_father'), identity.father_name],
                    [t('f_mother'), identity.mother_name],
                    [t('f_occupation'), identity.occupation],
                    [t('f_dob'), identity.date_of_birth ? date(identity.date_of_birth) : null],
                    [t('f_joined'), identity.joined ? date(identity.joined) : null],
                    [t('f_closed'), identity.closed_at ? date(identity.closed_at) : null],
                    [t('f_language'), identity.locale],
                ]} />

                <section className="panel">
                    <h2>{t('lock_title')}</h2>
                    <p className="muted">{t(sheet.locked ? 'lock_note_on' : 'lock_note_off')}</p>
                    <button type="button" className="button" onClick={() => router.post(`/commands/accounts/${sheet.id}/profile-lock`, { locked: !sheet.locked }, { preserveScroll: true })}>{t(sheet.locked ? 'lock_button_off' : 'lock_button_on')}</button>
                </section>

                {canManagePassword && (
                    <section className="panel">
                        <h2>{t('sec_password')}</h2>
                        <AccountPasswordManager account={{ id: sheet.id }} canManage />
                        {canResetOnly && <p className="muted">{t('password_note')}</p>}
                    </section>
                )}

                <Facts title={t('sec_contact')} lines={[
                    [t('f_phone'), contact.phone],
                    [t('f_email'), contact.email],
                    [t('f_extra_contact'), contact.extra_contact],
                    [t('f_village'), contact.village],
                    [t('f_post_office'), contact.post_office],
                    [t('f_union'), contact.union],
                    [t('f_upazila'), contact.upazila],
                    [t('f_district'), contact.district],
                    [t('f_division'), contact.division],
                    [t('f_location'), contact.latitude != null && contact.longitude != null ? `${contact.latitude}, ${contact.longitude}` : null],
                    [t('f_directory'), yesNo(Boolean(contact.directory_visible))],
                ]} />

                <Facts title={t('sec_place')} lines={[
                    [t('f_place_code'), place.place_code],
                    [t('f_area_code'), place.area_code],
                    [t('f_union_place_code'), place.union_place_code],
                ]} />

                <Facts title={t('sec_kyc')} note={kyc.nid && String(kyc.nid).startsWith('••') ? t('nid_masked_note') : undefined} lines={[
                    [t('f_identity_type'), kyc.identity_type ? t(`identity_${kyc.identity_type}`) : null],
                    [t('f_nid'), kyc.nid],
                    [t('f_birth_cert'), kyc.birth_certificate_number],
                    [t('f_documents'), (kyc.documents ?? []).length > 0
                        ? <ul className="plain-list">{(kyc.documents as Row[]).map((doc) => <li key={doc.id}>{doc.original_name}{doc.created_at ? ` · ${date(doc.created_at)}` : ''}</li>)}</ul>
                        : t('no_documents')],
                ]} />

                <Facts title={t('sec_network')} lines={[
                    [t('f_sponsor'), sponsor ? <Link className="text-link" href={`${base}/accounts/${sponsor.id}`}>{sponsor.name}{sponsor.dealer_code ? ` · ${sponsor.dealer_code}` : ''}</Link> : t('company_direct')],
                    [t('f_rank'), isDealer ? translate('portal', `net_rank_${network.rank || 'dealer'}`) : null],
                    [t('f_rank_date'), network.rank_achieved_at ? date(network.rank_achieved_at) : null],
                    [t('f_lifetime'), money(network.lifetime_purchases ?? 0)],
                    [t('f_direct'), number(network.direct_count ?? 0)],
                    [t('f_team'), number(network.team_size ?? 0)],
                ]} />

                <section className="panel table-panel">
                    <h2>{t('sec_money')}</h2>
                    <dl className="office-facts"><div><dt>{t('f_wallet')}</dt><dd>{money(sheet.money?.available ?? 0)}</dd></div></dl>
                    <h3>{t('payouts_title')}</h3>
                    {(sheet.money?.payouts ?? []).length === 0 ? <p className="muted">{t('no_payouts')}</p> : (
                        <div className="table-scroll">
                            <table className="table">
                                <thead><tr><th>{t('col_receipt')}</th><th>{t('col_method')}</th><th>{t('col_account')}</th><th>{t('col_paid_on')}</th><th>{t('col_net')}</th></tr></thead>
                                <tbody>
                                    {(sheet.money.payouts as Row[]).map((row) => (
                                        <tr key={row.number}>
                                            <td>{row.number}</td>
                                            <td>{t(`method_${row.method}`)}{row.bank_name ? ` · ${row.bank_name}` : ''}</td>
                                            <td>{row.account || '—'}</td>
                                            <td>{date(row.paid_at)}</td>
                                            <td>{money(row.net_amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Link className="text-link" href={`${base}/treasury/partners/${sheet.id}`}>{t('open_money')}</Link>
                </section>

                <TargetsPanel partnerId={sheet.id} isDealer={isDealer} month={month} monthText={monthText} targets={targets} canSet={canSetTargets} />
            </div>
        </Shell>
    );
}

/** The month's targets with progress; the owner can set a different number for this dealer and month. */
function TargetsPanel({ partnerId, isDealer, month, monthText, targets, canSet }: { partnerId: number; isDealer: boolean; month: string; monthText: string; targets: Target[]; canSet: boolean }) {
    const translate = useT();
    const t = (key: string) => translate('partner_file', key);
    const { portalBase: base = '' } = usePage<any>().props;
    const [editing, setEditing] = useState<Target['kind'] | null>(null);
    const [value, setValue] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const go = (to: string) => router.get(`${base}/accounts/${partnerId}`, { month: to }, { preserveScroll: true, replace: true });
    const open = (target: Target) => { setEditing(target.kind); setValue(target.override ?? target.target); setNote(target.note ?? ''); };
    const save = (target: Target) => {
        setBusy(true);
        router.post(`/commands/accounts/${partnerId}/monthly-targets`, { month, kind: target.kind, amount: value, note }, {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
            onFinish: () => setBusy(false),
        });
    };
    const clear = (target: Target) => router.post(`/commands/accounts/${partnerId}/monthly-targets/clear`, { month, kind: target.kind }, { preserveScroll: true });

    return (
        <section className="panel" aria-labelledby="partner-targets-title">
            <div className="ops-row is-between is-wrap">
                <h2 id="partner-targets-title">{t('sec_targets')}</h2>
                <div className="ops-row">
                    <button type="button" className="button small" onClick={() => go(shiftMonth(month, -1))} aria-label="−">‹</button>
                    <strong>{monthText}</strong>
                    <button type="button" className="button small" onClick={() => go(shiftMonth(month, 1))} aria-label="+">›</button>
                </div>
            </div>
            {!isDealer ? <p className="muted">{t('targets_sub_dealer')}</p> : (
                <>
                    <p className="muted">{t('targets_hint')}</p>
                    <div className="pp-target-list">
                        {targets.map((target) => (
                            <article key={target.kind} className="pp-target">
                                <div className="ops-row is-between is-wrap">
                                    <h3>{target.label}</h3>
                                    {target.override !== null && <span className="badge tone-info">{t('own_target_badge')}</span>}
                                </div>
                                {target.has_target ? (
                                    <>
                                        <div className="pp-bar" role="progressbar" aria-label={target.label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={target.percent}><i style={{ width: `${target.percent}%` }} /></div>
                                        <dl className="office-facts">
                                            <div><dt>{t('col_now')}</dt><dd>{amount(target.unit, target.current)} ({number(target.percent)}%)</dd></div>
                                            <div><dt>{t('col_target')}</dt><dd>{amount(target.unit, target.target)}</dd></div>
                                            <div><dt>{t('col_left')}</dt><dd>{amount(target.unit, target.remaining)}</dd></div>
                                            {target.override !== null && <div><dt>{t('col_default')}</dt><dd>{amount(target.unit, target.default_target)}</dd></div>}
                                        </dl>
                                    </>
                                ) : <p className="muted">{t('no_target_for_kind')}</p>}
                                <small className="muted">{target.hint}</small>
                                {target.note && <small className="muted">“{target.note}”</small>}
                                {canSet && target.has_target && editing !== target.kind && (
                                    <div className="ops-row is-wrap">
                                        <button type="button" className="button small" onClick={() => open(target)}>{t('set_target')}</button>
                                        {target.override !== null && <button type="button" className="button small" onClick={() => clear(target)}>{t('clear_target')}</button>}
                                    </div>
                                )}
                                {editing === target.kind && (
                                    <form className="ops-stack" onSubmit={(event) => { event.preventDefault(); save(target); }}>
                                        <label>{t('target_amount')} ({t(target.unit === 'money' ? 'unit_money' : 'unit_count')})
                                            <input type="number" inputMode="decimal" min="1" step={target.unit === 'money' ? '0.01' : '1'} required value={value} onChange={(event) => setValue(event.target.value)} />
                                        </label>
                                        <label>{t('target_note')}
                                            <input type="text" maxLength={255} value={note} onChange={(event) => setNote(event.target.value)} />
                                        </label>
                                        <div className="ops-row">
                                            <button type="submit" className="button dark" disabled={busy}>{t('save_target')}</button>
                                            <button type="button" className="button" onClick={() => setEditing(null)}>{t('cancel')}</button>
                                        </div>
                                    </form>
                                )}
                            </article>
                        ))}
                    </div>
                    {!canSet && <p className="muted">{t('only_owner_note')}</p>}
                </>
            )}
        </section>
    );
}
