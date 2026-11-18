import {useState} from 'react';
import {usePage,useForm,router} from '@inertiajs/react';
import {Download} from 'lucide-react';
import PaymentsQueue from '../../PaymentsQueue';
import {actionAllowed} from '../../action-permissions';
import {Row,Modal,money,number} from '../../UI';
import {Link} from '@inertiajs/react';
import {useT} from '../../../shared/i18n/t';
import {useReasonField} from '../../../shared/reasonFields';
import {StatusTabs,branchFilterKeys,channelName,claimStatuses,usePortalSection,type PortalSectionProps} from '../portalShared';
/** Dates for "Download all": the dates on screen, or the last 12 months when no dates are chosen. */
function collectionsExportHref(filters:Row|undefined):string{
 const dhaka=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Dhaka',year:'numeric',month:'2-digit',day:'2-digit'});
 const today=new Date();
 const iso=(value:Date)=>dhaka.format(value);
 const back=(days:number)=>{const value=new Date(today);value.setDate(value.getDate()-days);return iso(value);};
 const period=String(filters?.period||'');
 let from=back(365);let to=iso(today);
 if(period==='today'){from=to;}
 else if(period==='week'){from=back((today.getDay()+6)%7);}
 else if(period==='month'){from=to.slice(0,8)+'01';}
 else if(period==='year'){from=to.slice(0,5)+'01-01';}
 else if(period==='day'&&filters?.from){from=String(filters.from);to=String(filters.from);}
 else if(period==='custom'&&filters?.from&&filters?.to){from=String(filters.from);to=String(filters.to);}
 const query:Record<string,string>={type:'collections',format:'xlsx',from,to};
 branchFilterKeys.forEach(key=>{const value=filters?.[key];if(value!=null&&value!=='')query[key]=String(value);});
 return `/commands/operations/reports?${new URLSearchParams(query).toString()}`;
}
/** Money received, waiting and rejected for every payment in the filtered list, and received money by method. */
function ClaimTotals({totals}:{totals:Row}){
 const translate=useT();
 const by=totals.by_status??{};
 const channels=Object.entries(totals.verified_by_channel??{}) as [string,string][];
 const tile=(key:string,title:string,tone:string)=><div className={`metric pay-total ${tone}`}><span>{translate('portal',title)}</span><strong>{money(by[key]?.amount??0)}</strong><small>{translate('portal','pay_totals_count',{count:number(by[key]?.count??0)})}</small></div>;
 return <div className="pay-totals" aria-label={translate('portal','pay_totals_title')}>
  <div className="metric-grid three">{tile('verified','pay_totals_verified','is-ok')}{tile('pending','pay_totals_pending','is-wait')}{tile('rejected','pay_totals_rejected','is-bad')}</div>
  {channels.length>0&&<p className="pay-total-channels">{channels.map(([channel,amount])=><span key={channel}>{translate('portal',channel==='Bank'?'pay_channel_bank':channel==='bKash'?'pay_channel_bkash':channel==='Nagad'?'pay_channel_nagad':channel==='Rocket'?'pay_channel_rocket':'pay_filter_channel')}: <b>{money(amount)}</b></span>)}</p>}
 </div>;
}
/**
 * The decision on one payment: a button for "payment received" and a button for "reject", each named for what it does.
 * A missing reason is said on screen in a space kept free for it, so the buttons do not move.
 */
function PaymentCheckForm({claim,onDone}:{claim:Row,onDone:()=>void}){
 const translate=useT();
 const {portal,staffActions}=usePage<any>().props;
 const reason=useReasonField('payment.verify');
 const form=useForm<{approve:string,reason:string}>({approve:'1',reason:''});
 const [missing,setMissing]=useState(false);
 const action=`/commands/claims/${claim.id}/verify`;
 if((portal==='manage'||portal==='sadmin')&&!actionAllowed(action,staffActions))return null;
 const decide=(approve:boolean)=>{
  if(reason.required&&form.data.reason.trim()===''){setMissing(true);return;}
  setMissing(false);
  form.transform(data=>({...data,approve:approve?'1':'0'}));
  form.post(action,{preserveScroll:true,onSuccess:onDone});
 };
 const error=missing?translate('portal','pay_check_reason_needed'):(form.errors.reason?String(form.errors.reason):'');
 return <form className="action-form" onSubmit={e=>e.preventDefault()}>
  {reason.shown&&<label>{reason.label(translate('portal','payment_evidence'))}<textarea rows={4} value={form.data.reason} onChange={e=>{form.setData('reason',e.target.value);setMissing(false);}}/></label>}
  <div style={{minHeight:'1.6em'}} aria-live="polite">{error&&<span className="field-error" role="alert">{error}</span>}</div>
  <div className="button-row" style={{display:'flex',gap:'8px',flexWrap:'wrap'}}>
   <button type="button" className="button dark" disabled={form.processing} onClick={()=>decide(true)}>{translate('portal','payment_confirm_button')}</button>
   <button type="button" className="button" disabled={form.processing} onClick={()=>decide(false)}>{translate('portal','payment_reject_button')}</button>
  </div>
 </form>;
}
/**
 * Confirm several office-cash payments in one go. Each one is sent to the same single-payment route as the
 * dialog above, one after another, so every payment keeps its own permission check and record.
 */
