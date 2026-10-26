import {useState} from 'react';
import {useForm, usePage} from '@inertiajs/react';
import {label, type Row} from './UI';
import {useT} from '../shared/i18n/t';

const actions = ['view', 'create', 'edit', 'approve'];
const empty = () => ({view:false,create:false,edit:false,approve:false});

function Matrix({modules,value,change}:{modules:string[];value:Row;change:(value:Row)=>void}) {
    const translate = useT();
    const moduleLabel = (module: string) => {
        const key = `perm_${module}`;
        const localized = translate('portal', key);
        return localized !== key ? localized : label(module);
    };
    const actionLabel = (action: string) => translate('portal', `sa_action_${action}`);

    return <div className="table-wrap"><table><thead><tr><th>{translate('portal', 'sa_module')}</th>{actions.map(action=><th key={action}>{actionLabel(action)}</th>)}</tr></thead><tbody>
        {modules.map(module=><tr key={module}><td>{moduleLabel(module)}</td>{actions.map(action=><td key={action}><input type="checkbox" aria-label={`${moduleLabel(module)}: ${actionLabel(action)}`} checked={!!value[module]?.[action]} onChange={event=>{
            const row={...empty(),...value[module],[action]:event.target.checked};
            if(action==='view'&&!event.target.checked)Object.assign(row,empty());
            if(action!=='view'&&event.target.checked)row.view=true;
            change({...value,[module]:row});
        }}/></td>)}</tr>)}
    </tbody></table></div>;
}

function PermissionForm({modules,entry,mode,roles}:{modules:string[];entry:Row;mode:'staff'|'role';roles:Row[]}) {
    const translate = useT();
    const {staffRoles=[]}=usePage<any>().props;
    const form=useForm<Row>({permissions:entry.permissions??{},version:entry.version??0,key:entry.key??'custom_',name:entry.name??'',staff_id:entry.id,role:entry.role??'employee',reason:''});
    const sr=mode==='staff'&&(entry.role==='sr'||form.data.role==='sr');
    const visible=sr?modules.filter(module=>['field_sales','sr_orders','sr_dues','sr_onboarding'].includes(module)):modules;
    return <form className="action-form" onSubmit={event=>{
        event.preventDefault();
        form.transform(data=>({...data,permissions:sr?Object.fromEntries(Object.entries(data.permissions).filter(([module])=>visible.includes(module))):data.permissions}));
        form.post('/commands/operations/staff/'+(mode==='role'?'roles':'action-permissions'),{preserveScroll:true});
    }}>
        {mode==='role'?<div className="form-grid"><label>{translate('portal', 'sa_role_name')}<input required maxLength={100} value={form.data.name} onChange={e=>form.setData('name',e.target.value)}/></label><label>{translate('portal', 'sa_role_key')}<input required readOnly={!!entry.version} pattern="custom_[a-z][a-z0-9_]{0,49}" value={form.data.key} onChange={e=>form.setData('key',e.target.value)} placeholder="custom_sales_support"/></label></div>:<label>{translate('portal', 'sa_staff_role')}<select value={form.data.role} disabled={entry.role==='sr'} onChange={e=>{
            const role=e.target.value;form.setData('role',role);
            const preset=roles.find(item=>item.key===role);if(preset)form.setData('permissions',preset.permissions);
        }}>{[...new Set(staffRoles as string[])].filter(role=>role!=='super_admin').map(role=><option value={role} key={role}>{roles.find(item=>item.key===role)?.name??label(role)}</option>)}</select></label>}
        <Matrix modules={visible} value={form.data.permissions} change={value=>form.setData('permissions',value)}/>
        <label>{translate('portal', 'sa_reason')}<textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>
        {Object.keys(form.errors).length>0&&<div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
        <button className="button dark" disabled={form.processing}>{form.processing?translate('portal', 'saving'):mode==='role'?translate('portal', 'sa_save_role'):translate('portal', 'sa_save_staff')}</button>
    </form>;
}

export default function StaffAccessEditor({modules,staff,roles,presets}:{modules:string[];staff:Row[];roles:Row[];presets:Row[]}) {
    const translate = useT();
    const [staffId,setStaffId]=useState('');
    const [roleKey,setRoleKey]=useState('');
    const selected=staff.find(user=>String(user.id)===staffId);
    const role=roles.find(item=>item.key===roleKey)??{};
    return <>
        <section className="panel"><h2>{translate('portal', 'sa_title')}</h2><p>{translate('portal', 'sa_body')}</p>
            <label>{translate('portal', 'sa_staff_member')}<select value={staffId} onChange={e=>setStaffId(e.target.value)}><option value="">{translate('portal', 'sa_select_staff')}</option>{staff.map(user=><option key={user.id} value={user.id}>{user.name} · {label(user.role)}</option>)}</select></label>
            {selected&&<PermissionForm key={selected.id+':'+selected.version} modules={modules} entry={selected} mode="staff" roles={[...presets,...roles]}/>}
        </section>
        <section className="panel"><h2>{translate('portal', 'sa_roles_title')}</h2><p>{translate('portal', 'sa_roles_body')}</p>
            <label>{translate('portal', 'sa_role')}<select value={roleKey} onChange={e=>setRoleKey(e.target.value)}><option value="">{translate('portal', 'sa_create_role')}</option>{roles.map(item=><option key={item.key} value={item.key}>{item.name}</option>)}</select></label>
            <PermissionForm key={roleKey+':'+(role.version??0)} modules={modules} entry={role} mode="role" roles={roles}/>
        </section>
    </>;
}
