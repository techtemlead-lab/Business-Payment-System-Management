import React from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import { label, type Row } from '../components/UI';
import { ArrowLeft, UserPlus, ShieldAlert, MapPin, Store, Building2, BadgeCheck } from 'lucide-react';

const divisions = [
    'Dhaka',
    'Chittagong',
    'Rajshahi',
    'Khulna',
    'Barishal',
    'Sylhet',
    'Rangpur',
    'Mymensingh',
];

const availablePermissions = [
    { key: 'field_sales', label: 'Field Sales Workspace', description: 'Work with partners inside assigned territories' },
    { key: 'sr_orders', label: 'Territory Orders', description: 'Submit and track orders for assigned partners' },
    { key: 'sr_dues', label: 'Territory Dues', description: 'Read assigned partner dues and reports' },
    { key: 'sr_onboarding', label: 'Dealer Applications', description: 'Submit new partner applications for review' },
    { key: 'staff_admin', label: 'Territories & Targets', description: 'Assign sales territories and monthly targets' },
    { key: 'payroll', label: 'Payroll Preparation', description: 'Prepare closed-month salaries from effective contracts' },
    { key: 'orders', label: 'Orders & Deliveries', description: 'View orders, shipments, deliveries and payment claims' },
    { key: 'order_review', label: 'Order Review & Approvals', description: 'Approve purchase requests and verify credit allocations' },
    { key: 'inventory', label: 'Inventory & Stock Movements', description: 'Track stock levels, record receipts and stock adjustments' },
    { key: 'catalog', label: 'Product Catalogue & Pricing', description: 'Manage products, units, descriptions and promotional flags' },
    { key: 'accounts', label: 'Partner Directory & Accounts', description: 'Manage dealer and sub-dealer profiles and KYC' },
    { key: 'finance', label: 'Treasury, Commissions & Payouts', description: 'View capital waterfall, settlements, commissions and payouts' },
    { key: 'reports', label: 'Export Reports', description: 'Export order CSVs and financial summaries' },
    { key: 'content', label: 'Content Management & Customization', description: 'Manage site content pages and visual hero customization' },
    { key: 'audit', label: 'Audit & Security Logs', description: 'Inspect append-only system activity and security events' },
    { key: 'pricing', label: 'Price Modification', description: 'Change MRP and wholesale pricing structures' },
    { key: 'cost_read', label: 'Cost Price Visibility', description: 'View manufacturing/cost prices on products' },
    { key: 'identity_write', label: 'KYC / NID Management', description: 'View and edit sensitive NID credentials' },
];

