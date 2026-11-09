import React, { useState } from 'react';
import { router, useForm, usePage, Link } from '@inertiajs/react';
import {
    Users, ShieldCheck, UserPlus, CheckSquare, Square, Search,
    Lock, Mail, Phone, Key, AlertCircle, Sparkles, Filter, CheckCircle2,
    XCircle, Clock, ArrowRight, ShieldAlert, Award
} from 'lucide-react';
import { Table, Pagination, Badge, Empty, date, label, type Row, number } from './UI';
import AccountPasswordManager from './AccountPasswordManager';
import OfficePrescriberGrants from './OfficePrescriberGrants';
import { useT } from '../shared/i18n/t';

export interface PermissionDefinition {
    key: string;
    label: string;
    description: string;
    category: string;
}

export interface EmployeeHubProps {
    employees: {
        data: Row[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    availablePermissions: PermissionDefinition[];
    stats: {
        total: number;
        active: number;
        suspended: number;
    };
    filters: Row;
}

export default function EmployeesHub({ employees, availablePermissions, stats, filters }: EmployeeHubProps) {
    const translate = useT();
    const {auth} = usePage<any>().props;
    const canManageStaff = auth.user.role === 'super_admin';
    const isOwner = ['super_admin', 'md', 'chairman'].includes(auth.user.role);
    const caps: string[] = auth.capabilities ?? [];
    // Mirrors PasswordAccess on the server.
    const canResetPasswordOf = (employee: Row): boolean => employee.role !== 'super_admin' && employee.id !== auth.user.id && (canManageStaff || (!['md', 'chairman'].includes(String(employee.role)) && (isOwner || caps.includes('staff_admin'))));
    const [search, setSearch] = useState(filters.q || '');
    const [createModalOpen, setCreateModalOpen] = useState(false);
    const [editModalEmployee, setEditModalEmployee] = useState<Row | null>(null);

    // Create Employee Form
    const createForm = useForm({
        role: 'employee',
        name: '',
        email: '',
        phone: '',
        status: 'active',
        reason: translate('portal', 'emp_reason_onboard'),
        permissions: ['orders', 'inventory'] as string[],
    });

    // Edit Employee Form
    const editForm = useForm<{
        name: string;
        phone: string;
        status: string;
        reason: string;
        permissions: string[];
    }>({
        name: '',
        phone: '',
        status: 'active',
        reason: '',
        permissions: [],
    });

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/employees', { q: search }, { preserveState: true });
    };

    // Mirrors the server: only an Employee or SR without their own access settings is edited with these boxes.
    const usesPermissionBoxes = (employee: Row): boolean => ['employee', 'sr'].includes(String(employee.role)) && !employee.profile?.action_permissions;

    const handleOpenEdit = (employee: Row) => {
        setEditModalEmployee(employee);
        const currentPermissions: string[] = Array.isArray(employee.profile?.permissions)
            ? employee.profile.permissions
            : employee.role === 'sr' ? ['field_sales','sr_orders','sr_onboarding'] : [];
        editForm.setData({
            name: employee.name || '',
            phone: employee.phone || '',
            status: employee.status || 'active',
            reason: translate('portal', 'emp_reason_update'),
            permissions: [...currentPermissions],
        });
    };

    const toggleCreatePermission = (key: string) => {
        const current = createForm.data.permissions;
        if (current.includes(key)) {
            createForm.setData('permissions', current.filter(k => k !== key));
        } else {
            createForm.setData('permissions', [...current, key]);
        }
    };

    const toggleEditPermission = (key: string) => {
        const current = editForm.data.permissions;
        if (current.includes(key)) {
            editForm.setData('permissions', current.filter(k => k !== key));
        } else {
            editForm.setData('permissions', [...current, key]);
        }
    };

    const applyCreatePreset = (keys: string[]) => {
        createForm.setData('permissions', [...keys]);
    };

    const applyEditPreset = (keys: string[]) => {
        editForm.setData('permissions', editModalEmployee?.role === 'sr' ? keys.filter(key=>['field_sales','sr_orders','sr_onboarding'].includes(key)) : [...keys]);
    };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.post('/commands/accounts', {
            onSuccess: () => {
                setCreateModalOpen(false);
                createForm.reset();
            },
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editModalEmployee) return;
        const sendsPermissions = usesPermissionBoxes(editModalEmployee);
        // Someone added before phone numbers were needed can still be renamed or disabled.
        const keepsNoPhone = !editModalEmployee.phone;
        editForm.transform(data => {
            const {permissions, phone, ...details} = data;
            return {...details, ...(keepsNoPhone && phone.trim() === '' ? {} : {phone}), ...(sendsPermissions ? {permissions} : {})} as typeof data;
        });
        editForm.patch(`/commands/accounts/${editModalEmployee.id}`, {
            onSuccess: () => {
                setEditModalEmployee(null);
            },
        });
    };

    // Group available permissions by category
    const categories = Array.from(new Set(availablePermissions.map(p => p.category)));

    return (
        <div className="employees-hub">
            {/* Header info */}
            <div className="panel emp-intro">
                <div className="hub-row is-between is-top">
                    <div>
                        <div className="emp-kicker">
                            <ShieldCheck size={16} className="icon-ok" />
                            {translate('portal', 'emp_badge')}
                        </div>
                        <h2 className="emp-heading">
                            {translate('portal', 'emp_heading')}
                        </h2>
                        <p className="emp-lede">
                            {translate('portal', 'emp_heading_hint')}
                        </p>
                    </div>

                    <button
                        type="button"
                        className="button dark"
                        disabled={!canManageStaff}
                        onClick={() => setCreateModalOpen(true)}
                    >
                        <UserPlus size={18} />
                        {translate('portal', 'emp_add')}
                    </button>
                </div>
            </div>

            {/* Metrics cards */}
            <div className="metric-grid hub-grid ops-mb-6">
                <div className="metric emp-kpi">
                    <span>{translate('portal', 'emp_kpi_total')}</span>
                    <strong>
                        {number(stats.total)}
                    </strong>
                    <small className="ops-faint">{translate('portal', 'emp_kpi_total_hint')}</small>
                </div>

                <div className="metric emp-kpi tone-ok">
                    <span>{translate('portal', 'emp_kpi_active')}</span>
                    <strong>
                        {stats.active}
                    </strong>
                    <small className="ops-faint">{translate('portal', 'emp_kpi_active_hint')}</small>
                </div>

                <div className="metric emp-kpi tone-danger">
                    <span>{translate('portal', 'emp_kpi_suspended')}</span>
                    <strong>
                        {stats.suspended}
                    </strong>
                    <small className="ops-faint">{translate('portal', 'emp_kpi_suspended_hint')}</small>
                </div>

                <div className="metric emp-kpi tone-info">
                    <span>{translate('portal', 'emp_kpi_perms')}</span>
                    <strong>
                        {number(availablePermissions.length)}
                    </strong>
                    <small className="ops-faint">{translate('portal', 'emp_kpi_perms_hint')}</small>
                </div>
            </div>

            {/* Employee Directory Panel */}
            <section className="panel table-panel">
                <div className="hub-row is-between ops-mb-4">
                    <div>
                        <h3 className="hub-title">{translate('portal', 'emp_directory_title')}</h3>
                        <p className="hub-sub">
                            {translate('portal', 'emp_directory_hint')}
                        </p>
                    </div>

                    <form className="filter-bar ops-m-0" onSubmit={handleSearch}>
                        <div className="search-input">
                            <Search size={16} />
                            <input
                                placeholder={translate('portal', 'emp_search_ph')}
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                            />
                        </div>
                        <button className="button small">{translate('portal', 'emp_search')}</button>
                        {filters.q && (
                            <Link href="/employees" className="text-link">{translate('portal', 'clear')}</Link>
                        )}
                    </form>
                </div>

                <Table
                    rows={employees.data}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'emp_col_employee'),
                            render: r => (
                                <div>
                                    <b className="ops-block">{r.name}</b>
                                    <div className="ops-row cell-sub">
                                        <span>{r.email}</span>
                                        {r.phone && <span>· {r.phone}</span>}
                                    </div>
                                </div>
                            )
                        },
                        {
                            key: 'status',
                            title: translate('portal', 'col_status'),
                            render: r => <Badge status={r.status} />
                        },
                        {
                            key: 'role',
                            title: translate('portal', 'col_role'),
                            render: (r: Row) => r.role ? (r.role_name || label(String(r.role), translate)) : '—',
                        },
                        {
                            key: 'branch',
                            title: translate('portal', 'col_branch'),
                            render: (r: Row) => r.branch_name || '—',
                        },
                        {
                            key: 'permissions',
                            title: translate('portal', 'emp_col_capabilities'),
                            render: r => {
                                const perms: string[] = r.effective_permissions ?? [];
                                if (!perms.length) {
                                    return <span className="emp-none">{translate('portal', 'emp_no_perms')}</span>;
                                }
                                return (
                                    <div className="ops-row is-wrap is-tight emp-perms">
                                        {perms.map(p => {
                                            const info = availablePermissions.find(ap => ap.key === p);
                                            const permKey = `perm_${p}`;
                                            const permName = translate('portal', permKey);
                                            const name = info?.label || (permName !== permKey ? permName : label(p, translate));
                                            return (
                                                <span
                                                    key={p}
                                                    title={info?.description || name}
                                                    className="emp-perm"
                                                >
                                                    {name}
                                                </span>
                                            );
                                        })}
                                    </div>
                                );
                            }
                        },
                        {
                            key: 'created_at',
                            title: translate('portal', 'emp_col_onboarded'),
                            render: r => <small className="ops-muted">{date(r.created_at)}</small>
                        },
                        {
                            key: 'actions',
                            title: translate('portal', 'emp_col_actions'),
                            render: r => (
                                <div className="inline-actions ops-row is-loose">
                                    <button
                                        type="button"
                                        className="button small dark"
                                        disabled={!canManageStaff}
                                        onClick={() => handleOpenEdit(r)}
                                    >
                                        {translate('portal', 'emp_edit_details')}
                                    </button>
                                    <button
                                        type="button"
                                        className="text-link ops-fs-2xs"
                                        disabled={!canManageStaff || r.status !== 'active'}
                                        onClick={() => router.post(`/commands/accounts/${r.id}/invite`)}
                                    >
                                        {translate('portal', 'emp_send_invite')}
                                    </button>
                                </div>
                            )
                        }
                    ]}
                />

                {!employees.data.length && (
                    <Empty
                        title={translate('portal', 'emp_empty_title')}
                        body={translate('portal', 'emp_empty_body')}
                    />
                )}

                <Pagination page={employees} />
            </section>

            {Boolean(auth?.prescriberVerifier) && <OfficePrescriberGrants />}

            {/* CREATE EMPLOYEE MODAL */}
            {createModalOpen && (
                <div className="modal-overlay hub-overlay emp-overlay">
                    <div className="hub-dialog emp-dialog">
                        <div className="ops-row is-between emp-dialog-head">
                            <div>
                                <h3 className="hub-title is-lg">
                                    {translate('portal', 'emp_create_title')}
                                </h3>
                                <p className="hub-sub">
                                    {translate('portal', 'emp_create_hint')}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setCreateModalOpen(false)}
                                className="ops-plain-button ops-faint emp-close"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={submitCreate} className="action-form">
                            {/* Employee basic info */}
                            <div className="form-grid hub-grid">
                                <label>
                                    {translate('portal', 'emp_fld_full_name')}
                                    <input
                                        required
                                        placeholder={translate('portal', 'emp_ph_full_name')}
                                        value={createForm.data.name}
                                        onChange={e => createForm.setData('name', e.target.value)}
                                    />
                                    {createForm.errors.name && <span className="field-error">{createForm.errors.name}</span>}
                                </label>

                                <label>
                                    {translate('portal', 'emp_fld_email')}
                                    <input
                                        required
                                        type="email"
                                        placeholder={translate('portal', 'emp_ph_email')}
                                        value={createForm.data.email}
                                        onChange={e => createForm.setData('email', e.target.value)}
                                    />
                                    {createForm.errors.email && <span className="field-error">{createForm.errors.email}</span>}
                                </label>

                                <label>
                                    {translate('portal', 'emp_fld_phone_login')}
                                    <input
                                        required
                                        placeholder={translate('portal', 'emp_ph_phone')}
                                        value={createForm.data.phone}
                                        onChange={e => createForm.setData('phone', e.target.value)}
                                    />
                                    {createForm.errors.phone && <span className="field-error">{createForm.errors.phone}</span>}
                                </label>

                                <label>
                                    {translate('portal', 'emp_fld_initial_status')}
                                    <select
                                        value={createForm.data.status}
                                        onChange={e => createForm.setData('status', e.target.value)}
                                    >
                                        <option value="active">{translate('portal', 'emp_opt_active_login')}</option>
                                        <option value="suspended">{translate('portal', 'emp_opt_suspended_draft')}</option>
                                    </select>
                                </label>
                            </div>

                            {/* Preset Buttons */}
                            <div className="ops-mt-5 ops-mb-3">
                                <div className="ops-row is-between ops-mb-2">
                                    <b className="ops-fs-sm ops-text">
                                        {translate('portal', 'emp_perms_manual')}
                                    </b>
                                    <span className="ops-fs-2xs ops-semibold tone-ok">
                                        {translate('portal', 'emp_caps_selected', { count: String(createForm.data.permissions.length) })}
                                    </span>
                                </div>

                                <div className="ops-row is-wrap is-snug ops-mb-4">
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyCreatePreset(availablePermissions.map(p => p.key))}
                                    >
                                        {translate('portal', 'emp_preset_all')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyCreatePreset(['orders', 'order_review', 'reports'])}
                                    >
                                        {translate('portal', 'emp_preset_orders')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyCreatePreset(['inventory', 'catalog'])}
                                    >
                                        {translate('portal', 'emp_preset_warehouse')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyCreatePreset(['accounts'])}
                                    >
                                        {translate('portal', 'emp_preset_dealer')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyCreatePreset(['finance', 'cost_read', 'reports'])}
                                    >
                                        {translate('portal', 'emp_preset_finance')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyCreatePreset([])}
                                    >
                                        {translate('portal', 'emp_preset_clear')}
                                    </button>
                                </div>
                            </div>

                            {/* Checkbox Groups by Category */}
                            <div className="ops-stack">
                                {categories.map(cat => (
                                    <div key={cat} className="ops-card is-soft is-tight">
                                        <div className="emp-cat-title">
                                            {translate('portal', 'emp_cat_modules', { cat: String(cat) })}
                                        </div>
                                        <div className="hub-grid is-wide emp-option-grid">
                                            {availablePermissions.filter(p => p.category === cat).map(p => {
                                                const checked = createForm.data.permissions.includes(p.key);
                                                return (
                                                    <label
                                                        key={p.key}
                                                        onClick={() => toggleCreatePermission(p.key)}
                                                        className={`emp-option${checked ? ' is-checked' : ''}`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={() => {}}
                                                            className="ops-mt-1"
                                                        />
                                                        <div>
                                                            <div className="ops-row is-snug">
                                                                <b className="emp-option-title">{p.label}</b>
                                                            </div>
                                                            <small className="ops-block ops-meta emp-option-hint">
                                                                {p.description}
                                                            </small>
                                                        </div>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <label className="ops-mt-3">
                                {translate('portal', 'emp_fld_onboard_reason')}
                                <textarea
                                    required
                                    rows={2}
                                    value={createForm.data.reason}
                                    onChange={e => createForm.setData('reason', e.target.value)}
                                />
                            </label>

                            <div className="ops-row is-end is-loose emp-dialog-foot">
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setCreateModalOpen(false)}
                                >
                                    {translate('portal', 'emp_cancel')}
                                </button>
                                <button
                                    type="submit"
                                    className="button dark"
                                    disabled={createForm.processing}
                                >
                                    {createForm.processing ? translate('portal', 'emp_create_submitting') : translate('portal', 'emp_create_submit')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* EDIT EMPLOYEE PERMISSIONS MODAL */}
            {editModalEmployee && (
                <div className="modal-overlay hub-overlay emp-overlay">
                    <div className="hub-dialog emp-dialog">
                        <div className="ops-row is-between emp-dialog-head">
                            <div>
                                <h3 className="hub-title is-lg">
                                    {translate('portal', 'emp_edit_title', { name: editModalEmployee.name })}
                                </h3>
                                <p className="hub-sub">
                                    {translate('portal', 'emp_edit_hint', { email: editModalEmployee.email })}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setEditModalEmployee(null)}
                                className="ops-plain-button ops-faint emp-close"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={submitEdit} className="action-form">
                            <div className="form-grid hub-grid">
                                <label>
                                    {translate('portal', 'emp_fld_name')}
                                    <input
                                        required
                                        value={editForm.data.name}
                                        onChange={e => editForm.setData('name', e.target.value)}
                                    />
                                </label>

                                <label>
                                    {translate('portal', 'emp_fld_phone')}
                                    <input
                                        value={editForm.data.phone}
                                        onChange={e => editForm.setData('phone', e.target.value)}
                                    />
                                </label>

                                <label>
                                    {translate('portal', 'emp_fld_status')}
                                    <select
                                        value={editForm.data.status}
                                        onChange={e => editForm.setData('status', e.target.value)}
                                    >
                                        <option value="active">{translate('portal', 'emp_opt_active_access')}</option>
                                        <option value="suspended">{translate('portal', 'emp_opt_suspended_lock')}</option>
                                    </select>
                                </label>
                            </div>

                            {!usesPermissionBoxes(editModalEmployee) ? <p className="notice">{translate('portal', 'emp_action_perms_notice')} <Link href="/operations/access-management" className="text-link">{translate('portal', 'emp_manage_roles')}</Link></p> : <>
                            {/* Preset Buttons */}
                            <div className="ops-mt-5 ops-mb-3">
                                <div className="ops-row is-between ops-mb-2">
                                    <b className="ops-fs-sm ops-text">
                                        {translate('portal', 'emp_perms_select')}
                                    </b>
                                    <span className="ops-fs-2xs ops-semibold tone-ok">
                                        {translate('portal', 'emp_caps_selected', { count: String(editForm.data.permissions.length) })}
                                    </span>
                                </div>

                                <div className="ops-row is-wrap is-snug ops-mb-4">
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyEditPreset(availablePermissions.map(p => p.key))}
                                    >
                                        {translate('portal', 'emp_preset_all')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyEditPreset(['orders', 'order_review', 'reports'])}
                                    >
                                        {translate('portal', 'emp_preset_orders')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyEditPreset(['inventory', 'catalog'])}
                                    >
                                        {translate('portal', 'emp_preset_warehouse')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyEditPreset(['finance', 'cost_read', 'reports'])}
                                    >
                                        {translate('portal', 'emp_preset_finance')}
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={() => applyEditPreset([])}
                                    >
                                        {translate('portal', 'emp_preset_clear')}
                                    </button>
                                </div>
                            </div>

                            {/* Checkbox Groups by Category */}
                            <div className="ops-stack">
                                {categories.map(cat => (
                                    <div key={cat} className="ops-card is-soft is-tight">
                                        <div className="emp-cat-title">
                                            {translate('portal', 'emp_cat_modules', { cat: String(cat) })}
                                        </div>
                                        <div className="hub-grid is-wide emp-option-grid">
                                            {availablePermissions.filter(p => p.category === cat && (editModalEmployee?.role !== 'sr' || ['field_sales','sr_orders','sr_onboarding'].includes(p.key))).map(p => {
                                                const checked = editForm.data.permissions.includes(p.key);
                                                return (
                                                    <label
                                                        key={p.key}
                                                        onClick={() => toggleEditPermission(p.key)}
                                                        className={`emp-option${checked ? ' is-checked' : ''}`}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={() => {}}
                                                            className="ops-mt-1"
                                                        />
                                                        <div>
                                                            <div className="ops-row is-snug">
                                                                <b className="emp-option-title">{p.label}</b>
                                                            </div>
                                                            <small className="ops-block ops-meta emp-option-hint">
                                                                {p.description}
                                                            </small>
                                                        </div>
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>

                            </>}

                            <label className="ops-mt-3">
                                {translate('portal', 'emp_fld_correction_reason')}
                                <textarea
                                    required
                                    rows={2}
                                    placeholder={translate('portal', 'emp_ph_correction_reason')}
                                    value={editForm.data.reason}
                                    onChange={e => editForm.setData('reason', e.target.value)}
                                />
                            </label>

                            <div className="ops-row is-end is-loose emp-dialog-foot">
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setEditModalEmployee(null)}
                                >
                                    {translate('portal', 'emp_cancel')}
                                </button>
                                <button
                                    type="submit"
                                    className="button dark"
                                    disabled={editForm.processing}
                                >
                                    {editForm.processing ? translate('portal', 'emp_saving') : translate('portal', 'emp_save')}
                                </button>
                            </div>
                        </form>

                        {canResetPasswordOf(editModalEmployee) && (
                            <AccountPasswordManager
                                account={editModalEmployee}
                                canManage
                            />
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
