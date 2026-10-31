import React, { useMemo, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { FolderTree, Layers, Plus, Ruler } from 'lucide-react';
import { Badge, Modal, Table, type Row } from './UI';
import { useT } from '../shared/i18n/t';

interface SubcategoryRow {
    id: number;
    product_category_id: number;
    name: string;
    slug: string;
    sort_order: number;
    is_active: boolean;
}

interface CategoryRow extends Row {
    id: number;
    name: string;
    slug: string;
    sort_order: number;
    is_active: boolean;
    subcategories: SubcategoryRow[];
}

interface UnitRow extends Row {
    id: number;
    name: string;
    code: string;
    sort_order: number;
    is_active: boolean;
}

interface CatalogTaxonomyHubProps {
    categories?: CategoryRow[];
    units?: UnitRow[];
    canManage?: boolean;
}

export default function CatalogTaxonomyHub({
    categories = [],
    units = [],
    canManage = false,
}: CatalogTaxonomyHubProps) {
    const translate = useT();
    const [createCategoryOpen, setCreateCategoryOpen] = useState(false);
    const [editCategory, setEditCategory] = useState<CategoryRow | null>(null);
    const [createSubOpen, setCreateSubOpen] = useState(false);
    const [editSub, setEditSub] = useState<SubcategoryRow | null>(null);
    const [createUnitOpen, setCreateUnitOpen] = useState(false);
    const [editUnit, setEditUnit] = useState<UnitRow | null>(null);
    const [parentCategoryId, setParentCategoryId] = useState<string>(
        categories[0] ? String(categories[0].id) : '',
    );

    const subcategoryRows = useMemo(
        () => categories.flatMap((category) => category.subcategories.map((sub) => ({
            ...sub,
            category_name: category.name,
        }))),
        [categories],
    );

    const categoryForm = useForm({
        name: '',
        slug: '',
        sort_order: 0,
        is_active: true,
    });

    const subcategoryForm = useForm({
        product_category_id: parentCategoryId,
        name: '',
        slug: '',
        sort_order: 0,
        is_active: true,
    });

    const unitForm = useForm({
        name: '',
        code: '',
        sort_order: 0,
        is_active: true,
    });

    const openCreateCategory = () => {
        categoryForm.setData({ name: '', slug: '', sort_order: 0, is_active: true });
        categoryForm.clearErrors();
        setCreateCategoryOpen(true);
    };

    const openEditCategory = (row: CategoryRow) => {
        setEditCategory(row);
        categoryForm.setData({
            name: row.name,
            slug: row.slug,
            sort_order: row.sort_order ?? 0,
            is_active: !!row.is_active,
        });
        categoryForm.clearErrors();
    };

    const openCreateSub = (categoryId?: number) => {
        const id = categoryId ? String(categoryId) : (categories[0] ? String(categories[0].id) : '');
        setParentCategoryId(id);
        subcategoryForm.setData({
            product_category_id: id,
            name: '',
            slug: '',
            sort_order: 0,
            is_active: true,
        });
        subcategoryForm.clearErrors();
        setCreateSubOpen(true);
    };

    const openEditSub = (row: SubcategoryRow) => {
        setEditSub(row);
        subcategoryForm.setData({
            product_category_id: String(row.product_category_id),
            name: row.name,
            slug: row.slug,
            sort_order: row.sort_order ?? 0,
            is_active: !!row.is_active,
        });
        subcategoryForm.clearErrors();
    };

    const openCreateUnit = () => {
        unitForm.setData({ name: '', code: '', sort_order: 0, is_active: true });
        unitForm.clearErrors();
        setCreateUnitOpen(true);
    };

    const openEditUnit = (row: UnitRow) => {
        setEditUnit(row);
        unitForm.setData({
            name: row.name,
            code: row.code,
            sort_order: row.sort_order ?? 0,
            is_active: !!row.is_active,
        });
        unitForm.clearErrors();
    };

    const submitCategory = (e: React.FormEvent) => {
        e.preventDefault();
        if (editCategory) {
            categoryForm.patch(`/commands/product-categories/${editCategory.id}`, {
                preserveScroll: true,
                onSuccess: () => setEditCategory(null),
            });
            return;
        }
        categoryForm.post('/commands/product-categories', {
            preserveScroll: true,
            onSuccess: () => {
                setCreateCategoryOpen(false);
                categoryForm.reset();
            },
        });
    };

    const submitSubcategory = (e: React.FormEvent) => {
        e.preventDefault();
        subcategoryForm.transform((data) => ({
            ...data,
            product_category_id: Number(data.product_category_id),
        }));
        if (editSub) {
            subcategoryForm.patch(`/commands/product-subcategories/${editSub.id}`, {
                preserveScroll: true,
                onSuccess: () => setEditSub(null),
            });
            return;
        }
        subcategoryForm.post('/commands/product-subcategories', {
            preserveScroll: true,
            onSuccess: () => {
                setCreateSubOpen(false);
                subcategoryForm.reset();
            },
        });
    };

    const submitUnit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editUnit) {
            unitForm.patch(`/commands/product-units/${editUnit.id}`, {
                preserveScroll: true,
                onSuccess: () => setEditUnit(null),
            });
            return;
        }
        unitForm.post('/commands/product-units', {
            preserveScroll: true,
            onSuccess: () => {
                setCreateUnitOpen(false);
                unitForm.reset();
            },
        });
    };

    return (
        <div data-tour="catalog-taxonomy-hub" style={{ display: 'grid', gap: 20 }}>
            <section className="panel" style={{ borderLeft: '4px solid #006837' }}>
                <div className="panel-heading" style={{ alignItems: 'flex-start' }}>
                    <div>
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 12, fontWeight: 700, color: '#006837', textTransform: 'uppercase' }}>
                            <FolderTree size={15} /> {translate('portal', 'cms_taxonomy_badge')}
                        </div>
                        <h2 style={{ margin: '6px 0' }}>{translate('portal', 'cms_taxonomy_heading')}</h2>
                        <p className="muted" style={{ margin: 0, maxWidth: 680 }}>
                            {translate('portal', 'cms_taxonomy_hint')}
                        </p>
                    </div>
                    {canManage && (
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            <button type="button" className="button dark" onClick={openCreateCategory}>
                                <Plus size={16} /> {translate('portal', 'cms_add_category')}
                            </button>
                            <button type="button" className="button" onClick={() => openCreateSub()} disabled={!categories.length}>
                                <Plus size={16} /> {translate('portal', 'cms_add_subcategory')}
                            </button>
                            <button type="button" className="button" onClick={openCreateUnit}>
                                <Plus size={16} /> {translate('portal', 'cms_add_unit')}
                            </button>
                        </div>
                    )}
                </div>
            </section>

            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2><Layers size={16} style={{ marginRight: 6 }} />{translate('portal', 'cms_categories_title')}</h2>
                        <p className="muted">{translate('portal', 'cms_categories_hint')}</p>
                    </div>
                    <span className="badge">{categories.length}</span>
                </div>
                <Table
                    rows={categories}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'cms_col_name'),
                            render: (r: CategoryRow) => (
                                <>
                                    <b>{r.name}</b>
                                    <small className="cell-sub">{r.slug}</small>
                                </>
                            ),
                        },
                        {
                            key: 'subcategories',
                            title: translate('portal', 'cms_col_subcategories'),
                            render: (r: CategoryRow) => r.subcategories?.length || 0,
                        },
                        {
                            key: 'sort_order',
                            title: translate('portal', 'cms_col_sort'),
                        },
                        {
                            key: 'is_active',
                            title: translate('portal', 'cms_col_status'),
                            render: (r: CategoryRow) => <Badge status={r.is_active ? 'published' : 'draft'} />,
                        },
                        {
                            key: 'action',
                            title: '',
                            render: (r: CategoryRow) => canManage ? (
                                <div className="inline-actions">
                                    <button type="button" className="text-link" onClick={() => openEditCategory(r)}>{translate('portal', 'cms_edit')}</button>
                                    <button type="button" className="text-link" onClick={() => openCreateSub(r.id)}>{translate('portal', 'cms_add_sub')}</button>
                                </div>
                            ) : null,
                        },
                    ]}
                    empty={translate('portal', 'cms_categories_empty')}
                />
            </section>

            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'cms_subcategories_title')}</h2>
                        <p className="muted">{translate('portal', 'cms_subcategories_hint')}</p>
                    </div>
                    <span className="badge">{subcategoryRows.length}</span>
                </div>
                <Table
                    rows={subcategoryRows as unknown as Row[]}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'cms_col_name'),
                            render: (r: any) => (
                                <>
                                    <b>{r.name}</b>
                                    <small className="cell-sub">{r.slug}</small>
                                </>
                            ),
                        },
                        {
                            key: 'category_name',
                            title: translate('portal', 'cms_col_category'),
                        },
                        {
                            key: 'sort_order',
                            title: translate('portal', 'cms_col_sort'),
                        },
                        {
                            key: 'is_active',
                            title: translate('portal', 'cms_col_status'),
                            render: (r: any) => <Badge status={r.is_active ? 'published' : 'draft'} />,
                        },
                        {
                            key: 'action',
                            title: '',
                            render: (r: any) => canManage ? (
                                <button type="button" className="text-link" onClick={() => openEditSub(r)}>{translate('portal', 'cms_edit')}</button>
                            ) : null,
                        },
                    ]}
                    empty={translate('portal', 'cms_subcategories_empty')}
                />
            </section>

            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2><Ruler size={16} style={{ marginRight: 6 }} />{translate('portal', 'cms_units_title')}</h2>
                        <p className="muted">{translate('portal', 'cms_units_hint')}</p>
                    </div>
                    <span className="badge">{units.length}</span>
                </div>
                <Table
                    rows={units}
                    columns={[
                        {
                            key: 'name',
                            title: translate('portal', 'cms_col_name'),
                            render: (r: UnitRow) => (
                                <>
                                    <b>{r.name}</b>
                                    <small className="cell-sub">{r.code}</small>
                                </>
                            ),
                        },
                        {
                            key: 'sort_order',
                            title: translate('portal', 'cms_col_sort'),
                        },
                        {
                            key: 'is_active',
                            title: translate('portal', 'cms_col_status'),
                            render: (r: UnitRow) => <Badge status={r.is_active ? 'published' : 'draft'} />,
                        },
                        {
                            key: 'action',
                            title: '',
                            render: (r: UnitRow) => canManage ? (
                                <button type="button" className="text-link" onClick={() => openEditUnit(r)}>{translate('portal', 'cms_edit')}</button>
                            ) : null,
                        },
                    ]}
                    empty={translate('portal', 'cms_units_empty')}
                />
            </section>

            {(createCategoryOpen || editCategory) && (
                <Modal
                    title={editCategory ? translate('portal', 'cms_edit_category') : translate('portal', 'cms_create_category')}
                    onClose={() => { setCreateCategoryOpen(false); setEditCategory(null); }}
                >
                    <form className="action-form" onSubmit={submitCategory}>
                        <label>
                            {translate('portal', 'cms_fld_name')}
                            <input value={categoryForm.data.name} onChange={(e) => categoryForm.setData('name', e.target.value)} required maxLength={150} />
                            {categoryForm.errors.name && <span className="field-error">{categoryForm.errors.name}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_slug')}
                            <input value={categoryForm.data.slug} onChange={(e) => categoryForm.setData('slug', e.target.value)} maxLength={160} placeholder={translate('portal', 'cms_ph_slug_auto')} />
                            {categoryForm.errors.slug && <span className="field-error">{categoryForm.errors.slug}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_sort')}
                            <input type="number" min={0} value={categoryForm.data.sort_order} onChange={(e) => categoryForm.setData('sort_order', Number(e.target.value))} />
                        </label>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <input type="checkbox" checked={!!categoryForm.data.is_active} onChange={(e) => categoryForm.setData('is_active', e.target.checked)} />
                            {translate('portal', 'cms_fld_active')}
                        </label>
                        <button className="button dark" disabled={categoryForm.processing}>
                            {categoryForm.processing ? translate('portal', 'cms_saving') : translate('portal', 'save_changes')}
                        </button>
                    </form>
                </Modal>
            )}

            {(createSubOpen || editSub) && (
                <Modal
                    title={editSub ? translate('portal', 'cms_edit_subcategory') : translate('portal', 'cms_create_subcategory')}
                    onClose={() => { setCreateSubOpen(false); setEditSub(null); }}
                >
                    <form className="action-form" onSubmit={submitSubcategory}>
                        <label>
                            {translate('portal', 'cms_fld_category')}
                            <select
                                value={subcategoryForm.data.product_category_id}
                                onChange={(e) => subcategoryForm.setData('product_category_id', e.target.value)}
                                required
                            >
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>{category.name}</option>
                                ))}
                            </select>
                            {subcategoryForm.errors.product_category_id && <span className="field-error">{subcategoryForm.errors.product_category_id}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_name')}
                            <input value={subcategoryForm.data.name} onChange={(e) => subcategoryForm.setData('name', e.target.value)} required maxLength={150} />
                            {subcategoryForm.errors.name && <span className="field-error">{subcategoryForm.errors.name}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_slug')}
                            <input value={subcategoryForm.data.slug} onChange={(e) => subcategoryForm.setData('slug', e.target.value)} maxLength={160} placeholder={translate('portal', 'cms_ph_slug_auto')} />
                            {subcategoryForm.errors.slug && <span className="field-error">{subcategoryForm.errors.slug}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_sort')}
                            <input type="number" min={0} value={subcategoryForm.data.sort_order} onChange={(e) => subcategoryForm.setData('sort_order', Number(e.target.value))} />
                        </label>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <input type="checkbox" checked={!!subcategoryForm.data.is_active} onChange={(e) => subcategoryForm.setData('is_active', e.target.checked)} />
                            {translate('portal', 'cms_fld_active')}
                        </label>
                        <button className="button dark" disabled={subcategoryForm.processing}>
                            {subcategoryForm.processing ? translate('portal', 'cms_saving') : translate('portal', 'save_changes')}
                        </button>
                    </form>
                </Modal>
            )}

            {(createUnitOpen || editUnit) && (
                <Modal
                    title={editUnit ? translate('portal', 'cms_edit_unit') : translate('portal', 'cms_create_unit')}
                    onClose={() => { setCreateUnitOpen(false); setEditUnit(null); }}
                >
                    <form className="action-form" onSubmit={submitUnit}>
                        <label>
                            {translate('portal', 'cms_fld_name')}
                            <input value={unitForm.data.name} onChange={(e) => unitForm.setData('name', e.target.value)} required maxLength={100} />
                            {unitForm.errors.name && <span className="field-error">{unitForm.errors.name}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_code')}
                            <input value={unitForm.data.code} onChange={(e) => unitForm.setData('code', e.target.value)} maxLength={80} placeholder={translate('portal', 'cms_ph_code_auto')} />
                            {unitForm.errors.code && <span className="field-error">{unitForm.errors.code}</span>}
                        </label>
                        <label>
                            {translate('portal', 'cms_fld_sort')}
                            <input type="number" min={0} value={unitForm.data.sort_order} onChange={(e) => unitForm.setData('sort_order', Number(e.target.value))} />
                        </label>
                        <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <input type="checkbox" checked={!!unitForm.data.is_active} onChange={(e) => unitForm.setData('is_active', e.target.checked)} />
                            {translate('portal', 'cms_fld_active')}
                        </label>
                        <button className="button dark" disabled={unitForm.processing}>
                            {unitForm.processing ? translate('portal', 'cms_saving') : translate('portal', 'save_changes')}
                        </button>
                    </form>
                </Modal>
            )}
        </div>
    );
}
