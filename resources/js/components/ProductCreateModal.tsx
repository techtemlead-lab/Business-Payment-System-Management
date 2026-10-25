import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { X, Upload, Sparkles, AlertCircle, Percent, Package } from 'lucide-react';
import { useT } from '../shared/i18n/t';

interface ProductCreateModalProps {
    isOpen: boolean;
    onClose: () => void;
    categories: string[];
}

export default function ProductCreateModal({ isOpen, onClose, categories }: ProductCreateModalProps) {
    const translate = useT();

    const [name, setName] = useState('');
    const [sku, setSku] = useState(`MNHC-${Math.floor(1000 + Math.random() * 9000)}`);
    const [category, setCategory] = useState(categories[0] || 'Unani & Herbal Medicine');
    const [customCategory, setCustomCategory] = useState('');
    const [isCustomCategory, setIsCustomCategory] = useState(false);
    const [unit, setUnit] = useState('pack');
    const [description, setDescription] = useState('');
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [imagePreview, setImagePreview] = useState<string | null>(null);

    // Pricing & Discount Engine
    const [mrp, setMrp] = useState('1000');
    const [discountPct, setDiscountPct] = useState('50');
    const [dealerPrice, setDealerPrice] = useState('500.00');

    // Publication & Inventory
    const [active, setActive] = useState('1');
    const [featured, setFeatured] = useState('0');
    const [initialStock, setInitialStock] = useState('0');
    const [lowStockAt, setLowStockAt] = useState('10');
    const [reason, setReason] = useState(() => translate('portal', 'pcm_default_reason'));

    // UI state
    const [submitting, setSubmitting] = useState(false);
    const [errorMsg, setErrorMsg] = useState<string | null>(null);

    // Dynamic two-way calculations
    const handleMrpChange = (newMrp: string) => {
        setMrp(newMrp);
        const numM = parseFloat(newMrp);
        const numD = parseFloat(discountPct);
        if (!isNaN(numM) && numM > 0 && !isNaN(numD) && numD >= 0 && numD <= 100) {
            setDealerPrice((numM * (1 - numD / 100)).toFixed(2));
        }
    };

    const handleDiscountChange = (newDisc: string) => {
        setDiscountPct(newDisc);
        const numM = parseFloat(mrp);
        const numD = parseFloat(newDisc);
        if (!isNaN(numM) && numM > 0 && !isNaN(numD) && numD >= 0 && numD <= 100) {
            setDealerPrice((numM * (1 - numD / 100)).toFixed(2));
        }
    };

    const handleDealerPriceChange = (newDealer: string) => {
        setDealerPrice(newDealer);
        const numM = parseFloat(mrp);
        const numP = parseFloat(newDealer);
        if (!isNaN(numM) && numM > 0 && !isNaN(numP) && numP >= 0 && numP <= numM) {
            setDiscountPct((((numM - numP) / numM) * 100).toFixed(1));
        }
    };

    const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0] ?? null;
        setImageFile(file);
        if (file) {
            const url = URL.createObjectURL(file);
            setImagePreview(url);
        } else {
            setImagePreview(null);
        }
    };

    const numMrp = parseFloat(mrp) || 0;
    const numDealer = parseFloat(dealerPrice) || 0;
    const numSavings = Math.max(0, numMrp - numDealer);
    const calculatedPct = numMrp > 0 ? ((numSavings / numMrp) * 100).toFixed(1) : '0';
    const cashSharePct = numMrp > 0 ? Math.min(100, Math.max(0, (numDealer / numMrp) * 100)) : 100;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setErrorMsg(null);

        if (!name.trim()) {
            setErrorMsg(translate('portal', 'pcm_err_title'));
            return;
        }

        const effectiveCategory = isCustomCategory ? customCategory.trim() : category.trim();
        if (!effectiveCategory) {
            setErrorMsg(translate('portal', 'pcm_err_category'));
            return;
        }

        if (numMrp <= 0) {
            setErrorMsg(translate('portal', 'pcm_err_mrp'));
            return;
        }

        if (numDealer <= 0 || numDealer > numMrp) {
            setErrorMsg(translate('portal', 'pcm_err_dealer'));
            return;
        }

        setSubmitting(true);

        const formData = new FormData();
        formData.append('name', name.trim());
        formData.append('sku', sku.trim());
        formData.append('category', effectiveCategory);
        formData.append('unit', unit.trim());
        formData.append('description', description.trim());
        formData.append('mrp', mrp);
        formData.append('dealer_price', dealerPrice);
        formData.append('dealer_discount_pct', discountPct);
        formData.append('active', active);
        formData.append('featured', featured);
        formData.append('initial_stock', initialStock);
        formData.append('low_stock_at', lowStockAt);
        formData.append('reason', reason.trim());
        formData.append('redirect_to', 'catalog');

        if (imageFile) {
            formData.append('image', imageFile);
        }

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

    if (!isOpen) return null;
    return (
        <div style={{
            position: 'fixed',
            inset: 0,
            zIndex: 1000,
            background: 'rgba(1, 22, 39, 0.72)',
            backdropFilter: 'blur(5px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            padding: 16,
            overflowY: 'auto'
        }}>
            <div style={{
                background: '#ffffff',
                borderRadius: 14,
                width: '100%',
                maxWidth: 680,
                maxHeight: '92vh',
                display: 'flex',
                flexDirection: 'column',
                boxShadow: '0 25px 60px rgba(0,0,0,0.3)',
                overflow: 'hidden',
                animation: 'modalSlideIn 0.2s ease-out'
            }}>
                {/* Header */}
                <div style={{
                    padding: '18px 24px',
                    borderBottom: '1px solid #e2e8f0',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    background: '#f8fafc'
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <div style={{ width: 34, height: 34, borderRadius: 8, background: '#ecfdf5', color: '#006837', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                            <Package size={20} />
                        </div>
                        <div>
                            <h2 style={{ fontSize: 17, fontWeight: 700, margin: 0, color: '#0f172a' }}>
                                {translate('portal', 'pcm_title')}
                            </h2>
                            <p style={{ margin: 0, fontSize: 12, color: '#64748b' }}>
                                {translate('portal', 'pcm_subtitle')}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        style={{
                            background: '#f1f5f9',
                            border: 'none',
                            width: 32,
                            height: 32,
                            borderRadius: '50%',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            color: '#64748b',
                            cursor: 'pointer'
                        }}
                    >
                        <X size={18} />
                    </button>
                </div>

                {/* Form Body */}
                <form onSubmit={handleSubmit} style={{ overflowY: 'auto', padding: '20px 24px', display: 'flex', flexDirection: 'column', gap: 20 }}>
                    {errorMsg && (
                        <div style={{ padding: '10px 14px', borderRadius: 8, background: '#fef2f2', border: '1px solid #fecaca', color: '#991b1b', fontSize: 13, display: 'flex', alignItems: 'center', gap: 8 }}>
                            <AlertCircle size={16} />
                            <span>{errorMsg}</span>
                        </div>
                    )}

                    {/* Section 1: Basic Information */}
                    <div>
                        <div style={{ fontSize: 13, fontWeight: 700, color: '#006837', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 12 }}>
                            {translate('portal', 'pcm_section_details')}
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: '1fr', gap: 14 }}>
                            <div>
                                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_title_label')}
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={name}
                                    placeholder={translate('portal', 'pcm_title_ph')}
                                    onChange={e => setName(e.target.value)}
                                    style={{ width: '100%', padding: '9px 12px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 13 }}
                                />
                            </div>

                            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
                                <div>
                                    <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                        {translate('portal', 'pcm_category')}
                                    </label>
                                    {!isCustomCategory ? (
                                        <select
                                            value={category}
                                            onChange={e => {
                                                if (e.target.value === '__new__') {
                                                    setIsCustomCategory(true);
                                                } else {
                                                    setCategory(e.target.value);
                                                }
                                            }}
                                            style={{ width: '100%', padding: '9px 12px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 13, background: '#fff' }}
                                        >
                                            {categories.map(c => <option key={c} value={c}>{c}</option>)}
                                            <option value="__new__">{translate('portal', 'pcm_new_category')}</option>
                                        </select>
                                    ) : (
                                        <div style={{ display: 'flex', gap: 6 }}>
                                            <input
                                                type="text"
                                                required
                                                placeholder={translate('portal', 'pcm_custom_category_ph')}
                                                value={customCategory}
                                                onChange={e => setCustomCategory(e.target.value)}
                                                style={{ flex: 1, padding: '9px 12px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 13 }}
                                            />
                                            <button
                                                type="button"
                                                onClick={() => setIsCustomCategory(false)}
                                                style={{ fontSize: 11, padding: '0 8px', background: '#f1f5f9', border: '1px solid #cbd5e1', borderRadius: 6, cursor: 'pointer' }}
                                            >
                                                {translate('portal', 'pcm_list')}
                                            </button>
                                        </div>
                                    )}
                                </div>

                                <div>
                                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                        <label style={{ fontSize: 13, fontWeight: 600, color: '#334155' }}>{translate('portal', 'pcm_sku')}</label>
                                        <button
                                            type="button"
                                            onClick={() => setSku(`MNHC-${Math.floor(1000 + Math.random() * 9000)}`)}
                                            style={{ fontSize: 11, color: '#006837', background: 'transparent', border: 'none', cursor: 'pointer', fontWeight: 600 }}
                                        >
                                            {translate('portal', 'pcm_auto')}
                                        </button>
                                    </div>
                                    <input
                                        type="text"
                                        required
                                        value={sku}
                                        onChange={e => setSku(e.target.value)}
                                        style={{ width: '100%', padding: '9px 12px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 13 }}
                                    />
                                </div>
                            </div>

                            {/* Image Upload & Thumbnail Preview */}
                            <div>
                                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_image')}
                                </label>
                                <div style={{ display: 'flex', gap: 14, alignItems: 'center' }}>
                                    <div style={{
                                        width: 80,
                                        height: 80,
                                        borderRadius: 8,
                                        border: '1.5px dashed #cbd5e1',
                                        background: '#f8fafc',
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        overflow: 'hidden',
                                        flexShrink: 0
                                    }}>
                                        {imagePreview ? (
                                            <img src={imagePreview} alt={translate('portal', 'pcm_preview')} style={{ width: '100%', height: '100%', objectFit: 'contain' }} />
                                        ) : (
                                            <Upload size={22} color="#94a3b8" />
                                        )}
                                    </div>
                                    <div style={{ flex: 1 }}>
                                        <input
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handleImageChange}
                                            style={{ fontSize: 12 }}
                                        />
                                        <p style={{ margin: '4px 0 0', fontSize: 11, color: '#94a3b8' }}>
                                            {translate('portal', 'pcm_image_hint')}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 13, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_description')}
                                </label>
                                <textarea
                                    rows={3}
                                    placeholder={translate('portal', 'pcm_description_ph')}
                                    value={description}
                                    onChange={e => setDescription(e.target.value)}
                                    style={{ width: '100%', padding: '9px 12px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 13 }}
                                />
                            </div>
                        </div>
                    </div>

                    {/* Section 2: Pricing & Dealer Discount Engine */}
                    <div style={{ background: '#f8fafc', border: '1.5px solid #e2e8f0', borderRadius: 10, padding: 18 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 13, fontWeight: 700, color: '#006837', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 14 }}>
                            <Percent size={16} /> {translate('portal', 'pcm_section_pricing')}
                        </div>

                        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14, marginBottom: 14 }}>
                            <div>
                                <label style={{ display: 'block', fontSize: 12.5, fontWeight: 700, color: '#1e293b', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_mrp')}
                                </label>
                                <div style={{ position: 'relative' }}>
                                    <span style={{ position: 'absolute', left: 10, top: 10, fontSize: 13, color: '#64748b', fontWeight: 600 }}>৳</span>
                                    <input
                                        type="number"
                                        min="1"
                                        step="0.01"
                                        required
                                        value={mrp}
                                        placeholder="1000"
                                        onChange={e => handleMrpChange(e.target.value)}
                                        style={{ width: '100%', padding: '9px 12px 9px 28px', border: '1.5px solid #cbd5e1', borderRadius: 7, fontSize: 14, fontWeight: 700, color: '#0f172a' }}
                                    />
                                </div>
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12.5, fontWeight: 700, color: '#1e293b', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_dealer_discount')}
                                </label>
                                <div style={{ position: 'relative' }}>
                                    <input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.5"
                                        value={discountPct}
                                        placeholder="50"
                                        onChange={e => handleDiscountChange(e.target.value)}
                                        style={{ width: '100%', padding: '9px 28px 9px 12px', border: '1.5px solid #cbd5e1', borderRadius: 7, fontSize: 14, fontWeight: 700, color: '#006837' }}
                                    />
                                    <span style={{ position: 'absolute', right: 10, top: 10, fontSize: 13, color: '#64748b', fontWeight: 700 }}>%</span>
                                </div>
                            </div>
                        </div>

                        {/* Quick Preset Pills */}
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 14 }}>
                            <span style={{ fontSize: 11, color: '#64748b', fontWeight: 600 }}>{translate('portal', 'pcm_quick_presets')}</span>
                            {['30', '40', '50', '60'].map(pct => (
                                <button
                                    key={pct}
                                    type="button"
                                    onClick={() => handleDiscountChange(pct)}
                                    style={{
                                        padding: '3px 10px',
                                        fontSize: 11,
                                        fontWeight: 700,
                                        borderRadius: 14,
                                        border: `1px solid ${discountPct === pct ? '#006837' : '#cbd5e1'}`,
                                        background: discountPct === pct ? '#006837' : '#ffffff',
                                        color: discountPct === pct ? '#ffffff' : '#334155',
                                        cursor: 'pointer',
                                        transition: 'all 0.15s ease'
                                    }}
                                >
                                    {pct}%
                                </button>
                            ))}
                        </div>

                        {/* Direct Editable Dealer Cash Price Input */}
                        <div style={{ marginBottom: 16 }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                <label style={{ fontSize: 12.5, fontWeight: 700, color: '#047857' }}>
                                    {translate('portal', 'pcm_dealer_price')}
                                </label>
                                <span style={{ fontSize: 11, color: '#64748b' }}>
                                    {translate('portal', 'pcm_dealer_price_hint')}
                                </span>
                            </div>
                            <div style={{ position: 'relative' }}>
                                <span style={{ position: 'absolute', left: 10, top: 10, fontSize: 13, color: '#047857', fontWeight: 700 }}>৳</span>
                                <input
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    required
                                    value={dealerPrice}
                                    placeholder="500.00"
                                    onChange={e => handleDealerPriceChange(e.target.value)}
                                    style={{ width: '100%', padding: '9px 12px 9px 28px', border: '1.5px solid #a7f3d0', borderRadius: 7, fontSize: 14, fontWeight: 800, color: '#047857', background: '#f0fdf4' }}
                                />
                            </div>
                        </div>

                        {/* Highlighted Financial Calculation Card */}
                        <div style={{
                            background: '#ffffff',
                            border: '1.5px solid #a7f3d0',
                            borderRadius: 10,
                            padding: '14px 16px',
                            boxShadow: '0 2px 6px rgba(0, 104, 55, 0.06)'
                        }}>
                            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 10, flexWrap: 'wrap', gap: 10 }}>
                                <div>
                                    <div style={{ fontSize: 11, fontWeight: 700, color: '#047857', textTransform: 'uppercase', letterSpacing: 0.5 }}>
                                        {translate('portal', 'pcm_cash_price')}
                                    </div>
                                    <div style={{ fontSize: 22, fontWeight: 800, color: '#006837' }}>
                                        ৳ {numDealer.toFixed(2)}
                                    </div>
                                    <div style={{ fontSize: 11, color: '#64748b', marginTop: 2 }}>
                                        {translate('portal', 'pcm_cash_price_hint')}
                                    </div>
                                </div>

                                <div style={{ textAlign: 'right' }}>
                                    <div style={{ fontSize: 11, fontWeight: 700, color: '#b45309', textTransform: 'uppercase', letterSpacing: 0.5 }}>
                                        {translate('portal', 'pcm_margin')}
                                    </div>
                                    <div style={{ fontSize: 20, fontWeight: 800, color: '#d97706' }}>
                                        ৳ {numSavings.toFixed(2)}
                                    </div>
                                    <div style={{ fontSize: 11, fontWeight: 600, color: '#059669', marginTop: 2 }}>
                                        {translate('portal', 'pcm_deduction', { pct: calculatedPct })}
                                    </div>
                                </div>
                            </div>

                            {/* Visual Progress Bar (Cash vs Margin) */}
                            <div style={{ width: '100%', height: 8, background: '#fef3c7', borderRadius: 4, overflow: 'hidden', display: 'flex' }}>
                                <div style={{ width: `${cashSharePct}%`, background: '#006837', transition: 'width 0.2s ease' }} title={translate('portal', 'pcm_system_cash', { pct: cashSharePct.toFixed(0) })} />
                                <div style={{ width: `${100 - cashSharePct}%`, background: '#f59e0b', transition: 'width 0.2s ease' }} title={translate('portal', 'pcm_dealer_margin', { pct: (100 - cashSharePct).toFixed(0) })} />
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10.5, color: '#64748b', marginTop: 4, fontWeight: 600 }}>
                                <span>🟢 {translate('portal', 'pcm_system_cash', { pct: cashSharePct.toFixed(0) })}</span>
                                <span>🟡 {translate('portal', 'pcm_dealer_margin', { pct: (100 - cashSharePct).toFixed(0) })}</span>
                            </div>
                        </div>
                    </div>

                    {/* Section 3: Publishing & Initial Stock */}
                    <div>
                        <div style={{ fontSize: 13, fontWeight: 700, color: '#006837', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 12 }}>
                            {translate('portal', 'pcm_section_publish')}
                        </div>
                        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))', gap: 12 }}>
                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_publication')}
                                </label>
                                <select
                                    value={active}
                                    onChange={e => setActive(e.target.value)}
                                    style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 12.5 }}
                                >
                                    <option value="1">{translate('portal', 'pcm_published')}</option>
                                    <option value="0">{translate('portal', 'pcm_draft')}</option>
                                </select>
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_placement')}
                                </label>
                                <select
                                    value={featured}
                                    onChange={e => setFeatured(e.target.value)}
                                    style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 12.5 }}
                                >
                                    <option value="0">{translate('portal', 'pcm_standard')}</option>
                                    <option value="1">{translate('portal', 'pcm_featured')}</option>
                                </select>
                            </div>

                            <div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                    <label style={{ fontSize: 12, fontWeight: 600, color: '#334155' }}>{translate('portal', 'pcm_initial_stock')}</label>
                                    {parseInt(initialStock, 10) > 0 ? (
                                        <span style={{ fontSize: 10.5, fontWeight: 700, color: '#059669', background: '#ecfdf5', padding: '1px 5px', borderRadius: 4 }}>
                                            {translate('portal', 'pcm_in_stock')}
                                        </span>
                                    ) : (
                                        <span style={{ fontSize: 10.5, fontWeight: 700, color: '#dc2626', background: '#fef2f2', padding: '1px 5px', borderRadius: 4 }}>
                                            {translate('portal', 'pcm_out_of_stock')}
                                        </span>
                                    )}
                                </div>
                                <input
                                    type="number"
                                    min="0"
                                    value={initialStock}
                                    placeholder={translate('portal', 'pcm_stock_ph')}
                                    onChange={e => setInitialStock(e.target.value)}
                                    style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 12.5 }}
                                />
                            </div>

                            <div>
                                <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                    {translate('portal', 'pcm_low_stock')}
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    value={lowStockAt}
                                    onChange={e => setLowStockAt(e.target.value)}
                                    style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 12.5 }}
                                />
                            </div>
                        </div>

                        <div style={{ marginTop: 12 }}>
                            <label style={{ display: 'block', fontSize: 12, fontWeight: 600, color: '#334155', marginBottom: 4 }}>
                                {translate('portal', 'pcm_audit_note')}
                            </label>
                            <input
                                type="text"
                                required
                                value={reason}
                                onChange={e => setReason(e.target.value)}
                                style={{ width: '100%', padding: '8px 10px', border: '1px solid #cbd5e1', borderRadius: 7, fontSize: 12 }}
                            />
                        </div>
                    </div>

                    {/* Footer Actions */}
                    <div style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'flex-end',
                        gap: 12,
                        paddingTop: 12,
                        borderTop: '1px solid #e2e8f0',
                        marginTop: 4
                    }}>
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={submitting}
                            style={{
                                padding: '9px 18px',
                                borderRadius: 7,
                                border: '1px solid #cbd5e1',
                                background: '#fff',
                                color: '#475569',
                                fontSize: 13,
                                fontWeight: 600,
                                cursor: 'pointer'
                            }}
                        >
                            {translate('portal', 'pcm_cancel')}
                        </button>
                        <button
                            type="submit"
                            disabled={submitting}
                            style={{
                                padding: '9px 22px',
                                borderRadius: 7,
                                border: 'none',
                                background: submitting ? '#94a3b8' : '#006837',
                                color: '#fff',
                                fontSize: 13,
                                fontWeight: 700,
                                cursor: submitting ? 'not-allowed' : 'pointer',
                                boxShadow: '0 2px 6px rgba(0, 104, 55, 0.25)',
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 6
                            }}
                        >
                            <Sparkles size={16} />
                            {submitting ? translate('portal', 'pcm_creating') : translate('portal', 'pcm_create')}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