function BulkConfirmForm({claims,onDone}:{claims:Row[],onDone:()=>void}){
 const translate=useT();
 const reason=useReasonField('payment.verify');
 const [text,setText]=useState('');
 const [done,setDone]=useState(0);
 const [failed,setFailed]=useState('');
 const [running,setRunning]=useState(false);
 const total=claims.reduce((sum,claim)=>sum+Math.round(Number(claim.amount)*100),0)/100;
 const run=()=>{
  if(reason.required&&text.trim()===''){setFailed(translate('portal','pay_check_reason_needed'));return;}
  setRunning(true);setFailed('');setDone(0);
  const next=(index:number)=>{
   if(index>=claims.length){setRunning(false);onDone();return;}
   router.post(`/commands/claims/${claims[index].id}/verify`,{approve:'1',reason:text},{preserveScroll:true,preserveState:true,onSuccess:()=>{setDone(index+1);next(index+1);},onError:(errors)=>{setRunning(false);setFailed(`${claims[index].number}: ${Object.values(errors)[0] ?? ''}`);}});
  };
  next(0);
 };
 return <div className="action-form">
  <p>{translate('office_shell','bulk_confirm_intro',{count:number(claims.length),amount:money(total.toFixed(2))})}</p>
  <ul className="plain-list">{claims.map(claim=><li key={claim.id}>{claim.number} · {claim.name} · {money(claim.amount)}</li>)}</ul>
  {reason.shown&&<label>{reason.label(translate('portal','payment_evidence'))}<textarea rows={3} value={text} onChange={e=>setText(e.target.value)}/></label>}
  <div style={{minHeight:'1.6em'}} aria-live="polite">{failed&&<span className="field-error" role="alert">{failed}</span>}{running&&<span>{translate('office_shell','bulk_confirm_progress',{done:number(done),count:number(claims.length)})}</span>}</div>
  <button type="button" className="button dark" disabled={running} onClick={run}>{translate('office_shell','bulk_confirm_button',{count:number(claims.length)})}</button>
 </div>;
}
/** Payments waiting to be checked: office only. */
export default function Payments(props:PortalSectionProps){
 const {section,d,filters,translate,staffActions,open,close,modal,selected}=usePortalSection(props);
 const [picked,setPicked]=useState<number[]>([]);
 const rows:Row[]=d.payments_table?.rows?.data??[];
 const pickedClaims=rows.filter(row=>picked.includes(Number(row.id)));
 const mayVerify=actionAllowed('/commands/claims/0/verify',staffActions);
 return <>
 {section==='payments'&&<section className="panel table-panel"><div className="panel-heading"><h2>{translate('portal','payment_verification_queue')}</h2><span>{translate('portal','payment_verification_queue_hint')}</span></div>{d.claim_status_counts&&<StatusTabs counts={d.claim_status_counts} filters={filters} section="payments" statuses={claimStatuses}/>}{d.claim_totals&&<ClaimTotals totals={d.claim_totals}/>}<PaymentsQueue data={d.payments_table} onReview={r=>open('payment',r)} picked={picked} onPick={mayVerify?(row,on)=>setPicked(current=>on?[...current,Number(row.id)]:current.filter(id=>id!==Number(row.id))):undefined} actions={<>{pickedClaims.length>0&&<button type="button" className="button small dark" onClick={()=>open('bulk',pickedClaims[0])}>{translate('office_shell','bulk_confirm_button',{count:number(pickedClaims.length)})}</button>}<a className="button small" href={collectionsExportHref(filters)} title={translate('portal','pay_export_all_hint')}><Download size={14}/> {translate('portal','pay_export_all')}</a></>}/></section>}
 {modal&&<Modal title={translate('portal','modal_payment')} onClose={close}>
 {modal==='bulk'&&<BulkConfirmForm claims={pickedClaims} onDone={()=>{setPicked([]);close();}}/>}
 {modal==='payment'&&<><p>{translate('portal','payment_verify_intro',{amount:money(selected.amount),channel:channelName(selected.channel,translate)})} <b>{selected.reference}</b>.</p>{selected.has_proof?<p><a className="text-link" href={`/commands/operations/receipts/payment/${selected.id}`} target="_blank" rel="noopener">{translate('portal','payment_proof_view')}</a></p>:selected.channel==='Office'?null:<p className="notice">{translate('portal','payment_proof_missing')}</p>}<p><Link className="text-link" href={`/orders/${selected.order_id}`}>{translate('office_shell','open_whole_order')}</Link></p><PaymentCheckForm claim={selected} onDone={close}/>{!actionAllowed(`/commands/claims/${selected.id}/verify`,staffActions)&&<p className="notice">{translate('portal','payment_view_only_note')}</p>}</>}
 </Modal>}
 </>;
}
