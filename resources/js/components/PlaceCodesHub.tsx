import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Hash, Plus, Search, MapPinned, Building2, Landmark, Layers3, CheckCircle2 } from 'lucide-react';
import { Table, Pagination, Badge, Modal, type Row } from './UI';

interface PlaceRow extends Row {
    id: number;
    code: string;
    name: string;
    level: string;
    parent_id?: number | null;
    parent_code?: string | null;
    parent_name?: string | null;
    base_place_id?: number | null;
    base_place_code?: string | null;
    base_place_name?: string | null;
    base_options?: { id: number; code: string; name: string; level: string }[];
    sort_order?: number;
    is_active?: boolean;
}

interface Submodule {
    key: string;
    label: string;
    href: string;
}

interface ParentOption {
    id: number;
    code: string;
    name: string;
    level: string;
}

interface PlaceCodesHubProps {
    level: string;
    level_label: string;
    searchable: boolean;
    filters: { q?: string; level?: string };
    submodules: Submodule[];
    parent_options: ParentOption[];
    places: {
        data: PlaceRow[];
        current_page: number;
        last_page: number;
        total: number;
        links: unknown[];
    };
}

const levelIcons: Record<string, React.ReactNode> = {
    management: <Landmark size={16} />,
    division: <Layers3 size={16} />,
    district: <Building2 size={16} />,
    upazila: <MapPinned size={16} />,
    union: <Hash size={16} />,
};

