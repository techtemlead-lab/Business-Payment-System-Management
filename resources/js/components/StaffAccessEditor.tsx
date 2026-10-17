import {useState} from 'react';
import {useForm, usePage} from '@inertiajs/react';
import {label, type Row} from './UI';

const actions = ['view', 'create', 'edit', 'approve'];
const empty = () => ({view:false,create:false,edit:false,approve:false});

function Matrix({modules,value,change}:{modules:string[];value:Row;change:(value:Row)=>void}) {
    return <div className="table-wrap"><table><thead><tr><th>Module</th>{actions.map(action=><th key={action}>{label(action)}</th>)}</tr></thead><tbody>
        {modules.map(module=><tr key={module}><td>{label(module)}</td>{actions.map(action=><td key={action}><input type="checkbox" aria-label={`${label(module)}: ${action}`} checked={!!value[module]?.[action]} onChange={event=>{
            const row={...empty(),...value[module],[action]:event.target.checked};
            if(action==='view'&&!event.target.checked)Object.assign(row,empty());
            if(action!=='view'&&event.target.checked)row.view=true;
            change({...value,[module]:row});
        }}/></td>)}</tr>)}
    </tbody></table></div>;
}

function PermissionForm({modules,entry,mode,roles}:{modules:string[];entry:Row;mode:'staff'|'role';roles:Row[]}) {
    const {staffRoles=[]}=usePage<any>().props;
    const form=useForm<Row>({permissions:entry.permissions??{},version:entry.version??0,key:entry.key??'custom_',name:entry.name??'',staff_id:entry.id,role:entry.role??'employee',reason:''});
    const sr=mode==='staff'&&(entry.role==='sr'||form.data.role==='sr');
    const visible=sr?modules.filter(module=>['field_sales','sr_orders','sr_dues','sr_onboarding'].includes(module)):modules;
    return <form className="action-form" onSubmit={event=>{
        event.preventDefault();
        form.transform(data=>({...data,permissions:sr?Object.fromEntries(Object.entries(data.permissions).filter(([module])=>visible.includes(module))):data.permissions}));
        form.post('/commands/operations/staff/'+(mode==='role'?'roles':'action-permissions'),{preserveScroll:true});
    }}>
        {mode==='role'?<div className="form-grid"><label>Role name<input required maxLength={100} value={form.data.name} onChange={e=>form.setData('name',e.target.value)}/></label><label>Role key<input required readOnly={!!entry.version} pattern="custom_[a-z][a-z0-9_]{0,49}" value={form.data.key} onChange={e=>form.setData('key',e.target.value)} placeholder="custom_sales_support"/></label></div>:<label>Staff role<select value={form.data.role} disabled={entry.role==='sr'} onChange={e=>{
            const role=e.target.value;form.setData('role',role);
            const preset=roles.find(item=>item.key===role);if(preset)form.setData('permissions',preset.permissions);
        }}>{[...new Set(staffRoles as string[])].filter(role=>role!=='super_admin').map(role=><option value={role} key={role}>{roles.find(item=>item.key===role)?.name??label(role)}</option>)}</select></label>}
        <Matrix modules={visible} value={form.data.permissions} change={value=>form.setData('permissions',value)}/>
        <label>Reason for these permissions<textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>
        {Object.keys(form.errors).length>0&&<div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}
        <button className="button dark" disabled={form.processing}>{form.processing?'Saving…':mode==='role'?'Save role':'Save staff permissions'}</button>
    </form>;
}

export default function StaffAccessEditor({modules,staff,roles,presets}:{modules:string[];staff:Row[];roles:Row[];presets:Row[]}) {
    const [staffId,setStaffId]=useState('');
    const [roleKey,setRoleKey]=useState('');
    const selected=staff.find(user=>String(user.id)===staffId);
    const role=roles.find(item=>item.key===roleKey)??{};
    return <>
        <section className="panel"><h2>Staff action permissions</h2><p>Select a staff member, then allow the actions their duties require. SR accounts remain limited to field-sales modules. Super-administrator access is maintained separately.</p>
            <label>Staff member<select value={staffId} onChange={e=>setStaffId(e.target.value)}><option value="">Select a staff member</option>{staff.map(user=><option key={user.id} value={user.id}>{user.name} · {label(user.role)}</option>)}</select></label>
            {selected&&<PermissionForm key={selected.id+':'+selected.version} modules={modules} entry={selected} mode="staff" roles={[...presets,...roles]}/>}
        </section>
        <section className="panel"><h2>Custom staff roles</h2><p>Create reusable roles or update an existing role. Individual staff permission overrides remain explicit when a role changes.</p>
            <label>Role<select value={roleKey} onChange={e=>setRoleKey(e.target.value)}><option value="">Create a new role</option>{roles.map(item=><option key={item.key} value={item.key}>{item.name}</option>)}</select></label>
            <PermissionForm key={roleKey+':'+(role.version??0)} modules={modules} entry={role} mode="role" roles={roles}/>
        </section>
    </>;
}
