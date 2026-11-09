import React, { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import { Badge, FormErrors, Modal, date, label, number, type Row } from './UI';
import AccountPasswordManager from './AccountPasswordManager';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from './MoneyTable';
import type { OfficeHub } from './OfficeHubTabs';
import OfficePrescriberGrants from './OfficePrescriberGrants';
import OwnerAccountsPanel, { type OwnerAccount } from './OwnerAccountsPanel';
import Figures from './office/Figures';
import OfficePage from './office/OfficePage';
import { DrawerLines, ViewSwitch, currentView } from './office/sections/staffSectionParts';
import { useT } from '../shared/i18n/t';

export interface PermissionDefinition {
    key: string;
    label: string;
    description: string;
    category: string;
}

export interface EmployeeHubProps {
    employees: { data: Row[]; table: Omit<MoneyTableData<Row>, 'rows'> } & Record<string, unknown>;
    availablePermissions: PermissionDefinition[];
    stats: {
        total: number;
        active: number;
        suspended: number;
    };
    filters?: Row;
    ownerAccounts?: OwnerAccount[];
    hub?: OfficeHub | null;
}

type StaffView = 'staff' | 'prescribers' | 'owners';
type Translate = ReturnType<typeof useT>;

/** Permissions an SR can hold. Mirrors the server. */
const SR_PERMISSIONS = ['field_sales', 'sr_orders', 'sr_onboarding'];

const PRESETS: { word: string; keys: string[]; onlyWhenAdding?: boolean }[] = [
    { word: 'emp_preset_orders', keys: ['orders', 'order_review', 'reports'] },
    { word: 'emp_preset_warehouse', keys: ['inventory', 'catalog'] },
    { word: 'emp_preset_dealer', keys: ['accounts'], onlyWhenAdding: true },
    { word: 'emp_preset_finance', keys: ['finance', 'cost_read', 'reports'] },
];

const roleName = (employee: Row, translate: Translate): string => (employee.role ? (employee.role_name || label(String(employee.role), translate)) : '—');

const permissionName = (key: string, available: PermissionDefinition[], translate: Translate): string => {
    const known = available.find((item) => item.key === key);
    const word = `perm_${key}`;
    const name = translate('portal', word);

    return known?.label || (name !== word ? name : label(key, translate));
};

function PermissionPicker({ available, selected, onChange, adding, onlyKeys }: {
    available: PermissionDefinition[];
    selected: string[];
    onChange: (keys: string[]) => void;
    adding: boolean;
    onlyKeys?: string[];
}) {
    const translate = useT();
    const shown = onlyKeys ? available.filter((item) => onlyKeys.includes(item.key)) : available;
    const categories = Array.from(new Set(shown.map((item) => item.category)));
    const applyPreset = (keys: string[]) => onChange(onlyKeys ? keys.filter((key) => onlyKeys.includes(key)) : [...keys]);
    const toggle = (key: string) => onChange(selected.includes(key) ? selected.filter((item) => item !== key) : [...selected, key]);

    return (
        <fieldset className="staff-permissions">
            <legend>{translate('portal', 'emp_perms_select')} <small>{translate('portal', 'emp_caps_selected', { count: number(selected.length) })}</small></legend>
            <div className="ops-row is-wrap is-snug">
                <button type="button" className="button small" onClick={() => applyPreset(available.map((item) => item.key))}>{translate('portal', 'emp_preset_all')}</button>
                {PRESETS.filter((preset) => adding || !preset.onlyWhenAdding).map((preset) => (
                    <button key={preset.word} type="button" className="button small" onClick={() => applyPreset(preset.keys)}>{translate('portal', preset.word)}</button>
                ))}
                <button type="button" className="button small" onClick={() => applyPreset([])}>{translate('portal', 'emp_preset_clear')}</button>
            </div>
            {categories.map((category) => (
                <div key={category}>
                    <div className="emp-cat-title">{translate('portal', 'emp_cat_modules', { cat: String(category) })}</div>
                    <div className="hub-grid is-wide emp-option-grid">
                        {shown.filter((item) => item.category === category).map((item) => {
                            const checked = selected.includes(item.key);

                            return (
                                <label key={item.key} className={`emp-option${checked ? ' is-checked' : ''}`}>
                                    <input type="checkbox" checked={checked} onChange={() => toggle(item.key)} className="ops-mt-1" />
                                    <div>
                                        <b className="emp-option-title">{item.label}</b>
                                        <small className="ops-block ops-meta emp-option-hint">{item.description}</small>
                                    </div>
                                </label>
                            );
                        })}
                    </div>
                </div>
            ))}
        </fieldset>
    );
}

function AddStaffDialog({ available, onClose }: { available: PermissionDefinition[]; onClose: () => void }) {
    const translate = useT();
    const form = useForm({
        role: 'employee',
        name: '',
        email: '',
        phone: '',
        status: 'active',
        reason: translate('portal', 'emp_reason_onboard'),
        permissions: ['orders', 'inventory'] as string[],
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post('/commands/accounts', { onSuccess: onClose });
    };

    return (
        <Modal title={translate('office_staff', 'add_staff')} onClose={onClose} variant="wide">
            <form onSubmit={submit} className="action-form">
                <label>
                    {translate('office_staff', 'field_full_name')}
                    <input required placeholder={translate('portal', 'emp_ph_full_name')} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                    {form.errors.name && <span className="field-error">{form.errors.name}</span>}
                </label>
                <label>
                    {translate('portal', 'emp_fld_email')}
                    <input required type="email" placeholder={translate('portal', 'emp_ph_email')} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} />
                    {form.errors.email && <span className="field-error">{form.errors.email}</span>}
                </label>
                <label>
                    {translate('portal', 'emp_fld_phone_login')}
                    <input required placeholder={translate('portal', 'emp_ph_phone')} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />
                    {form.errors.phone && <span className="field-error">{form.errors.phone}</span>}
                </label>
                <label>
                    {translate('portal', 'emp_fld_initial_status')}
                    <select value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                        <option value="active">{translate('portal', 'emp_opt_active_login')}</option>
                        <option value="suspended">{translate('portal', 'emp_opt_suspended_draft')}</option>
                    </select>
                </label>
                <PermissionPicker available={available} selected={form.data.permissions} onChange={(keys) => form.setData('permissions', keys)} adding />
                <label>
                    {translate('office_staff', 'field_reason_add')}
                    <textarea required rows={2} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    {form.errors.reason && <span className="field-error">{form.errors.reason}</span>}
                </label>
                <FormErrors errors={form.errors} besideFields={['name', 'email', 'phone', 'reason']} />
                <div className="ops-row is-end is-loose">
                    <button type="button" className="button" onClick={onClose}>{translate('portal', 'emp_cancel')}</button>
                    <button type="submit" className="button dark" disabled={form.processing}>
                        {form.processing ? translate('portal', 'emp_create_submitting') : translate('portal', 'emp_create_submit')}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function EditStaffDialog({ employee, available, canResetPassword, onClose }: { employee: Row; available: PermissionDefinition[]; canResetPassword: boolean; onClose: () => void }) {
    const translate = useT();
    // Mirrors the server: only an Employee or SR without their own access settings is edited with these boxes.
    const usesPermissionBoxes = ['employee', 'sr'].includes(String(employee.role)) && !employee.profile?.action_permissions;
    const startingPermissions: string[] = Array.isArray(employee.profile?.permissions)
        ? employee.profile.permissions
        : employee.role === 'sr' ? SR_PERMISSIONS : [];
    const form = useForm<{ name: string; phone: string; status: string; reason: string; permissions: string[] }>({
        name: employee.name || '',
        phone: employee.phone || '',
        status: employee.status || 'active',
        reason: translate('office_staff', 'reason_update'),
        permissions: [...startingPermissions],
    });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        // Someone added before phone numbers were needed can still be renamed or disabled.
        const keepsNoPhone = !employee.phone;
        form.transform((data) => {
            const { permissions, phone, ...details } = data;

            return { ...details, ...(keepsNoPhone && phone.trim() === '' ? {} : { phone }), ...(usesPermissionBoxes ? { permissions } : {}) } as typeof data;
        });
        form.patch(`/commands/accounts/${employee.id}`, { onSuccess: onClose });
    };

    return (
        <Modal title={translate('office_staff', 'edit_staff')} onClose={onClose} variant="wide">
            <form onSubmit={submit} className="action-form">
                <label>
                    {translate('office_staff', 'field_name')}
                    <input required value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} />
                    {form.errors.name && <span className="field-error">{form.errors.name}</span>}
                </label>
                <label>
                    {translate('portal', 'emp_fld_phone')}
                    <input value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} />
                    {form.errors.phone && <span className="field-error">{form.errors.phone}</span>}
                </label>
                <label>
                    {translate('portal', 'emp_fld_status')}
                    <select value={form.data.status} onChange={(event) => form.setData('status', event.target.value)}>
                        <option value="active">{translate('portal', 'emp_opt_active_access')}</option>
                        <option value="suspended">{translate('portal', 'emp_opt_suspended_lock')}</option>
                    </select>
                </label>
                {usesPermissionBoxes ? (
                    <PermissionPicker available={available} selected={form.data.permissions} onChange={(keys) => form.setData('permissions', keys)} adding={false} onlyKeys={employee.role === 'sr' ? SR_PERMISSIONS : undefined} />
                ) : (
                    <p className="muted">{translate('portal', 'emp_action_perms_notice')} <Link href="/operations/access-management" className="text-link">{translate('portal', 'emp_manage_roles')}</Link></p>
                )}
                <label>
                    {translate('office_staff', 'field_reason_edit')}
                    <textarea required rows={2} placeholder={translate('portal', 'emp_ph_correction_reason')} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                    {form.errors.reason && <span className="field-error">{form.errors.reason}</span>}
                </label>
                <FormErrors errors={form.errors} besideFields={['name', 'phone', 'reason']} />
                <div className="ops-row is-end is-loose">
                    <button type="button" className="button" onClick={onClose}>{translate('portal', 'emp_cancel')}</button>
                    <button type="submit" className="button dark" disabled={form.processing}>
                        {form.processing ? translate('portal', 'emp_saving') : translate('office_staff', 'save_staff')}
                    </button>
                </div>
            </form>
            {canResetPassword && <AccountPasswordManager account={employee} canManage />}
        </Modal>
    );
}