export default function PlaceCodesHub({
    level,
    level_label,
    searchable,
    filters,
    submodules,
    parent_options,
    places,
}: PlaceCodesHubProps) {
    const [search, setSearch] = useState(filters.q || '');
    const [createOpen, setCreateOpen] = useState(false);
    const [editRow, setEditRow] = useState<PlaceRow | null>(null);

    const createForm = useForm({
        level,
        code: level === 'management' ? 'MNHC-100' : 'MNHC-',
        name: '',
        parent_id: parent_options[0] ? String(parent_options[0].id) : '',
        sort_order: 0,
        is_active: true,
    });

    const editForm = useForm({
        code: '',
        name: '',
        parent_id: '',
        base_place_id: '',
        sort_order: 0,
        is_active: true,
    });

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get('/place-codes', { level, q: search }, { preserveState: true });
    };

    const openEdit = (row: PlaceRow) => {
        setEditRow(row);
        editForm.setData({
            code: row.code,
            name: row.name,
            parent_id: row.parent_id ? String(row.parent_id) : '',
            base_place_id: row.base_place_id ? String(row.base_place_id) : '',
            sort_order: row.sort_order ?? 0,
            is_active: !!row.is_active,
        });
    };

    const submitCreate = (e: React.FormEvent) => {
        e.preventDefault();
        createForm.transform((data) => ({
            ...data,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
        }));
        createForm.post('/commands/place-codes', {
            preserveScroll: true,
            onSuccess: () => {
                setCreateOpen(false);
                createForm.reset();
                createForm.setData({
                    level,
                    code: level === 'management' ? 'MNHC-100' : 'MNHC-',
                    name: '',
                    parent_id: parent_options[0] ? String(parent_options[0].id) : '',
                    sort_order: 0,
                    is_active: true,
                });
            },
        });
    };

    const submitEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editRow) {
            return;
        }
        editForm.transform((data) => ({
            ...data,
            parent_id: data.parent_id === '' ? null : Number(data.parent_id),
            base_place_id: data.base_place_id === '' ? null : Number(data.base_place_id),
        }));
        editForm.patch(`/commands/place-codes/${editRow.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditRow(null),
        });
    };

    return (
        <div className="place-codes-hub">
            <section className="panel" style={{ marginBottom: 16, borderLeft: '4px solid #0f766e' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 16, flexWrap: 'wrap' }}>
                    <div>
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 700, color: '#0f766e', textTransform: 'uppercase' }}>
                            <Hash size={15} /> Place codes
                        </div>
                        <h2 style={{ margin: '6px 0' }}>{level_label} codes</h2>
                        <p className="muted" style={{ margin: 0, maxWidth: 640 }}>
                            Assign dealers by branded place code (for example MNHC-101 for Dhaka division, MNHC-100 for management). Enter digits alone and the MNHC- prefix is applied automatically. Base places define the last-serial path used when a higher-level assignee purchases.
                        </p>
                    </div>
                    {(level !== 'management' || places.data.length === 0) && (
                        <button type="button" className="button dark" onClick={() => setCreateOpen(true)}>
                            <Plus size={16} /> Add {level_label.toLowerCase()} code
                        </button>
                    )}
                </div>
            </section>

            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
                {submodules.map((item) => (
                    <Link
                        key={item.key}
                        href={item.href}
                        className={`button small ${level === item.key ? 'dark' : ''}`}
                        style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}
                    >
                        {levelIcons[item.key]}
                        {item.label}
                    </Link>
                ))}
            </div>

            {searchable && (
                <form onSubmit={handleSearch} className="panel" style={{ marginBottom: 16, display: 'flex', gap: 10, alignItems: 'center' }}>
                    <Search size={18} color="#64748b" />
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={`Search ${level_label.toLowerCase()} by code, name, or parent…`}
                        style={{ flex: 1, border: 0, outline: 'none', fontSize: 15, background: 'transparent' }}
                    />
                    <button type="submit" className="button small dark">Search</button>
                </form>
            )}

            <section className="panel">
                <Table
                    rows={places.data}
                    columns={[
                        { key: 'code', title: 'Code', render: (r) => <b style={{ fontVariantNumeric: 'tabular-nums' }}>{r.code}</b> },
                        { key: 'name', title: 'Place name' },
                        {
                            key: 'parent_name',
                            title: 'Parent',
                            render: (r) => (r.parent_code ? `${r.parent_name} (${r.parent_code})` : '—'),
                        },
                        {
                            key: 'base_place_name',
                            title: 'Base place',
                            render: (r) => (r.base_place_code ? `${r.base_place_name} (${r.base_place_code})` : <span className="muted">—</span>),
                        },
                        {
                            key: 'is_active',
                            title: 'Status',
                            render: (r) => <Badge status={r.is_active ? 'published' : 'draft'} />,
                        },
                        {
                            key: 'action',
                            title: '',
                            render: (r) => (
                                <button type="button" className="text-link" onClick={() => openEdit(r as PlaceRow)}>
                                    Edit
                                </button>
                            ),
                        },
                    ]}
                    empty={`No ${level_label.toLowerCase()} codes yet`}
                />
                <Pagination page={places} />
            </section>

            {createOpen && (
                <Modal title={`Add ${level_label.toLowerCase()} code`} onClose={() => setCreateOpen(false)}>
                    <form onSubmit={submitCreate} className="action-form">
                        <label>
                            Code
                            <input value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value.toUpperCase())} required placeholder="e.g. MNHC-201" />
                            {createForm.errors.code && <span className="field-error">{createForm.errors.code}</span>}
                        </label>
                        <label>
                            Name
                            <input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required placeholder="Place name" />
                            {createForm.errors.name && <span className="field-error">{createForm.errors.name}</span>}
                        </label>
                        {parent_options.length > 0 && (
                            <label>
                                Parent
                                <select value={createForm.data.parent_id} onChange={(e) => createForm.setData('parent_id', e.target.value)} required>
                                    {parent_options.map((option) => (
                                        <option key={option.id} value={option.id}>{option.name} ({option.code})</option>
                                    ))}
                                </select>
                                {createForm.errors.parent_id && <span className="field-error">{createForm.errors.parent_id}</span>}
                            </label>
                        )}
                        <button type="submit" className="button dark" disabled={createForm.processing}>
                            <CheckCircle2 size={16} /> Save code
                        </button>
                    </form>
                </Modal>
            )}

            {editRow && (
                <Modal title={`Edit ${editRow.name}`} onClose={() => setEditRow(null)}>
                    <form onSubmit={submitEdit} className="action-form">
                        <label>
                            Code
                            <input value={editForm.data.code} onChange={(e) => editForm.setData('code', e.target.value)} required />
                            {editForm.errors.code && <span className="field-error">{editForm.errors.code}</span>}
                        </label>
                        <label>
                            Name
                            <input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />
                            {editForm.errors.name && <span className="field-error">{editForm.errors.name}</span>}
                        </label>
                        {parent_options.length > 0 && (
                            <label>
                                Parent
                                <select value={editForm.data.parent_id} onChange={(e) => editForm.setData('parent_id', e.target.value)}>
                                    {parent_options.map((option) => (
                                        <option key={option.id} value={option.id}>{option.name} ({option.code})</option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {(editRow.base_options?.length ?? 0) > 0 && (
                            <label>
                                Base place (last-serial child)
                                <select value={editForm.data.base_place_id} onChange={(e) => editForm.setData('base_place_id', e.target.value)}>
                                    <option value="">—</option>
                                    {editRow.base_options?.map((option) => (
                                        <option key={option.id} value={option.id}>{option.name} ({option.code})</option>
                                    ))}
                                </select>
                                {editForm.errors.base_place_id && <span className="field-error">{editForm.errors.base_place_id}</span>}
                            </label>
                        )}
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} />
                            Active
                        </label>
                        <button type="submit" className="button dark" disabled={editForm.processing}>Save changes</button>
                    </form>
                </Modal>
            )}
        </div>
    );
}
