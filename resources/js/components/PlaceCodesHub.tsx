import React, { useState } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import { Hash, Plus, Search, MapPinned, Building2, Landmark, Layers3, CheckCircle2 } from 'lucide-react';
import { Table, Pagination, Badge, Modal, type Row } from './UI';
import { useT } from '../shared/i18n/t';

interface PlaceRow extends Row {
    id: number;
    code: string | null;
    name: string | null;
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
    is_placeholder?: boolean;
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

const displayPlace = (value?: string | null): string => {
    if (!value || value === '-' || value === '—' || value === '–') {
        return '—';
    }

    return value;
};

const formValue = (value?: string | null): string => {
    const shown = displayPlace(value);

    return shown === '—' ? '' : shown;
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
    const translate = useT();
    const [search, setSearch] = useState(filters.q || '');
    const [createOpen, setCreateOpen] = useState(false);
    const [editRow, setEditRow] = useState<PlaceRow | null>(null);
    const levelName = level_label.toLowerCase();

    const createForm = useForm({
        level,
        code: '',
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
            code: formValue(row.code),
            name: formValue(row.name),
            parent_id: row.parent_id ? String(row.parent_id) : '',
            base_place_id: row.base_place_id ? String(row.base_place_id) : '',
            sort_order: row.sort_order ?? 0,
            is_active: !!row.is_active,
        });
    };

