import React, { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { Upload, AlertCircle } from 'lucide-react';
import { Modal } from './UI';
import { useT } from '../shared/i18n/t';
import { suggestSku } from '../shared/sku';
import { useProductPhotoFit } from '../shared/productPhotoFit';
import ProductPhotoCutout from './ProductPhotoCutout';
import SearchSelect from './SearchSelect';
import ProductPhotoFitNote from './ProductPhotoFitNote';

type CatalogSubcategory = { id: number; name: string; is_active?: boolean };
type CatalogCategory = { id: number; name: string; is_active?: boolean; subcategories?: CatalogSubcategory[] };
type CatalogUnit = { id: number; name: string; code?: string; is_active?: boolean };

interface ProductCreateModalProps {
    isOpen: boolean;
    onClose: () => void;
    categories?: CatalogCategory[];
    units?: CatalogUnit[];
    maxDealerPct?: number;
    maxSubDealerPct?: number;
}

type AddTarget = 'category' | 'subcategory' | 'unit';

function csrfToken(): string {
    const row = document.cookie.split('; ').find((item) => item.startsWith('XSRF-TOKEN='));
    return decodeURIComponent(row?.split('=').slice(1).join('=') || '');
}

async function postJson(url: string, body: Record<string, unknown>): Promise<Record<string, unknown>> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = payload && typeof payload === 'object' && 'errors' in payload
            ? Object.values((payload as { errors: Record<string, string[]> }).errors).flat()
            : [];
        throw new Error(errors.join(' ') || (payload as { message?: string }).message || 'Request failed');
    }
    return payload as Record<string, unknown>;
}

function priceFromPercent(mrp: string, percent: string): string {
    const retail = parseFloat(mrp);
    const rate = parseFloat(percent);
    if (Number.isNaN(retail) || retail <= 0 || Number.isNaN(rate) || rate < 0 || rate > 100) {
        return '';
    }
    return (retail * (1 - rate / 100)).toFixed(2);
}

function percentFromPrice(mrp: string, price: string): string {
    const retail = parseFloat(mrp);
    const amount = parseFloat(price);
    if (Number.isNaN(retail) || retail <= 0 || Number.isNaN(amount) || amount < 0 || amount > retail) {
        return '';
    }
    return (((retail - amount) / retail) * 100).toFixed(1);
}