export default function AccountCreate({ roles = ['dealer', 'sub_dealer'], sponsors = [] }: { roles: string[]; sponsors: Row[] }) {
    const { auth } = usePage<any>().props;

    const form = useForm({
        role: 'dealer',
        dealer_code: '',
        name: '',
        email: '',
        phone: '',
        division: 'Dhaka',
        district: '',
        latitude: '',
        longitude: '',
        directory_visible: '0',
        status: 'active',
        sponsor_id: '',
        nid: '',
        permissions: ['orders', 'inventory'] as string[],
        reason: 'Physical agreement verified and onboarding authorized by company staff.',
        profile: {
            name_bn: '',
            father_name: '',
            mother_name: '',
            village: '',
            post_office: '',
            upazila: '',
            union: '',
            pharmacy: '',
            area_code: '',
        },
    });

    const isPartner = ['dealer', 'sub_dealer'].includes(form.data.role);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform(data => {
            const {permissions, ...rest} = data;
            return ['employee', 'sr'].includes(data.role) ? {...rest, permissions} : rest;
        });
        form.post('/commands/accounts', {
            preserveScroll: true,
        });
    };

    return (
        <Shell section="accounts">
            <Head title="Add Partner Account" />
            <div className="section-header-row">
                <Link href="/company/accounts" className="text-link flex-center">
                    <ArrowLeft size={16} /> Back to Partner Accounts
                </Link>
            </div>

            <div className="registry-heading">
                <div>
                    <h2>Add Dealer / Sub-Dealer Account</h2>
                    <p>
                        Authorized staff onboarding. Enter the verified KYC, administrative territory, and manually assign the unique dealer code. A secure password-setting invitation will be emailed upon creation.
                    </p>
                </div>
            </div>

            <form onSubmit={submit} className="account-create-layout">
                <div className="account-create-main">
                    {/* Panel 1: Account Role & Identification */}
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>1. Account Role &amp; Unique Identification</h3>
                                <p>Staff manually assigns the unique code according to regional serials or contract records.</p>
                            </div>
                            <UserPlus size={22} className="text-muted" />
                        </div>

                        <div className="form-grid">
                            <label>
                                Account Type / Role *
                                <select
                                    value={form.data.role}
                                    onChange={(e) => {
                                        const newRole = e.target.value;
                                        form.setData((prev) => ({
                                            ...prev,
                                            role: newRole,
                                            permissions: newRole === 'sr' ? ['field_sales','sr_orders','sr_dues','sr_onboarding'] : ['orders','inventory'],
                                            directory_visible: newRole === 'dealer' ? prev.directory_visible : '0',
                                        }));
                                    }}
                                    required
                                >
                                    {roles.map((r) => (
                                        <option key={r} value={r}>
                                            {r === 'dealer' ? 'Dealer (Full margin & area privileges)' : r === 'sub_dealer' ? 'Sub-Dealer (40% discount baseline & flat 3% salary)' : label(r)}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.role && <span className="field-error">{form.errors.role}</span>}
                            </label>

                            <label>
                                Unique Dealer Code *
                                <input
                                    type="text"
                                    required={isPartner}
                                    placeholder="e.g. D-DHK-001 or SD-CTG-012"
                                    value={form.data.dealer_code}
                                    maxLength={40}
                                    onChange={(e) => form.setData('dealer_code', e.target.value.toUpperCase())}
                                />
                                <small className="muted">Manually assigned unique serial (never system-randomized).</small>
                                {form.errors.dealer_code && <span className="field-error">{form.errors.dealer_code}</span>}
                            </label>

                            <label>
                                Area / Zone Code
                                <input
                                    type="text"
                                    placeholder="e.g. ZONE-DHK-02"
                                    value={form.data.profile.area_code}
                                    maxLength={80}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, area_code: e.target.value })
                                    }
                                />
                                {form.errors['profile.area_code'] && (
                                    <span className="field-error">{form.errors['profile.area_code']}</span>
                                )}
                            </label>

                            <label>
                                Initial Account Status
                                <select
                                    value={form.data.status}
                                    onChange={(e) => form.setData('status', e.target.value)}
                                >
                                    <option value="active">Active (Permit login and product orders)</option>
                                    <option value="suspended">Suspended / Under Review (Locked)</option>
                                </select>
                                {form.errors.status && <span className="field-error">{form.errors.status}</span>}
                            </label>
                        </div>
                    </section>

                    {/* Panel: Employee Privileges & Permissions (When Role is Employee) */}
                    {['employee','sr'].includes(form.data.role) && (
                        <section className="panel" style={{ borderLeft: '4px solid #059669' }}>
                            <div className="panel-heading">
                                <div>
                                    <h3>Employee Privileges &amp; Permissions</h3>
                                    <p>Manually select which sections and features this employee is authorized to view and manage.</p>
                                </div>
                                <span style={{ fontSize: 11, fontWeight: 700, color: '#059669', background: '#ecfdf5', padding: '4px 8px', borderRadius: 4, border: '1px solid #a7f3d0' }}>
                                    {form.data.permissions.length} PERMISSIONS ACTIVE
                                </span>
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 10, marginTop: 12 }}>
                                {availablePermissions.filter(p=>form.data.role!=='sr'||['field_sales','sr_orders','sr_dues','sr_onboarding'].includes(p.key)).map(p => {
                                    const checked = form.data.permissions.includes(p.key);
                                    return (
                                        <label
                                            key={p.key}
                                            onClick={() => {
                                                const cur = form.data.permissions;
                                                form.setData('permissions', cur.includes(p.key) ? cur.filter(k => k !== p.key) : [...cur, p.key]);
                                            }}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'flex-start',
                                                gap: 10,
                                                padding: '10px 12px',
                                                borderRadius: 6,
                                                background: checked ? '#ecfdf5' : '#ffffff',
                                                border: checked ? '1px solid #10b981' : '1px solid #e2e8f0',
                                                cursor: 'pointer',
                                                userSelect: 'none'
                                            }}
                                        >
                                            <input type="checkbox" checked={checked} onChange={() => {}} style={{ marginTop: 3 }} />
                                            <div>
                                                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                    <b style={{ fontSize: 12, color: checked ? '#065f46' : '#1e293b' }}>{p.label}</b>
                                                    <code style={{ fontSize: 10, background: '#f1f5f9', padding: '1px 4px', borderRadius: 3, color: '#64748b' }}>{p.key}</code>
                                                </div>
                                                <small style={{ display: 'block', fontSize: 11, color: '#64748b', marginTop: 2 }}>{p.description}</small>
                                            </div>
                                        </label>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    {/* Panel 2: Personal & Family KYC */}
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>2. Personal &amp; Family KYC</h3>
                                <p>Legal name in English &amp; Bangla, family credentials, and restricted NID record.</p>
                            </div>
                            <Building2 size={22} className="text-muted" />
                        </div>

                        <div className="form-grid">
                            <label>
                                Full Name (English) *
                                <input
                                    type="text"
                                    required
                                    placeholder="e.g. Md. Rafiqul Islam"
                                    value={form.data.name}
                                    maxLength={150}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                />
                                {form.errors.name && <span className="field-error">{form.errors.name}</span>}
                            </label>

                            <label>
                                Full Name in Bangla (বাংলায় পুরো নাম)
                                <input
                                    type="text"
                                    placeholder="যেমন: মোঃ রফিকুল ইসলাম"
                                    value={form.data.profile.name_bn}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, name_bn: e.target.value })
                                    }
                                />
                                {form.errors['profile.name_bn'] && (
                                    <span className="field-error">{form.errors['profile.name_bn']}</span>
                                )}
                            </label>

                            <label>
                                Father's Name (পিতার নাম)
                                <input
                                    type="text"
                                    placeholder="Father's full legal name"
                                    value={form.data.profile.father_name}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, father_name: e.target.value })
                                    }
                                />
                                {form.errors['profile.father_name'] && (
                                    <span className="field-error">{form.errors['profile.father_name']}</span>
                                )}
                            </label>

                            <label>
                                Mother's Name (মাতার নাম)
                                <input
                                    type="text"
                                    placeholder="Mother's full legal name"
                                    value={form.data.profile.mother_name}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, mother_name: e.target.value })
                                    }
                                />
                                {form.errors['profile.mother_name'] && (
                                    <span className="field-error">{form.errors['profile.mother_name']}</span>
                                )}
                            </label>

                            <label>
                                Primary Mobile Number
                                <input
                                    type="tel"
                                    placeholder="01XXXXXXXXX"
                                    value={form.data.phone}
                                    maxLength={30}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                />
                                <small className="muted">Supports login via phone alongside email.</small>
                                {form.errors.phone && <span className="field-error">{form.errors.phone}</span>}
                            </label>

                            <label>
                                Email Address *
                                <input
                                    type="email"
                                    required
                                    placeholder="dealer@example.com"
                                    value={form.data.email}
                                    maxLength={254}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                />
                                <small className="muted">Password setup invitation is dispatched here.</small>
                                {form.errors.email && <span className="field-error">{form.errors.email}</span>}
                            </label>

                            <label className="span-2">
                                National Identity (NID) Number
                                <input
                                    type="text"
                                    placeholder="10, 13, or 17 digit NID number"
                                    value={form.data.nid}
                                    maxLength={30}
                                    onChange={(e) => form.setData('nid', e.target.value)}
                                />
                                <small className="muted">Restricted confidential data. Stored encrypted; hidden from public &amp; downline lists.</small>
                                {form.errors.nid && <span className="field-error">{form.errors.nid}</span>}
                            </label>
                        </div>
                    </section>

                    {/* Panel 3: Geographic Territory & Coordinates */}
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>3. Geographic Territory &amp; GPS Coordinates</h3>
                                <p>Administrative coverage hierarchy for territory allocation and public nearest-dealer search.</p>
                            </div>
                            <MapPin size={22} className="text-muted" />
                        </div>

                        <div className="form-grid">
                            <label>
                                Division (বিভাগ)
                                <select
                                    value={form.data.division}
                                    onChange={(e) => form.setData('division', e.target.value)}
                                >
                                    <option value="">Select Division…</option>
                                    {divisions.map((d) => (
                                        <option key={d} value={d}>
                                            {d}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.division && <span className="field-error">{form.errors.division}</span>}
                            </label>

                            <label>
                                District (জেলা)
                                <input
                                    type="text"
                                    placeholder="e.g. Gazipur, Bogura, Cumilla"
                                    value={form.data.district}
                                    maxLength={80}
                                    onChange={(e) => form.setData('district', e.target.value)}
                                />
                                {form.errors.district && <span className="field-error">{form.errors.district}</span>}
                            </label>

                            <label>
                                Upazila / Thana (উপজেলা / থানা)
                                <input
                                    type="text"
                                    placeholder="e.g. Kaliakair, Sadar"
                                    value={form.data.profile.upazila}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, upazila: e.target.value })
                                    }
                                />
                                {form.errors['profile.upazila'] && (
                                    <span className="field-error">{form.errors['profile.upazila']}</span>
                                )}
                            </label>

                            <label>
                                Union / Ward (ইউনিয়ন / ওয়ার্ড)
                                <input
                                    type="text"
                                    placeholder="Assigned Union coverage"
                                    value={form.data.profile.union}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, union: e.target.value })
                                    }
                                />
                                {form.errors['profile.union'] && (
                                    <span className="field-error">{form.errors['profile.union']}</span>
                                )}
                            </label>

                            <label>
                                Village / Road / Street (গ্রাম / রাস্তা)
                                <input
                                    type="text"
                                    placeholder="Village or street address"
                                    value={form.data.profile.village}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, village: e.target.value })
                                    }
                                />
                                {form.errors['profile.village'] && (
                                    <span className="field-error">{form.errors['profile.village']}</span>
                                )}
                            </label>

                            <label>
                                Post Office (ডাকঘর)
                                <input
                                    type="text"
                                    placeholder="e.g. Kaliakair - 1750"
                                    value={form.data.profile.post_office}
                                    maxLength={150}
                                    onChange={(e) =>
                                        form.setData('profile', { ...form.data.profile, post_office: e.target.value })
                                    }
                                />
                                {form.errors['profile.post_office'] && (
                                    <span className="field-error">{form.errors['profile.post_office']}</span>
                                )}
                            </label>

                            <label>
                                GPS Latitude
                                <input
                                    type="number"
                                    step="0.0000001"
                                    placeholder="e.g. 23.8103310"
                                    value={form.data.latitude}
                                    onChange={(e) => form.setData('latitude', e.target.value)}
                                />
                                {form.errors.latitude && <span className="field-error">{form.errors.latitude}</span>}
                            </label>

                            <label>
                                GPS Longitude
                                <input
                                    type="number"
                                    step="0.0000001"
                                    placeholder="e.g. 90.4125210"
                                    value={form.data.longitude}
                                    onChange={(e) => form.setData('longitude', e.target.value)}
                                />
                                {form.errors.longitude && <span className="field-error">{form.errors.longitude}</span>}
                            </label>
                        </div>
                    </section>
                </div>

                <div className="account-create-sidebar">
                    {/* Business Establishment */}
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>4. Business Point &amp; Directory</h3>
                                <p>Medical chamber / pharmacy information.</p>
                            </div>
                            <Store size={20} className="text-muted" />
                        </div>

                        <label>
                            Pharmacy / Chamber Name
                            <input
                                type="text"
                                placeholder="e.g. Madina Natural Care Point"
                                value={form.data.profile.pharmacy}
                                maxLength={150}
                                onChange={(e) =>
                                    form.setData('profile', { ...form.data.profile, pharmacy: e.target.value })
                                }
                            />
                            {form.errors['profile.pharmacy'] && (
                                <span className="field-error">{form.errors['profile.pharmacy']}</span>
                            )}
                        </label>

                        {form.data.role === 'dealer' && (
                            <label className="checkbox-label" style={{ marginTop: '0.8rem' }}>
                                <input
                                    type="checkbox"
                                    checked={form.data.directory_visible === '1'}
                                    onChange={(e) =>
                                        form.setData('directory_visible', e.target.checked ? '1' : '0')
                                    }
                                />
                                <span>Publish in public dealer directory (Name, business phone &amp; district)</span>
                            </label>
                        )}
                        {form.data.role === 'sub_dealer' && (
                            <p className="muted" style={{ marginTop: '0.5rem', fontSize: '0.85rem' }}>
                                Sub-dealer contacts remain confidential and are excluded from the public directory per client policy.
                            </p>
                        )}
                    </section>

                    {/* Sponsoring Dealer */}
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>5. Sponsor Relationship</h3>
                                <p>Required for sub-dealer parent link and D2D reward tracking.</p>
                            </div>
                            <BadgeCheck size={20} className="text-muted" />
                        </div>

                        <label>
                            Sponsoring Dealer
                            <select
                                value={form.data.sponsor_id}
                                onChange={(e) => form.setData('sponsor_id', e.target.value)}
                            >
                                <option value="">No sponsor (Direct company placement)</option>
                                {sponsors.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name} ({s.dealer_code ?? 'No code'}) — {s.district ?? 'BD'}
                                    </option>
                                ))}
                            </select>
                            {form.errors.sponsor_id && (
                                <span className="field-error">{form.errors.sponsor_id}</span>
                            )}
                        </label>
                    </section>

                    {/* Staff Verification Note & Actions */}
                    <section className="panel">
                        <div className="panel-heading">
                            <div>
                                <h3>6. Staff Audit Record</h3>
                                <p>Immutable log entry for this account creation.</p>
                            </div>
                            <ShieldAlert size={20} className="text-muted" />
                        </div>

                        <label>
                            Staff Verification / Reason Note *
                            <textarea
                                rows={3}
                                required
                                maxLength={1000}
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                            />
                            {form.errors.reason && <span className="field-error">{form.errors.reason}</span>}
                        </label>

                        {Object.keys(form.errors).length > 0 && (
                            <div className="error" role="alert" style={{ marginTop: '0.8rem' }}>
                                Please correct the highlighted errors above.
                            </div>
                        )}

                        <button
                            type="submit"
                            className="button dark full-width"
                            disabled={form.processing}
                            style={{ marginTop: '1rem', justifyContent: 'center' }}
                        >
                            {form.processing ? 'Creating Account…' : 'Create Account & Email Invitation'}
                        </button>
                    </section>
                </div>
            </form>
        </Shell>
    );
}
