import { useForm } from '@inertiajs/react';
import { Row, label } from './UI';

const profileFields = ['name_bn', 'father_name', 'mother_name', 'village', 'post_office', 'upazila', 'union', 'pharmacy'];

export default function AccountEditor({ account, onDone }: { account: Row; onDone: () => void }) {
    const form = useForm({ name: account.name, phone: account.phone ?? '', division: account.division ?? '', district: account.district ?? '',
        directory_visible: account.directory_visible ? '1' : '0', status: account.status,
        profile: Object.fromEntries(profileFields.map(field => [field, account.profile?.[field] ?? ''])), reason: '' });
    return <form className="action-form" onSubmit={event => {
        event.preventDefault();
        form.patch(`/commands/accounts/${account.id}`, { onSuccess: onDone });
    }}>
        <p className="muted">Company-controlled corrections are recorded with a reason. NID is restricted and never included in this form.</p>
        <div className="form-grid">
            {(['name', 'phone', 'division', 'district'] as const).map(field => <label key={field}>{label(field)}
                <input required={field === 'name'} value={form.data[field]} onChange={event => form.setData(field, event.target.value)} />
            </label>)}
            {profileFields.map(field => <label key={field}>{label(field)}<input maxLength={150} value={form.data.profile[field]} onChange={event => form.setData('profile', { ...form.data.profile, [field]: event.target.value })} /></label>)}
            <label>Status<select value={form.data.status} onChange={event => form.setData('status', event.target.value)}><option value="active">Active</option><option value="suspended">Suspended</option></select></label>
            {account.role === 'dealer' && <label>Approved business contact visibility<select value={form.data.directory_visible} onChange={event => form.setData('directory_visible', event.target.value)}><option value="0">Private</option><option value="1">Publish name, business phone and district</option></select></label>}
        </div>
        <label>Correction / status-change reason<textarea required maxLength={1000} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} /></label>
        {Object.keys(form.errors).length > 0 && <div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
        <button className="button dark" disabled={form.processing}>Save recorded correction</button>
    </form>;
}