export default function ProductCreateModal({
    isOpen,
    onClose,
    categories = [],
    units = [],
    maxDealerPct = 50,
    maxSubDealerPct = 40,
}: ProductCreateModalProps) {
    const translate = useT();
    const [catalog, setCatalog] = useState<CatalogCategory[]>(categories);
    const [unitList, setUnitList] = useState<CatalogUnit[]>(units);
    const [name, setName] = useState('');
    const [sku, setSku] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [subcategoryId, setSubcategoryId] = useState('');
    const [unitId, setUnitId] = useState('');
    const [strength, setStrength] = useState('');
    const [description, setDescription] = useState('');
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [cutoutFile, setCutoutFile] = useState<File | null>(null);
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const [mrp, setMrp] = useState('');
    const [dealerPct, setDealerPct] = useState('');
    const [dealerPrice, setDealerPrice] = useState('');
    const [subDealerPct, setSubDealerPct] = useState('');
    const [subDealerPrice, setSubDealerPrice] = useState('');
    const [active, setActive] = useState('1');
    const [featured, setFeatured] = useState('0');
    const [initialStock, setInitialStock] = useState('0');
    const [lowStockAt, setLowStockAt] = useState('10');
    const [drafts, setDrafts] = useState<Record<AddTarget, string>>({ category: '', subcategory: '', unit: '' });
    const [savingTarget, setSavingTarget] = useState<AddTarget | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [errorMsg, setErrorMsg] = useState<string | null>(null);
    const formRef = useRef<HTMLFormElement>(null);
    const photoInput = useRef<HTMLInputElement>(null);
    // A photo too heavy or too wide for the server is made smaller here, before anything is sent.
    const photoFit = useProductPhotoFit((photo) => {
        setImageFile(photo);
        setCutoutFile(null);
        setImagePreview(photo ? URL.createObjectURL(photo) : null);
        if (!photo && photoInput.current) {
            // A photo that cannot be used must not stay named in the box.
            photoInput.current.value = '';
        }
    });

    // Refresh the pick lists when the page data changes, without touching what was typed.
    useEffect(() => {
        setCatalog(categories);
        setUnitList(units);
    }, [categories, units]);

    // Start clean only when the dialog opens, so a refused save keeps every value.
    useEffect(() => {
        if (!isOpen) {
            return;
        }
        setName('');
        setSku(suggestSku());
        setCategoryId('');
        setSubcategoryId('');
        setUnitId('');
        setStrength('');
        setDescription('');
        setImageFile(null);
        setCutoutFile(null);
        setImagePreview(null);
        photoFit.forget();
        setMrp('');
        setDealerPct('');
        setDealerPrice('');
        setSubDealerPct('');
        setSubDealerPrice('');
        setActive('1');
        setFeatured('0');
        setInitialStock('0');
        setLowStockAt('10');
        setDrafts({ category: '', subcategory: '', unit: '' });
        setErrorMsg(null);
    }, [isOpen]);

    // A refused save scrolls back up so the reason is on screen.
    useEffect(() => {
        if (errorMsg) {
            formRef.current?.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }, [errorMsg]);

    const activeCategories = useMemo(
        () => catalog.filter((category) => category.is_active !== false),
        [catalog],
    );
    const selectedCategory = activeCategories.find((category) => String(category.id) === categoryId);
    const subcategories = (selectedCategory?.subcategories ?? []).filter((item) => item.is_active !== false);
    const activeUnits = unitList.filter((unit) => unit.is_active !== false);

    const changeMrp = (value: string) => {
        setMrp(value);
        setDealerPrice(priceFromPercent(value, dealerPct));
        setSubDealerPrice(priceFromPercent(value, subDealerPct));
    };

    const saveTaxonomy = async (target: AddTarget) => {
        const label = drafts[target].trim();
        if (!label || (target === 'subcategory' && !categoryId)) {
            return;
        }
        setSavingTarget(target);
        setErrorMsg(null);
        try {
            if (target === 'category') {
                const payload = await postJson('/commands/product-categories', { name: label, is_active: true });
                const created = payload.data as CatalogCategory;
                setCatalog((current) => [...current, { ...created, subcategories: created.subcategories ?? [] }]);
                setCategoryId(String(created.id));
                setSubcategoryId('');
            }
            if (target === 'subcategory') {
                const payload = await postJson('/commands/product-subcategories', {
                    product_category_id: Number(categoryId),
                    name: label,
                    is_active: true,
                });
                const created = payload.data as CatalogSubcategory;
                setCatalog((current) => current.map((category) => (
                    String(category.id) === categoryId
                        ? { ...category, subcategories: [...(category.subcategories ?? []), created] }
                        : category
                )));
                setSubcategoryId(String(created.id));
            }
            if (target === 'unit') {
                const payload = await postJson('/commands/product-units', { name: label, is_active: true });
                const created = payload.data as CatalogUnit;
                setUnitList((current) => [...current, created]);
                setUnitId(String(created.id));
            }
            setDrafts((current) => ({ ...current, [target]: '' }));
        } catch (error) {
            setErrorMsg(error instanceof Error ? error.message : translate('portal', 'pcm_err_taxonomy'));
        } finally {
            setSavingTarget(null);
        }
    };

    const handleSubmit = (event: React.FormEvent) => {
        event.preventDefault();
        if (photoFit.busy) {
            return;
        }
        setErrorMsg(null);
        const retail = parseFloat(mrp);
        const dealer = parseFloat(dealerPrice);
        const subDealer = parseFloat(subDealerPrice);
        const dealerRate = parseFloat(dealerPct);
        const subRate = parseFloat(subDealerPct);
        if (!name.trim()) {
            setErrorMsg(translate('portal', 'pcm_err_title'));
            return;
        }
        if (!categoryId || !selectedCategory) {
            setErrorMsg(translate('portal', 'pcm_err_category'));
            return;
        }
        if (!subcategoryId) {
            setErrorMsg(translate('portal', 'pcm_err_subcategory'));
            return;
        }
        if (!unitId) {
            setErrorMsg(translate('portal', 'pcm_err_unit'));
            return;
        }
        if (Number.isNaN(retail) || retail <= 0) {
            setErrorMsg(translate('portal', 'pcm_err_mrp'));
            return;
        }
        if (Number.isNaN(dealer) || dealer <= 0 || dealer > retail || (!Number.isNaN(dealerRate) && dealerRate > maxDealerPct)) {
            setErrorMsg(translate('portal', 'pcm_err_dealer'));
            return;
        }
        if (Number.isNaN(subDealer) || subDealer <= 0 || subDealer > retail || (!Number.isNaN(subRate) && subRate > maxSubDealerPct)) {
            setErrorMsg(translate('portal', 'pcm_err_subdealer'));
            return;
        }

        const subcategory = subcategories.find((item) => String(item.id) === subcategoryId);
        const unit = activeUnits.find((item) => String(item.id) === unitId);
        const formData = new FormData();
        formData.append('name', name.trim());
        formData.append('sku', sku.trim());
        formData.append('category', selectedCategory.name);
        formData.append('sub_category', subcategory?.name ?? '');
        formData.append('product_category_id', categoryId);
        formData.append('product_subcategory_id', subcategoryId);
        formData.append('unit', unit?.name ?? '');
        formData.append('product_unit_id', unitId);
        formData.append('strength', strength.trim());
        formData.append('description', description.trim());
        formData.append('mrp', retail.toFixed(2));
        formData.append('dealer_price', dealer.toFixed(2));
        formData.append('dealer_discount_pct', dealerPct || '0');
        formData.append('sub_dealer_price', subDealer.toFixed(2));
        formData.append('sub_dealer_discount_pct', subDealerPct || '0');
        formData.append('active', active);
        formData.append('featured', featured);
        formData.append('initial_stock', initialStock || '0');
        formData.append('low_stock_at', lowStockAt || '0');
        formData.append('reason', translate('portal', 'pcm_created_reason'));
        formData.append('redirect_to', 'catalog');
        if (imageFile) {
            formData.append('image', cutoutFile ?? imageFile);
        }
        if (imageFile && cutoutFile) {
            formData.append('image_original', imageFile);
        }

        setSubmitting(true);
        router.post('/commands/products', formData, {
            forceFormData: true,
            onSuccess: () => {
                setSubmitting(false);
                onClose();
            },
            onError: (errors) => {
                setSubmitting(false);
                setErrorMsg(Object.values(errors).join(' '));
            },
        });
    };

    if (!isOpen || typeof document === 'undefined') {
        return null;
    }

    const choice = (
        target: AddTarget,
        label: string,
        value: string,
        options: { id: number; name: string }[],
        onChange: (value: string) => void,
        disabled = false,
    ) => (
        <label className="pcm-field">
            <span>{label}</span>
            <SearchSelect
                value={value}
                disabled={disabled}
                onChange={(next) => onChange(next)}
                emptyLabel={translate('portal', 'pcm_select')}
                options={options.map((option) => ({ value: String(option.id), label: option.name }))}
            />
            <span className="pcm-create">
                <input
                    value={drafts[target]}
                    disabled={disabled}
                    placeholder={translate('portal', 'pcm_create_ph')}
                    onChange={(event) => setDrafts((current) => ({ ...current, [target]: event.target.value }))}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void saveTaxonomy(target);
                        }
                    }}
                />
                <button type="button" className="pcm-create-btn" disabled={disabled || savingTarget === target || !drafts[target].trim()} onClick={() => void saveTaxonomy(target)}>
                    {translate('portal', 'pcm_create_now')}
                </button>
            </span>
        </label>
    );

    return (
        <Modal title={translate('portal', 'pcm_title')} onClose={onClose} variant="wide">
                <form className="pcm-form" ref={formRef} onSubmit={handleSubmit}>
                    {errorMsg && (
                        <div className="pcm-error" role="alert">
                            <AlertCircle size={16} />
                            <span>{errorMsg}</span>
                        </div>
                    )}

                    <section>
                        <h3>{translate('portal', 'pcm_section_details')}</h3>
                        <div className="pcm-grid">
                            <label className="pcm-field pcm-span">
                                <span>{translate('portal', 'pcm_title_label')}</span>
                                <input required value={name} placeholder={translate('portal', 'pcm_title_ph')} onChange={(event) => setName(event.target.value)} />
                            </label>
                            <label className="pcm-field">
                                <span className="pcm-label-row">
                                    {translate('portal', 'pcm_sku')}
                                    <button type="button" className="pcm-text" onClick={() => setSku(suggestSku())}>
                                        {translate('portal', 'pcm_auto')}
                                    </button>
                                </span>
                                <input required value={sku} onChange={(event) => setSku(event.target.value)} />
                            </label>
                            <label className="pcm-field">
                                <span>{translate('portal', 'pcm_strength')}</span>
                                <input value={strength} placeholder={translate('portal', 'pcm_strength_ph')} onChange={(event) => setStrength(event.target.value)} />
                            </label>
                            {choice('category', translate('portal', 'pcm_category'), categoryId, activeCategories, (value) => {
                                setCategoryId(value);
                                setSubcategoryId('');
                            })}
                            {choice('subcategory', translate('portal', 'pcm_subcategory'), subcategoryId, subcategories, setSubcategoryId, !categoryId)}
                            {choice('unit', translate('portal', 'pcm_unit'), unitId, activeUnits, setUnitId)}
                            <label className="pcm-field pcm-span">
                                <span>{translate('portal', 'pcm_image')}</span>
                                <span className="pcm-image">
                                    <span className="pcm-thumb">
                                        {imagePreview ? <img src={imagePreview} alt={translate('portal', 'pcm_preview')} /> : <Upload size={18} />}
                                    </span>
                                    <span>
                                        <input ref={photoInput} type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => photoFit.choose(event.target.files?.[0] ?? null)} />
                                        <small>{translate('portal', 'pcm_image_hint')}</small>
                                    </span>
                                </span>
                            </label>
                            <div className="pcm-span">
                                <ProductPhotoFitNote status={photoFit.status} />
                                <ProductPhotoCutout
                                    photo={imageFile}
                                    onUse={(cutout) => {
                                        setCutoutFile(cutout);
                                        setImagePreview(URL.createObjectURL(cutout));
                                    }}
                                    onUndo={() => {
                                        setCutoutFile(null);
                                        setImagePreview(imageFile ? URL.createObjectURL(imageFile) : null);
                                    }}
                                />
                            </div>
                            <label className="pcm-field pcm-span">
                                <span>{translate('portal', 'pcm_description')}</span>
                                <textarea rows={3} value={description} placeholder={translate('portal', 'pcm_description_ph')} onChange={(event) => setDescription(event.target.value)} />
                            </label>
                        </div>
                    </section>

                    <section>
                        <h3>{translate('portal', 'pcm_section_pricing')}</h3>
                        <div className="pcm-price">
                            <label className="pcm-field pcm-price-mrp">
                                <span>{translate('portal', 'pcm_mrp')}</span>
                                <input type="number" min="0.01" step="0.01" required value={mrp} onChange={(event) => changeMrp(event.target.value)} />
                            </label>
                            <div className="pcm-price-line">
                                <label className="pcm-field">
                                    <span>{translate('portal', 'pcm_dealer_discount')}</span>
                                    <input type="number" min="0" max={maxDealerPct} step="0.1" value={dealerPct} onChange={(event) => {
                                        setDealerPct(event.target.value);
                                        setDealerPrice(priceFromPercent(mrp, event.target.value));
                                    }} />
                                </label>
                                <label className="pcm-field">
                                    <span>{translate('portal', 'pcm_dealer_price')}</span>
                                    <input type="number" min="0.01" step="0.01" required value={dealerPrice} onChange={(event) => {
                                        setDealerPrice(event.target.value);
                                        setDealerPct(percentFromPrice(mrp, event.target.value));
                                    }} />
                                </label>
                            </div>
                            <div className="pcm-price-line">
                                <label className="pcm-field">
                                    <span>{translate('portal', 'pcm_subdealer_discount')}</span>
                                    <input type="number" min="0" max={maxSubDealerPct} step="0.1" value={subDealerPct} onChange={(event) => {
                                        setSubDealerPct(event.target.value);
                                        setSubDealerPrice(priceFromPercent(mrp, event.target.value));
                                    }} />
                                </label>
                                <label className="pcm-field">
                                    <span>{translate('portal', 'pcm_subdealer_price')}</span>
                                    <input type="number" min="0.01" step="0.01" required value={subDealerPrice} onChange={(event) => {
                                        setSubDealerPrice(event.target.value);
                                        setSubDealerPct(percentFromPrice(mrp, event.target.value));
                                    }} />
                                </label>
                            </div>
                        </div>
                    </section>

                    <section>
                        <h3>{translate('portal', 'pcm_section_publish')}</h3>
                        <div className="pcm-grid">
                            <label className="pcm-field">
                                <span>{translate('portal', 'pcm_publication')}</span>
                                <select value={active} onChange={(event) => setActive(event.target.value)}>
                                    <option value="1">{translate('portal', 'pcm_published')}</option>
                                    <option value="0">{translate('portal', 'pcm_draft')}</option>
                                </select>
                            </label>
                            <label className="pcm-field">
                                <span>{translate('portal', 'pcm_placement')}</span>
                                <select value={featured} onChange={(event) => setFeatured(event.target.value)}>
                                    <option value="0">{translate('portal', 'pcm_standard')}</option>
                                    <option value="1">{translate('portal', 'pcm_featured')}</option>
                                </select>
                            </label>
                            <label className="pcm-field">
                                <span>{translate('portal', 'pcm_initial_stock')}</span>
                                <input type="number" min="0" value={initialStock} onChange={(event) => setInitialStock(event.target.value)} />
                            </label>
                            <label className="pcm-field">
                                <span>{translate('portal', 'pcm_low_stock')}</span>
                                <input type="number" min="0" value={lowStockAt} onChange={(event) => setLowStockAt(event.target.value)} />
                            </label>
                        </div>
                    </section>

                    <footer className="pcm-footer">
                        <button type="button" className="pcm-secondary" onClick={onClose} disabled={submitting}>
                            {translate('portal', 'pcm_cancel')}
                        </button>
                        <button type="submit" className="pcm-primary" disabled={submitting || photoFit.busy}>
                            {submitting ? translate('portal', 'pcm_creating') : translate('portal', 'pcm_create')}
                        </button>
                    </footer>
                </form>
        </Modal>
    );
}