    const removePlace = (row: PlaceRow) => {
        const label = displayPlace(row.name);
        const reason = window.prompt(translate('portal', 'pc_remove_prompt', { name: label }));
        if (reason === null || reason.trim().length < 3) {
            return;
        }
        router.delete(`/commands/place-codes/${row.id}`, {
            data: { reason: reason.trim() },
            preserveScroll: true,
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
                    code: '',
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
                            <Hash size={15} /> {translate('portal', 'pc_badge')}
                        </div>
                        <h2 style={{ margin: '6px 0' }}>{translate('portal', 'pc_heading', { level: level_label })}</h2>
                        <p className="muted" style={{ margin: 0, maxWidth: 640 }}>
                            {translate('portal', 'pc_hint')}
                        </p>
                    </div>
                    {(level !== 'management' || places.data.length === 0) && (
                        <button type="button" className="button dark" onClick={() => setCreateOpen(true)}>
                            <Plus size={16} /> {translate('portal', 'pc_add', { level: levelName })}
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
                        placeholder={translate('portal', 'pc_search_ph', { level: levelName })}
                        style={{ flex: 1, border: 0, outline: 'none', fontSize: 15, background: 'transparent' }}
                    />
                    <button type="submit" className="button small dark">{translate('portal', 'pc_search')}</button>
                </form>
            )}

            <section className="panel">
                <Table
                    rows={places.data}
                    columns={[
                        { key: 'code', title: translate('portal', 'pc_col_code'), render: (r) => <b style={{ fontVariantNumeric: 'tabular-nums' }}>{displayPlace(r.code)}</b> },
                        { key: 'name', title: translate('portal', 'pc_col_name'), render: (r) => displayPlace(r.name) },
                        {
                            key: 'parent_name',
                            title: translate('portal', 'pc_col_parent'),
                            render: (r) => (r.parent_id ? `${displayPlace(r.parent_name)} (${displayPlace(r.parent_code)})` : '—'),
                        },
                        {
                            key: 'base_place_name',
                            title: translate('portal', 'pc_col_base'),
                            render: (r) => (r.base_place_code ? `${displayPlace(r.base_place_name)} (${displayPlace(r.base_place_code)})` : <span className="muted">—</span>),
                        },
                        {
                            key: 'is_active',
                            title: translate('portal', 'pc_col_status'),
                            render: (r) => <Badge status={r.is_active ? 'published' : 'draft'} />,
                        },
                        {
                            key: 'action',
                            title: '',
                            render: (r) => (
                                <span style={{ display: 'inline-flex', gap: 10 }}>
                                    <button type="button" className="text-link" onClick={() => openEdit(r as PlaceRow)}>
                                        {translate('portal', 'pc_edit')}
                                    </button>
                                    <button type="button" className="text-link" onClick={() => removePlace(r as PlaceRow)}>
                                        {translate('portal', 'pc_remove')}
                                    </button>
                                </span>
                            ),
                        },
                    ]}
                    empty={translate('portal', 'pc_empty', { level: levelName })}
                />
                <Pagination page={places} />
            </section>

            {createOpen && (
                <Modal title={translate('portal', 'pc_create_title', { level: levelName })} onClose={() => setCreateOpen(false)}>
                    <form onSubmit={submitCreate} className="action-form">
                        <label>
                            {translate('portal', 'pc_fld_code')}
                            <input value={createForm.data.code} onChange={(e) => createForm.setData('code', e.target.value.toUpperCase())} placeholder={translate('portal', 'pc_ph_code')} />
                            {createForm.errors.code && <span className="field-error">{createForm.errors.code}</span>}
                        </label>
                        <label>
                            {translate('portal', 'pc_fld_name')}
                            <input value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} placeholder={translate('portal', 'pc_ph_name')} />
                            {createForm.errors.name && <span className="field-error">{createForm.errors.name}</span>}
                        </label>
                        {parent_options.length > 0 && (
                            <label>
                                {translate('portal', 'pc_fld_parent')}
                                <select value={createForm.data.parent_id} onChange={(e) => createForm.setData('parent_id', e.target.value)} required>
                                    {parent_options.map((option) => (
                                        <option key={option.id} value={option.id}>{displayPlace(option.name)} ({displayPlace(option.code)})</option>
                                    ))}
                                </select>
                                {createForm.errors.parent_id && <span className="field-error">{createForm.errors.parent_id}</span>}
                            </label>
                        )}
                        <button type="submit" className="button dark" disabled={createForm.processing}>
                            <CheckCircle2 size={16} /> {translate('portal', 'pc_save')}
                        </button>
                    </form>
                </Modal>
            )}

            {editRow && (
                <Modal title={translate('portal', 'pc_edit_title', { name: displayPlace(editRow.name) })} onClose={() => setEditRow(null)}>
                    <form onSubmit={submitEdit} className="action-form">
                        <label>
                            {translate('portal', 'pc_fld_code')}
                            <input value={editForm.data.code} onChange={(e) => editForm.setData('code', e.target.value)} placeholder={translate('portal', 'pc_ph_code')} />
                            {editForm.errors.code && <span className="field-error">{editForm.errors.code}</span>}
                        </label>
                        <label>
                            {translate('portal', 'pc_fld_name')}
                            <input value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} placeholder={translate('portal', 'pc_ph_name')} />
                            {editForm.errors.name && <span className="field-error">{editForm.errors.name}</span>}
                        </label>
                        {parent_options.length > 0 && (
                            <label>
                                {translate('portal', 'pc_fld_parent')}
                                <select value={editForm.data.parent_id} onChange={(e) => editForm.setData('parent_id', e.target.value)}>
                                    {parent_options.map((option) => (
                                        <option key={option.id} value={option.id}>{displayPlace(option.name)} ({displayPlace(option.code)})</option>
                                    ))}
                                </select>
                            </label>
                        )}
                        {(editRow.base_options?.length ?? 0) > 0 && (
                            <label>
                                {translate('portal', 'pc_fld_base_serial')}
                                <select value={editForm.data.base_place_id} onChange={(e) => editForm.setData('base_place_id', e.target.value)}>
                                    <option value="">—</option>
                                    {editRow.base_options?.map((option) => (
                                        <option key={option.id} value={option.id}>{displayPlace(option.name)} ({displayPlace(option.code)})</option>
                                    ))}
                                </select>
                                {editForm.errors.base_place_id && <span className="field-error">{editForm.errors.base_place_id}</span>}
                            </label>
                        )}
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} />
                            {translate('portal', 'pc_fld_active')}
                        </label>
                        <button type="submit" className="button dark" disabled={editForm.processing}>{translate('portal', 'pc_save_changes')}</button>
                    </form>
                </Modal>
            )}
        </div>
    );
}