function StaffDrawer({ employee, available, canManageStaff, onEdit, onClose }: { employee: Row; available: PermissionDefinition[]; canManageStaff: boolean; onEdit: () => void; onClose: () => void }) {
    const translate = useT();
    const permissions: string[] = employee.effective_permissions ?? [];

    return (
        <Modal
            title={String(employee.name)}
            variant="drawer"
            badge={<Badge status={String(employee.status)} />}
            onClose={onClose}
            footer={canManageStaff ? (
                <>
                    <button type="button" className="button" disabled={employee.status !== 'active'} onClick={() => router.post(`/commands/accounts/${employee.id}/invite`, {}, { preserveScroll: true })}>
                        {translate('office_staff', 'send_invite')}
                    </button>
                    <button type="button" className="button dark" onClick={onEdit}>{translate('office_staff', 'edit_staff')}</button>
                </>
            ) : undefined}
        >
            <DrawerLines
                lines={[
                    { label: translate('office_staff', 'detail_email'), value: employee.email },
                    { label: translate('office_staff', 'detail_phone'), value: employee.phone },
                    { label: translate('office_staff', 'detail_role'), value: roleName(employee, translate) },
                    { label: translate('office_staff', 'detail_branch'), value: employee.branch_name || '—' },
                    { label: translate('office_staff', 'detail_joined'), value: date(employee.created_at) },
                ]}
            />
            <h3 className="staff-drawer-heading">{translate('office_staff', 'detail_permissions')}</h3>
            {permissions.length === 0 ? (
                <p className="muted">{translate('office_staff', 'no_permissions')}</p>
            ) : (
                <div className="ops-row is-wrap is-tight">
                    {permissions.map((key) => <span key={key} className="emp-perm" title={available.find((item) => item.key === key)?.description}>{permissionName(key, available, translate)}</span>)}
                </div>
            )}
        </Modal>
    );
}

