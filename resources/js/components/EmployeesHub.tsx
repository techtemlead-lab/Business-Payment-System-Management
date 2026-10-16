import React, { useState } from 'react';
import { router, useForm, Link } from '@inertiajs/react';
import {
    Users, ShieldCheck, UserPlus, CheckSquare, Square, Search,
    Lock, Mail, Phone, Key, AlertCircle, Sparkles, Filter, CheckCircle2,
    XCircle, Clock, ArrowRight, ShieldAlert, Award
} from 'lucide-react';
import { Table, Pagination, Badge, Empty, date, type Row } from './UI';

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
        reason: 'Authorized company employee onboarded from company dashboard with custom permissions.',
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
        router.get('/company/employees', { q: search }, { preserveState: true });
    };

    const handleOpenEdit = (employee: Row) => {
        setEditModalEmployee(employee);
        const currentPermissions: string[] = Array.isArray(employee.profile?.permissions)
            ? employee.profile.permissions
            : employee.role === 'sr' ? ['field_sales','sr_orders','sr_dues','sr_onboarding'] : [];
        editForm.setData({
            name: employee.name || '',
            phone: employee.phone || '',
            status: employee.status || 'active',
            reason: 'Updating employee permissions and privileges.',
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
        editForm.setData('permissions', editModalEmployee?.role === 'sr' ? keys.filter(key=>['field_sales','sr_orders','sr_dues','sr_onboarding'].includes(key)) : [...keys]);
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
            <div className="panel" style={{ marginBottom: 20, borderLeft: '4px solid #011627' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 16 }}>
                    <div>
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 700, color: '#011627', textTransform: 'uppercase', marginBottom: 4 }}>
                            <ShieldCheck size={16} color="#059669" />
                            STAFF ACCESS &amp; PERMISSION CONTROL
                        </div>
                        <h2 style={{ margin: '0 0 6px 0', fontSize: 22, fontWeight: 800, color: '#0f172a' }}>
                            Company Employee Dashboard &amp; Role Delegation
                        </h2>
                        <p style={{ margin: 0, color: '#64748b', fontSize: 13, maxWidth: 760 }}>
                            Super Admin can create employee accounts and manually select which capabilities to grant.
                            Employees logging into the company portal will have their visible sections, actions, and features strictly limited to their manually assigned permissions.
                        </p>
                    </div>

                    <button
                        type="button"
                        className="button dark"
                        onClick={() => setCreateModalOpen(true)}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '10px 18px', fontWeight: 700 }}
                    >
                        <UserPlus size={18} />
                        Add New Employee
                    </button>
                </div>
            </div>

            {/* Metrics cards */}
            <div className="metric-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))', gap: 16, marginBottom: 24 }}>
                <div className="metric" style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '18px 20px' }}>
                    <span style={{ fontSize: 12, fontWeight: 600, color: '#64748b' }}>Total Staff / Employees</span>
                    <strong style={{ fontSize: 28, fontWeight: 800, color: '#011627', display: 'block', marginTop: 4 }}>
                        {stats.total}
                    </strong>
                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Accounts with company portal access</small>
                </div>

                <div className="metric" style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '18px 20px' }}>
                    <span style={{ fontSize: 12, fontWeight: 600, color: '#059669' }}>Active Employees</span>
                    <strong style={{ fontSize: 28, fontWeight: 800, color: '#059669', display: 'block', marginTop: 4 }}>
                        {stats.active}
                    </strong>
                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Can log in with granted permissions</small>
                </div>

                <div className="metric" style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '18px 20px' }}>
                    <span style={{ fontSize: 12, fontWeight: 600, color: '#e11d48' }}>Suspended Accounts</span>
                    <strong style={{ fontSize: 28, fontWeight: 800, color: '#e11d48', display: 'block', marginTop: 4 }}>
                        {stats.suspended}
                    </strong>
                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Access blocked immediately</small>
                </div>

                <div className="metric" style={{ background: '#ffffff', border: '1px solid #e2e8f0', borderRadius: 10, padding: '18px 20px' }}>
                    <span style={{ fontSize: 12, fontWeight: 600, color: '#2563eb' }}>Configurable Permissions</span>
                    <strong style={{ fontSize: 28, fontWeight: 800, color: '#2563eb', display: 'block', marginTop: 4 }}>
                        {availablePermissions.length}
                    </strong>
                    <small style={{ color: '#94a3b8', fontSize: 11 }}>Granular feature isolation modules</small>
                </div>
            </div>

            {/* Employee Directory Panel */}
            <section className="panel table-panel">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 12, marginBottom: 16 }}>
                    <div>
                        <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>Employee Directory &amp; Assigned Capabilities</h3>
                        <p style={{ margin: '2px 0 0 0', fontSize: 12, color: '#64748b' }}>
                            Click "Edit Permissions" on any employee to modify their access capabilities at any time.
                        </p>
                    </div>

                    <form className="filter-bar" onSubmit={handleSearch} style={{ margin: 0 }}>
                        <div className="search-input">
                            <Search size={16} />
                            <input
                                placeholder="Search employees by name, email, phone…"
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                            />
                        </div>
                        <button className="button small">Search</button>
                        {filters.q && (
                            <Link href="/company/employees" className="text-link">Clear</Link>
                        )}
                    </form>
                </div>

                <Table
                    rows={employees.data}
                    columns={[
                        {
                            key: 'name',
                            title: 'Employee',
                            render: r => (
                                <div>
                                    <b style={{ color: '#0f172a', display: 'block' }}>{r.name}</b>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                        <span>{r.email}</span>
                                        {r.phone && <span>· {r.phone}</span>}
                                    </div>
                                </div>
                            )
                        },
                        {
                            key: 'status',
                            title: 'Status',
                            render: r => <Badge status={r.status} />
                        },
                        {
                            key: 'permissions',
                            title: 'Granted Capabilities',
                            render: r => {
                                const perms: string[] = Array.isArray(r.profile?.permissions) ? r.profile.permissions : r.role === 'sr' ? ['field_sales','sr_orders','sr_dues','sr_onboarding'] : [];
                                if (!perms.length) {
                                    return <span style={{ fontSize: 11, color: '#94a3b8', fontStyle: 'italic' }}>No permissions granted (Restricted)</span>;
                                }
                                return (
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, maxWidth: 380 }}>
                                        {perms.map(p => {
                                            const info = availablePermissions.find(ap => ap.key === p);
                                            return (
                                                <span
                                                    key={p}
                                                    title={info?.description || p}
                                                    style={{
                                                        display: 'inline-flex',
                                                        alignItems: 'center',
                                                        fontSize: 10,
                                                        fontWeight: 700,
                                                        padding: '2px 6px',
                                                        borderRadius: 4,
                                                        background: '#f1f5f9',
                                                        color: '#334155',
                                                        border: '1px solid #cbd5e1'
                                                    }}
                                                >
                                                    {info?.label || p}
                                                </span>
                                            );
                                        })}
                                    </div>
                                );
                            }
                        },
                        {
                            key: 'created_at',
                            title: 'Onboarded',
                            render: r => <small style={{ color: '#64748b' }}>{date(r.created_at)}</small>
                        },
                        {
                            key: 'actions',
                            title: 'Management Actions',
                            render: r => (
                                <div className="inline-actions" style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11, padding: '4px 10px', background: '#011627', color: '#fff' }}
                                        onClick={() => handleOpenEdit(r)}
                                    >
                                        Edit Permissions
                                    </button>
                                    <button
                                        type="button"
                                        className="text-link"
                                        style={{ fontSize: 11 }}
                                        onClick={() => router.post(`/commands/accounts/${r.id}/invite`)}
                                    >
                                        Send Invite
                                    </button>
                                </div>
                            )
                        }
                    ]}
                />

                {!employees.data.length && (
                    <Empty
                        title="No employee accounts found"
                        body="Click '+ Add New Employee' above to create an employee account and grant permissions manually."
                    />
                )}

                <Pagination page={employees} />
            </section>

            {/* CREATE EMPLOYEE MODAL */}
            {createModalOpen && (
                <div className="modal-overlay" style={{
                    position: 'fixed',
                    top: 0, left: 0, right: 0, bottom: 0,
                    backgroundColor: 'rgba(15, 23, 42, 0.65)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 9999,
                    padding: 16
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: 12,
                        width: '100%',
                        maxWidth: 720,
                        maxHeight: '90vh',
                        overflowY: 'auto',
                        boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1)',
                        padding: '24px 28px'
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, borderBottom: '1px solid #e2e8f0', paddingBottom: 12 }}>
                            <div>
                                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                                    Create New Employee Dashboard Account
                                </h3>
                                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                    Super Admin selects exactly which capabilities the employee can see and access.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setCreateModalOpen(false)}
                                style={{ background: 'none', border: 'none', fontSize: 20, cursor: 'pointer', color: '#94a3b8' }}
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={submitCreate} className="action-form">
                            {/* Employee basic info */}
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                                <label>
                                    Employee Full Name *
                                    <input
                                        required
                                        placeholder="e.g. Tariqul Islam"
                                        value={createForm.data.name}
                                        onChange={e => createForm.setData('name', e.target.value)}
                                    />
                                    {createForm.errors.name && <span className="field-error">{createForm.errors.name}</span>}
                                </label>

                                <label>
                                    Company Email Address *
                                    <input
                                        required
                                        type="email"
                                        placeholder="e.g. tariqul@madina.com"
                                        value={createForm.data.email}
                                        onChange={e => createForm.setData('email', e.target.value)}
                                    />
                                    {createForm.errors.email && <span className="field-error">{createForm.errors.email}</span>}
                                </label>

                                <label>
                                    Phone Number (For Login)
                                    <input
                                        placeholder="e.g. 01712345678"
                                        value={createForm.data.phone}
                                        onChange={e => createForm.setData('phone', e.target.value)}
                                    />
                                    {createForm.errors.phone && <span className="field-error">{createForm.errors.phone}</span>}
                                </label>

                                <label>
                                    Initial Status
                                    <select
                                        value={createForm.data.status}
                                        onChange={e => createForm.setData('status', e.target.value)}
                                    >
                                        <option value="active">Active (Can log in immediately)</option>
                                        <option value="suspended">Suspended / Draft (Locked)</option>
                                    </select>
                                </label>
                            </div>

                            {/* Preset Buttons */}
                            <div style={{ marginTop: 20, marginBottom: 12 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                    <b style={{ fontSize: 13, color: '#0f172a' }}>
                                        Manually Select Permissions (Checkboxes):
                                    </b>
                                    <span style={{ fontSize: 11, color: '#059669', fontWeight: 600 }}>
                                        {createForm.data.permissions.length} capabilities selected
                                    </span>
                                </div>

                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 16 }}>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyCreatePreset(availablePermissions.map(p => p.key))}
                                    >
                                        Select All
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyCreatePreset(['orders', 'order_review', 'reports'])}
                                    >
                                        Orders Staff
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyCreatePreset(['inventory', 'catalog'])}
                                    >
                                        Warehouse Staff
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyCreatePreset(['accounts'])}
                                    >
                                        Dealer Relations
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyCreatePreset(['finance', 'cost_read', 'reports'])}
                                    >
                                        Finance &amp; Treasury
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyCreatePreset([])}
                                    >
                                        Clear All
                                    </button>
                                </div>
                            </div>

                            {/* Checkbox Groups by Category */}
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                                {categories.map(cat => (
                                    <div key={cat} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, padding: '12px 14px' }}>
                                        <div style={{ fontSize: 11, fontWeight: 700, color: '#475569', textTransform: 'uppercase', marginBottom: 8 }}>
                                            {cat} Modules
                                        </div>
                                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 8 }}>
                                            {availablePermissions.filter(p => p.category === cat).map(p => {
                                                const checked = createForm.data.permissions.includes(p.key);
                                                return (
                                                    <label
                                                        key={p.key}
                                                        onClick={() => toggleCreatePermission(p.key)}
                                                        style={{
                                                            display: 'flex',
                                                            alignItems: 'flex-start',
                                                            gap: 10,
                                                            padding: '8px 10px',
                                                            borderRadius: 6,
                                                            background: checked ? '#ecfdf5' : '#ffffff',
                                                            border: checked ? '1px solid #10b981' : '1px solid #e2e8f0',
                                                            cursor: 'pointer',
                                                            userSelect: 'none'
                                                        }}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={() => {}}
                                                            style={{ marginTop: 3 }}
                                                        />
                                                        <div>
                                                            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                                <b style={{ fontSize: 12, color: checked ? '#065f46' : '#1e293b' }}>{p.label}</b>
                                                                <code style={{ fontSize: 10, background: '#f1f5f9', padding: '1px 4px', borderRadius: 3, color: '#64748b' }}>
                                                                    {p.key}
                                                                </code>
                                                            </div>
                                                            <small style={{ display: 'block', fontSize: 11, color: '#64748b', marginTop: 2, lineHeight: 1.3 }}>
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

                            <label style={{ marginTop: 14 }}>
                                Onboarding Authorization Reason *
                                <textarea
                                    required
                                    rows={2}
                                    value={createForm.data.reason}
                                    onChange={e => createForm.setData('reason', e.target.value)}
                                />
                            </label>

                            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16, borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setCreateModalOpen(false)}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="button dark"
                                    disabled={createForm.processing}
                                    style={{ fontWeight: 700 }}
                                >
                                    {createForm.processing ? 'Creating Account…' : 'Create Employee & Send Invite'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* EDIT EMPLOYEE PERMISSIONS MODAL */}
            {editModalEmployee && (
                <div className="modal-overlay" style={{
                    position: 'fixed',
                    top: 0, left: 0, right: 0, bottom: 0,
                    backgroundColor: 'rgba(15, 23, 42, 0.65)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    zIndex: 9999,
                    padding: 16
                }}>
                    <div style={{
                        background: '#ffffff',
                        borderRadius: 12,
                        width: '100%',
                        maxWidth: 720,
                        maxHeight: '90vh',
                        overflowY: 'auto',
                        boxShadow: '0 20px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1)',
                        padding: '24px 28px'
                    }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, borderBottom: '1px solid #e2e8f0', paddingBottom: 12 }}>
                            <div>
                                <h3 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: '#0f172a' }}>
                                    Edit Permissions for {editModalEmployee.name}
                                </h3>
                                <p style={{ margin: '4px 0 0 0', fontSize: 12, color: '#64748b' }}>
                                    {editModalEmployee.email} · Modifying granted dashboard permissions
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setEditModalEmployee(null)}
                                style={{ background: 'none', border: 'none', fontSize: 20, cursor: 'pointer', color: '#94a3b8' }}
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={submitEdit} className="action-form">
                            <div className="form-grid" style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 14 }}>
                                <label>
                                    Employee Name
                                    <input
                                        required
                                        value={editForm.data.name}
                                        onChange={e => editForm.setData('name', e.target.value)}
                                    />
                                </label>

                                <label>
                                    Phone Number
                                    <input
                                        value={editForm.data.phone}
                                        onChange={e => editForm.setData('phone', e.target.value)}
                                    />
                                </label>

                                <label>
                                    Account Status
                                    <select
                                        value={editForm.data.status}
                                        onChange={e => editForm.setData('status', e.target.value)}
                                    >
                                        <option value="active">Active (Access allowed)</option>
                                        <option value="suspended">Suspended (Immediate lockout)</option>
                                    </select>
                                </label>
                            </div>

                            {/* Preset Buttons */}
                            <div style={{ marginTop: 20, marginBottom: 12 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                                    <b style={{ fontSize: 13, color: '#0f172a' }}>
                                        Select Granted Permissions (Checkboxes):
                                    </b>
                                    <span style={{ fontSize: 11, color: '#059669', fontWeight: 600 }}>
                                        {editForm.data.permissions.length} capabilities selected
                                    </span>
                                </div>

                                <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 16 }}>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyEditPreset(availablePermissions.map(p => p.key))}
                                    >
                                        Select All
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyEditPreset(['orders', 'order_review', 'reports'])}
                                    >
                                        Orders Staff
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyEditPreset(['inventory', 'catalog'])}
                                    >
                                        Warehouse Staff
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyEditPreset(['finance', 'cost_read', 'reports'])}
                                    >
                                        Finance &amp; Treasury
                                    </button>
                                    <button
                                        type="button"
                                        className="button small"
                                        style={{ fontSize: 11 }}
                                        onClick={() => applyEditPreset([])}
                                    >
                                        Clear All
                                    </button>
                                </div>
                            </div>

                            {/* Checkbox Groups by Category */}
                            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                                {categories.map(cat => (
                                    <div key={cat} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 8, padding: '12px 14px' }}>
                                        <div style={{ fontSize: 11, fontWeight: 700, color: '#475569', textTransform: 'uppercase', marginBottom: 8 }}>
                                            {cat} Modules
                                        </div>
                                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 8 }}>
                                            {availablePermissions.filter(p => p.category === cat && (editModalEmployee?.role !== 'sr' || ['field_sales','sr_orders','sr_dues','sr_onboarding'].includes(p.key))).map(p => {
                                                const checked = editForm.data.permissions.includes(p.key);
                                                return (
                                                    <label
                                                        key={p.key}
                                                        onClick={() => toggleEditPermission(p.key)}
                                                        style={{
                                                            display: 'flex',
                                                            alignItems: 'flex-start',
                                                            gap: 10,
                                                            padding: '8px 10px',
                                                            borderRadius: 6,
                                                            background: checked ? '#ecfdf5' : '#ffffff',
                                                            border: checked ? '1px solid #10b981' : '1px solid #e2e8f0',
                                                            cursor: 'pointer',
                                                            userSelect: 'none'
                                                        }}
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={checked}
                                                            onChange={() => {}}
                                                            style={{ marginTop: 3 }}
                                                        />
                                                        <div>
                                                            <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                                                <b style={{ fontSize: 12, color: checked ? '#065f46' : '#1e293b' }}>{p.label}</b>
                                                                <code style={{ fontSize: 10, background: '#f1f5f9', padding: '1px 4px', borderRadius: 3, color: '#64748b' }}>
                                                                    {p.key}
                                                                </code>
                                                            </div>
                                                            <small style={{ display: 'block', fontSize: 11, color: '#64748b', marginTop: 2, lineHeight: 1.3 }}>
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

                            <label style={{ marginTop: 14 }}>
                                Reason for Permission Correction *
                                <textarea
                                    required
                                    rows={2}
                                    placeholder="Enter audit reason for modifying permissions or account status"
                                    value={editForm.data.reason}
                                    onChange={e => editForm.setData('reason', e.target.value)}
                                />
                            </label>

                            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 16, borderTop: '1px solid #e2e8f0', paddingTop: 14 }}>
                                <button
                                    type="button"
                                    className="button"
                                    onClick={() => setEditModalEmployee(null)}
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    className="button dark"
                                    disabled={editForm.processing}
                                    style={{ fontWeight: 700 }}
                                >
                                    {editForm.processing ? 'Saving Changes…' : 'Save Permissions'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
}
