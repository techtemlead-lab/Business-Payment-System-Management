import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Building2, MapPin, Plus, Store, Truck, Users } from 'lucide-react';
import { Badge, Modal, Table, Pagination, type Row } from './UI';
import { useT } from '../shared/i18n/t';

interface BranchStaff {
    user_id: number;
    name?: string | null;
    email?: string | null;
    phone?: string | null;
    duty: string;
    is_primary: boolean;
}

interface BranchRow extends Row {
    id: number;
    code: string;
    name: string;
    division?: string | null;
    district?: string | null;
    upazila?: string | null;
    address?: string | null;
    phone?: string | null;
    is_active: boolean;
    is_delivery_point: boolean;
    is_stock_hub: boolean;
    stock_skus?: number;
    stock_units?: number;
    staff?: BranchStaff[];
}

interface AssignableStaff {
    id: number;
    name: string;
    email?: string | null;
    phone?: string | null;
    role: string;
}

interface BranchesPage {
    data: BranchRow[];
    current_page: number;
    last_page: number;
    total: number;
    links: unknown[];
}

interface BranchesHubProps {
    branches: BranchesPage;
    active_branch_id?: number | null;
    assignable_staff?: AssignableStaff[];
    canManage?: boolean;
}

export default function BranchesHub({
    branches,
    active_branch_id,
    assignable_staff = [],
    canManage = true,
}: BranchesHubProps) {
    const translate = useT();
    const [createOpen, setCreateOpen] = useState(false);
    const [editRow, setEditRow] = useState<BranchRow | null>(null);
    const [assignRow, setAssignRow] = useState<BranchRow | null>(null);

    const createForm = useForm({
        code: 'BR-',
        name: '',
        division: '',
        district: '',
        upazila: '',
        address: '',
        phone: '',
        is_active: true,
        is_delivery_point: true,
        is_stock_hub: true,
        notes: '',
    });

    const editForm = useForm({
        code: '',
        name: '',
        division: '',
        district: '',
        upazila: '',
        address: '',
        phone: '',
        is_active: true,
        is_delivery_point: true,
        is_stock_hub: true,
        notes: '',
    });

    const assignForm = useForm({
        user_id: '',
        duty: 'storekeeper',
        is_primary: true,
    });

    const openEdit = (row: BranchRow) => {
        setEditRow(row);
        editForm.setData({
            code: row.code,
            name: row.name,
            division: row.division || '',
            district: row.district || '',
            upazila: row.upazila || '',
            address: row.address || '',
            phone: row.phone || '',
            is_active: !!row.is_active,
            is_delivery_point: !!row.is_delivery_point,
            is_stock_hub: !!row.is_stock_hub,
            notes: '',
        });
    };

    const location = (row: BranchRow) =>
        [row.upazila, row.district, row.division].filter(Boolean).join(', ') || '—';

    return (
        <div data-tour="branches-hub">
            <div className="toolbar-end">
                {canManage && (
                    <button type="button" className="button dark" onClick={() => setCreateOpen(true)}>
                        <Plus size={17} />
                        {translate('portal', 'branch_add')}
                    </button>
                )}
            </div>

            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'branches_title')}</h2>
                        <p className="muted">{translate('portal', 'branches_hint')}</p>
                    </div>
                    <span className="badge">{translate('portal', 'branches_count', { count: String(branches.total || branches.data.length) })}</span>
                </div>
                <Table
                    rows={branches.data}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'col_branch'),
                            render: (r: BranchRow) => (
                                <>
                                    <b>{r.name}</b>
                                    <small className="cell-sub">{r.code}</small>
                                    {Number(active_branch_id) === Number(r.id) && (
                                        <span className="badge" style={{ marginLeft: 8 }}>{translate('portal', 'branch_active_badge')}</span>
                                    )}
                                </>
                            ),
                        },
                        {
                            key: 'location',
                            title: translate('portal', 'label_location'),
                            render: (r: BranchRow) => (
                                <>
                                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                        <MapPin size={14} /> {location(r)}
                                    </span>
                                    {r.address && <small className="cell-sub">{r.address}</small>}
                                </>
                            ),
                        },
                        {
                            key: 'flags',
                            title: translate('portal', 'branch_roles'),
                            render: (r: BranchRow) => (
                                <div className="inline-actions" style={{ flexWrap: 'wrap', gap: 6 }}>
                                    {r.is_stock_hub && <span className="badge"><Store size={12} /> {translate('portal', 'branch_stock_hub')}</span>}
                                    {r.is_delivery_point && <span className="badge"><Truck size={12} /> {translate('portal', 'branch_delivery_point')}</span>}
                                    {!r.is_active && <Badge status="suspended" />}
                                </div>
                            ),
                        },
                        {
                            key: 'stock',
                            title: translate('portal', 'branch_stock_summary'),
                            render: (r: BranchRow) => (
                                <b>{translate('portal', 'branch_stock_units', {
                                    units: String(r.stock_units ?? 0),
                                    skus: String(r.stock_skus ?? 0),
                                })}</b>
                            ),
                        },
                        {
                            key: 'staff',
                            title: translate('portal', 'branch_staff'),
                            render: (r: BranchRow) => (
                                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                                    <Users size={14} /> {(r.staff || []).length}
                                </span>
                            ),
                        },
                        {
                            key: 'action',
                            title: translate('portal', 'col_manage'),
                            render: (r: BranchRow) => (
                                <div className="inline-actions">
                                    {Number(active_branch_id) !== Number(r.id) && r.is_active && (
                                        <button
                                            type="button"
                                            className="button small"
                                            onClick={() => router.post(`/commands/branches/${r.id}/select`, {}, { preserveScroll: true })}
                                        >
                                            {translate('portal', 'branch_select')}
                                        </button>
                                    )}
                                    {canManage && (
                                        <>
                                            <button type="button" className="button small" onClick={() => openEdit(r)}>
                                                {translate('portal', 'branch_edit')}
                                            </button>
                                            <button type="button" className="button small" onClick={() => { setAssignRow(r); assignForm.setData({ user_id: assignable_staff[0] ? String(assignable_staff[0].id) : '', duty: 'storekeeper', is_primary: true }); }}>
                                                {translate('portal', 'branch_assign_staff')}
                                            </button>
                                        </>
                                    )}
                                </div>
                            ),
                        },
                    ]}
                />
                <Pagination page={branches} />
            </section>

            {createOpen && (
                <Modal title={translate('portal', 'branch_add')} onClose={() => setCreateOpen(false)}>
                    <form
                        className="form-grid"
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post('/commands/branches', {
                                preserveScroll: true,
                                onSuccess: () => { createForm.reset(); setCreateOpen(false); },
                            });
                        }}
                    >
                        <label>{translate('portal', 'branch_code')}<input value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value)} required /></label>
                        <label>{translate('portal', 'branch_name')}<input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required /></label>
                        <label>{translate('portal', 'profile_division')}<input value={createForm.data.division} onChange={(e) => createForm.setData('division', e.target.value)} /></label>
                        <label>{translate('portal', 'profile_district')}<input value={createForm.data.district} onChange={(e) => createForm.setData('district', e.target.value)} /></label>
                        <label>{translate('portal', 'profile_upazila')}<input value={createForm.data.upazila} onChange={(e) => createForm.setData('upazila', e.target.value)} /></label>
                        <label>{translate('portal', 'branch_phone')}<input value={createForm.data.phone} onChange={(e) => createForm.setData('phone', e.target.value)} /></label>
                        <label className="full">{translate('portal', 'branch_address')}<input value={createForm.data.address} onChange={(e) => createForm.setData('address', e.target.value)} /></label>
                        <label><input type="checkbox" checked={createForm.data.is_stock_hub} onChange={(e) => createForm.setData('is_stock_hub', e.target.checked)} /> {translate('portal', 'branch_stock_hub')}</label>
                        <label><input type="checkbox" checked={createForm.data.is_delivery_point} onChange={(e) => createForm.setData('is_delivery_point', e.target.checked)} /> {translate('portal', 'branch_delivery_point')}</label>
                        <div className="toolbar-end full">
                            <button type="button" className="button" onClick={() => setCreateOpen(false)}>{translate('portal', 'close')}</button>
                            <button type="submit" className="button dark" disabled={createForm.processing}>{translate('portal', 'branch_save')}</button>
                        </div>
                    </form>
                </Modal>
            )}

            {editRow && (
                <Modal title={translate('portal', 'branch_edit')} onClose={() => setEditRow(null)}>
                    <form
                        className="form-grid"
                        onSubmit={(e) => {
                            e.preventDefault();
                            editForm.patch(`/commands/branches/${editRow.id}`, {
                                preserveScroll: true,
                                onSuccess: () => setEditRow(null),
                            });
                        }}
                    >
                        <label>{translate('portal', 'branch_code')}<input value={editForm.data.code} onChange={(e) => editForm.setData('code', e.target.value)} required /></label>
                        <label>{translate('portal', 'branch_name')}<input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required /></label>
                        <label>{translate('portal', 'profile_division')}<input value={editForm.data.division} onChange={(e) => editForm.setData('division', e.target.value)} /></label>
                        <label>{translate('portal', 'profile_district')}<input value={editForm.data.district} onChange={(e) => editForm.setData('district', e.target.value)} /></label>
                        <label>{translate('portal', 'profile_upazila')}<input value={editForm.data.upazila} onChange={(e) => editForm.setData('upazila', e.target.value)} /></label>
                        <label>{translate('portal', 'branch_phone')}<input value={editForm.data.phone} onChange={(e) => editForm.setData('phone', e.target.value)} /></label>
                        <label className="full">{translate('portal', 'branch_address')}<input value={editForm.data.address} onChange={(e) => editForm.setData('address', e.target.value)} /></label>
                        <label><input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} /> {translate('portal', 'branch_active')}</label>
                        <label><input type="checkbox" checked={editForm.data.is_stock_hub} onChange={(e) => editForm.setData('is_stock_hub', e.target.checked)} /> {translate('portal', 'branch_stock_hub')}</label>
                        <label><input type="checkbox" checked={editForm.data.is_delivery_point} onChange={(e) => editForm.setData('is_delivery_point', e.target.checked)} /> {translate('portal', 'branch_delivery_point')}</label>
                        <div className="toolbar-end full">
                            <button type="button" className="button" onClick={() => setEditRow(null)}>{translate('portal', 'close')}</button>
                            <button type="submit" className="button dark" disabled={editForm.processing}>{translate('portal', 'branch_save')}</button>
                        </div>
                    </form>
                    {(editRow.staff || []).length > 0 && (
                        <div style={{ marginTop: 16 }}>
                            <h3 style={{ display: 'flex', alignItems: 'center', gap: 6 }}><Building2 size={16} /> {translate('portal', 'branch_staff')}</h3>
                            <Table
                                rows={editRow.staff || []}
                                columns={[
                                    { key: 'name', title: translate('portal', 'col_name'), render: (s: BranchStaff) => <><b>{s.name}</b><small className="cell-sub">{s.phone || s.email}</small></> },
                                    { key: 'duty', title: translate('portal', 'branch_duty') },
                                    {
                                        key: 'action',
                                        title: '',
                                        render: (s: BranchStaff) => (
                                            <button
                                                type="button"
                                                className="button small"
                                                onClick={() => router.delete(`/commands/branches/${editRow.id}/staff/${s.user_id}`, { preserveScroll: true })}
                                            >
                                                {translate('portal', 'branch_remove_staff')}
                                            </button>
                                        ),
                                    },
                                ]}
                            />
                        </div>
                    )}
                </Modal>
            )}

            {assignRow && (
                <Modal title={translate('portal', 'branch_assign_staff')} onClose={() => setAssignRow(null)}>
                    <form
                        className="form-grid"
                        onSubmit={(e) => {
                            e.preventDefault();
                            assignForm.post(`/commands/branches/${assignRow.id}/staff`, {
                                preserveScroll: true,
                                onSuccess: () => setAssignRow(null),
                            });
                        }}
                    >
                        <label className="full">
                            {translate('portal', 'branch_staff_member')}
                            <select value={assignForm.data.user_id} onChange={(e) => assignForm.setData('user_id', e.target.value)} required>
                                <option value="">{translate('portal', 'branch_pick_staff')}</option>
                                {assignable_staff.map((staff) => (
                                    <option key={staff.id} value={staff.id}>{staff.name} · {staff.role}</option>
                                ))}
                            </select>
                        </label>
                        <label>
                            {translate('portal', 'branch_duty')}
                            <select value={assignForm.data.duty} onChange={(e) => assignForm.setData('duty', e.target.value)}>
                                <option value="manager">{translate('portal', 'branch_duty_manager')}</option>
                                <option value="storekeeper">{translate('portal', 'branch_duty_storekeeper')}</option>
                                <option value="dispatcher">{translate('portal', 'branch_duty_dispatcher')}</option>
                            </select>
                        </label>
                        <label>
                            <input type="checkbox" checked={assignForm.data.is_primary} onChange={(e) => assignForm.setData('is_primary', e.target.checked)} />
                            {translate('portal', 'branch_primary_assignment')}
                        </label>
                        <div className="toolbar-end full">
                            <button type="button" className="button" onClick={() => setAssignRow(null)}>{translate('portal', 'close')}</button>
                            <button type="submit" className="button dark" disabled={assignForm.processing}>{translate('portal', 'branch_save')}</button>
                        </div>
                    </form>
                </Modal>
            )}
        </div>
    );
}