export default function EmployeesHub({ employees, availablePermissions, stats, ownerAccounts, hub }: EmployeeHubProps) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const { url } = usePage();
    const canManageStaff = auth.user.role === 'super_admin';
    const isOwner = ['super_admin', 'md', 'chairman'].includes(auth.user.role);
    const caps: string[] = auth.capabilities ?? [];
    // Mirrors PasswordAccess on the server.
    const canResetPasswordOf = (employee: Row): boolean => employee.role !== 'super_admin' && employee.id !== auth.user.id && (canManageStaff || (!['md', 'chairman'].includes(String(employee.role)) && (isOwner || caps.includes('staff_admin'))));

    const [adding, setAdding] = useState(false);
    const [openedId, setOpenedId] = useState<number | null>(null);
    const [editing, setEditing] = useState<Row | null>(null);
    // A row from the latest answer, so the drawer shows what was just saved.
    const opened = employees.data.find((row) => row.id === openedId) ?? null;

    const views: { key: StaffView; label: string }[] = [
        { key: 'staff', label: translate('office_staff', 'view_staff') },
        ...(auth?.companyStockManager ? [{ key: 'prescribers' as const, label: translate('office_staff', 'view_prescribers') }] : []),
        ...(canManageStaff ? [{ key: 'owners' as const, label: translate('office_staff', 'view_owners') }] : []),
    ];
    const view = currentView(url, views.map((item) => item.key), 'staff');

    const columns: MoneyColumn<Row>[] = [
        { key: 'name', title: translate('office_staff', 'col_staff'), sortKey: 'name', phone: 'title', render: (row) => <b>{row.name}</b> },
        { key: 'role', title: translate('office_staff', 'col_role'), sortKey: 'role', phone: 'sub', render: (row) => roleName(row, translate) },
        { key: 'branch', title: translate('office_staff', 'col_branch'), phone: 'sub', render: (row) => row.branch_name || '—' },
        { key: 'permissions', title: translate('office_staff', 'col_permissions'), phone: 'hide', render: (row) => translate('office_staff', 'permission_count', { count: number((row.effective_permissions ?? []).length) }) },
        { key: 'joined', title: translate('office_staff', 'col_joined'), sortKey: 'joined', phone: 'hide', render: (row) => date(row.created_at) },
        { key: 'status', title: translate('office_staff', 'col_status'), sortKey: 'status', phone: 'amount', render: (row) => <Badge status={String(row.status)} /> },
    ];

    const tableData: MoneyTableData<Row> = { rows: employees as MoneyTableData<Row>['rows'], ...employees.table };

    return (
        <OfficePage
            title={translate('office_staff', 'page_title')}
            hub={hub}
            action={canManageStaff ? (
                <button type="button" className="button dark" onClick={() => setAdding(true)}>
                    <UserPlus size={18} aria-hidden="true" />
                    {translate('office_staff', 'add_staff')}
                </button>
            ) : undefined}
        >
            <Figures
                items={[
                    { key: 'total', label: translate('office_staff', 'card_total'), value: number(stats.total), text: true },
                    { key: 'active', label: translate('office_staff', 'card_active'), value: number(stats.active), text: true },
                    { key: 'off', label: translate('office_staff', 'card_off'), value: number(stats.suspended), text: true },
                ]}
            />

            <ViewSwitch views={views} active={view} fallback="staff" label={translate('office_staff', 'views_label')} />

            {view === 'staff' && (
                <MoneyTable<Row>
                    data={tableData}
                    prop="data"
                    columns={columns}
                    searchPlaceholder={translate('office_staff', 'search_staff')}
                    hideViewChips
                    empty={translate('office_staff', 'empty_staff')}
                    onRowOpen={(row) => setOpenedId(Number(row.id))}
                />
            )}
            {view === 'prescribers' && <OfficePrescriberGrants />}
            {view === 'owners' && <OwnerAccountsPanel accounts={ownerAccounts} />}

            {opened && (
                <StaffDrawer
                    employee={opened}
                    available={availablePermissions}
                    canManageStaff={canManageStaff}
                    onClose={() => setOpenedId(null)}
                    onEdit={() => { setOpenedId(null); setEditing(opened); }}
                />
            )}
            {adding && <AddStaffDialog available={availablePermissions} onClose={() => setAdding(false)} />}
            {editing && <EditStaffDialog employee={editing} available={availablePermissions} canResetPassword={canResetPasswordOf(editing)} onClose={() => setEditing(null)} />}
        </OfficePage>
    );
}
