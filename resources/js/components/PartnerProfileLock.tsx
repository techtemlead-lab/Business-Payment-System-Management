import {useForm,usePage} from '@inertiajs/react';
import {Lock,LockOpen} from 'lucide-react';
import {type Row} from './UI';
import {useT} from '../shared/i18n/t';

/** Office switch: lock a partner's profile (company-only edits) or open it for the partner's own small fixes. */
export default function PartnerProfileLock({account}:{account:Row}){
 const translate=useT();
 const {auth}=usePage<any>().props;
 const form=useForm({locked:1,reason:''});
 if(!['dealer','sub_dealer'].includes(account.role)||!auth?.user)return null;
 const locked=account.profile?.profile_locked!==false;
 const p=(key:string,replace:Record<string,string>={})=>translate('partner_profile',key,replace);
 return <section className="panel pbc-panel">
  <div className="panel-heading"><div>
   <h3 className="ops-row is-inline">{locked?<Lock size={18}/>:<LockOpen size={18}/>} {p('admin_lock_title')}: {p(locked?'admin_lock_locked':'admin_lock_open')}</h3>
   <p className="muted pbc-hint">{p('admin_lock_body',{name:String(account.name??'')})}</p>
  </div></div>
  <form className="action-form" onSubmit={event=>{
   event.preventDefault();
   form.transform(data=>({...data,locked:locked?0:1}));
   form.post(`/commands/accounts/${account.id}/profile-lock`,{preserveScroll:true,onSuccess:()=>form.reset('reason')});
  }}>
   <label>{p('admin_lock_reason')}<input value={form.data.reason} onChange={event=>form.setData('reason',event.target.value)} maxLength={500}/></label>
   <button className="button dark" type="submit" disabled={form.processing}>{p(locked?'admin_lock_do_open':'admin_lock_do_lock')}</button>
  </form>
 </section>;
}
