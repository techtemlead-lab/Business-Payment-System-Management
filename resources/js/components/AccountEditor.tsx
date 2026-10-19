import { useForm } from '@inertiajs/react';
import { Row, label } from './UI';

const profileFields = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy', 'area_code'];

export default function AccountEditor({ account, onDone }: { account: Row; onDone: () => void }) {
    const isPartner = ['dealer', 'sub_dealer'].includes(account.role);
    const form = useForm({
        name: account.name,
        dealer_code: account.dealer_code ?? '',
        phone: account.phone ?? '',
        division: account.division ?? '',
        district: account.district ?? '',
        latitude: account.latitude ?? '',
        longitude: account.longitude ?? '',
        directory_visible: account.directory_visible ? '1' : '0',
        status: account.status,
        profile: Object.fromEntries(profileFields.map(field => [field, account.profile?.[field] ?? ''])),
        reason: '',
    });
    return <form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.patch(`/commands/accounts/${account.id}`, { onSuccess: onDone });
    }}>
        <p className="muted">Company-controlled corrections are recorded with a reason. NID is restricted and never included in this form.</p>
        <div className="form-grid">
            <label>Full name *
                <input required value={form.data.name} onChange={event => form.setData('name', event.target.value)} />
            </label>
            {isPartner && <label>Dealer code (Unique)
                <input value={form.data.dealer_code} onChange={event => form.setData('dealer_code', event.target.value.toUpperCase())} />
            </label>}
            <label>Phone number
                <input value={form.data.phone} onChange={event => form.setData('phone', event.target.value)} />
            </label>
            <label>Division
                <input value={form.data.division} onChange={event => form.setData('division', event.target.value)} />
            </label>
            <label>District
                <input value={form.data.district} onChange={event => form.setData('district', event.target.value)} />
            </label>
            {isPartner && <>
                <label>Map latitude
                    <input type="number" step="any" min="-90" max="90" value={form.data.latitude} onChange={event => form.setData('latitude', event.target.value)} placeholder="e.g. 25.7701" />
                    {form.errors.latitude && <span className="field-error">{form.errors.latitude}</span>}
                </label>
                <label>Map longitude
                    <input type="number" step="any" min="-180" max="180" value={form.data.longitude} onChange={event => form.setData('longitude', event.target.value)} placeholder="e.g. 88.8240" />
                    {form.errors.longitude && <span className="field-error">{form.errors.longitude}</span>}
                </label>
            </>}
            {profileFields.map(field => <label key={field}>{label(field)}<input maxLength={150} value={form.data.profile[field]} onChange={event => form.setData('profile', { ...form.data.profile, [field]: event.target.value })} /></label>)}
            <label>Status<select value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="active">Active</option><option value="suspended">Suspended</option></select></label>
            {account.role === 'dealer' && <label>Approved business contact visibility<select value={form.data.directory_visible} onChange={event => form.setData('directory_visible', event.target.value)}><option value="0">Private</option><option value="1">Publish name, business phone and district</option></select></label>}
        </div>
        <label>Correction / status-change reason *<textarea required maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
        {Object.keys(form.errors).length > 0 && <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
        <button className="button dark" disabled={form.processing}>Save recorded correction</button>
    </form>;
}
