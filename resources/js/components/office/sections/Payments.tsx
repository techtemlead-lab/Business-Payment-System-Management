import {useState} from 'react';
import {Download} from 'lucide-react';
import {AmountWords} from '../../payouts/WithdrawalFacts';
import PaymentsQueue from '../../PaymentsQueue';
import {actionAllowed} from '../../action-permissions';
import {Row,ActionForm,Modal,money,number} from '../../UI';
import {useT} from '../../../shared/i18n/t';
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
/** Payments waiting to be checked: office only. */
export default function Payments(props:PortalSectionProps){
 const {section,d,filters,translate,staffActions,open,close,modal,selected}=usePortalSection(props);
 // "Save and open the next": the clerk works down the queue oldest first without going back to the list (MA-17).
 const [openNext,setOpenNext]=useState(true);
 return <>
 {section==='payments'&&<section className="panel table-panel"><div className="panel-heading"><h2>{translate('portal','payment_verification_queue')}</h2><span>{translate('portal','payment_verification_queue_hint')}</span></div>{d.claim_status_counts&&<StatusTabs counts={d.claim_status_counts} filters={filters} section="payments" statuses={claimStatuses}/>}{d.claim_totals&&<ClaimTotals totals={d.claim_totals}/>}<PaymentsQueue data={d.payments_table} onReview={r=>open('payment',r)} actions={<a className="button small" href={collectionsExportHref(filters)} title={translate('portal','pay_export_all_hint')}><Download size={14}/> {translate('portal','pay_export_all')}</a>}/></section>}
 {modal&&<Modal title={translate('portal','modal_payment')} onClose={close}>
 {modal==='payment'&&<><p>{translate('portal','payment_verify_intro',{amount:money(selected.amount),channel:channelName(selected.channel,translate)})} <b>{selected.reference}</b>.</p><AmountWords amount={selected.amount}/>{selected.proof_looks_blank&&<p className="notice" role="alert">{translate('payouts','proof_looks_blank')}</p>}{selected.has_proof?<p><a className="text-link" href={`/commands/operations/receipts/payment/${selected.id}`} target="_blank" rel="noopener">{translate('portal','payment_proof_view')}</a></p>:selected.channel==='Office'?null:<p className="notice">{translate('portal','payment_proof_missing')}</p>}<ActionForm key={selected.id} action={`/commands/claims/${selected.id}/verify`} fields={[{name:'approve',label:translate('portal','payment_decision'),options:[{value:'1',label:translate('portal','payment_verify_received')},{value:'0',label:translate('portal','payment_reject')}],required:true},...(selected.channel==='Office'?[{name:'cash_counted',type:'number',label:translate('payouts','cash_counted'),hint:translate('payouts','cash_counted_hint',{amount:money(selected.amount)})}]:[]),{name:'reason',label:translate('portal','payment_evidence'),type:'textarea',required:true,place:'payment.verify'}]} onDone={()=>{const next=openNext?((d.payments_table?.rows?.data??[]) as Row[]).find(row=>row.status==='pending'&&row.id!==selected.id):undefined;if(next){open('payment',next);}else{close();}}}/><label className="hub-choice"><input type="checkbox" checked={openNext} onChange={event=>setOpenNext(event.target.checked)}/>{translate('payouts','verify_next')}</label>{!actionAllowed(`/commands/claims/${selected.id}/verify`,staffActions)&&<p className="notice">{translate('portal','payment_view_only_note')}</p>}</>}
 </Modal>}
 </>;
}
