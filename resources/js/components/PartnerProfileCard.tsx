import {useState} from 'react';
import {Copy,Lock,LockOpen} from 'lucide-react';
import {ActionForm,Badge,money,number} from './UI';
import {useT} from '../shared/i18n/t';

type Row=Record<string,any>;

/** The partner's own identity card: type, code, full address, who referred him, his referrals and rank. */
export default function PartnerProfileCard({card}:{card:Row}){
 const translate=useT();
 const [copied,setCopied]=useState(false);
 const p=(key:string,replace:Record<string,string>={})=>translate('partner_profile',key,replace);
 const none=p('card_not_given');
 const address=card.address??{};
 const referral=card.referred_by??{};
 const refs=card.referrals??{items:[]};
 const missing:string[]=(card.address_missing??[]).map((field:string)=>p(`card_${field}`));
 const c=(key:string)=>translate('cr21_identity',key);
 const bio=card.biodata??{};
 const appointment=card.appointment??{};
 // Everything the office holds about the person, read only; the ID numbers arrive already masked.
 const personal:Array<[string,string|null]>=[
  [c('f_name'),bio.name],[c('f_father'),bio.father_name],[c('f_mother'),bio.mother_name],
  [c('f_birth_date'),bio.date_of_birth],
  [bio.birth_certificate&&!bio.nid?c('f_birth_cert'):c('f_nid'),bio.nid||bio.birth_certificate],
  [c('f_occupation'),bio.occupation],[c('label_mobile'),bio.phone],[c('f_email'),bio.email],[c('f_extra_contact'),bio.extra_contact],
  [c('f_address'),bio.full_address],
 ];
 const identity:Array<[string,string]>=[
  [c('label_appointment'),appointment.line||none],
  [c('label_area'),appointment.area||none],
  [p('card_type'),p(`card_type_${card.role}`)],
  [p('card_code'),card.dealer_code||none],
  [p('card_status'),''],
  [p('card_joined'),card.joined_on||none],
  [p('card_place_code'),card.place_code||none],
  // Only a sub-dealer has a chamber, hospital or diagnostic centre; a dealer never shows this line.
  ...(card.role==='sub_dealer'?[[c('label_chamber'),card.shop_name||none] as [string,string]]:[]),
 ];
 const copyLink=async()=>{
  try{await navigator.clipboard.writeText(card.refer_url);setCopied(true);window.setTimeout(()=>setCopied(false),2000);}catch{setCopied(false);}
 };
 return <section className="panel narrow-panel ptl-flat-panel" data-tour="partner-identity">
  <h2>{c('profile_title')}</h2>
  <p className="muted">{c('profile_hint')}</p>
  <h3>{c('sec_person')}</h3>
  <dl className="profile-grid" data-testid="profile-biodata">{personal.map(([key,value])=><div key={key}><dt>{key}</dt><dd>{value||c('not_given')}</dd></div>)}</dl>
  <h3>{c('sec_appointment')}</h3>
  <dl className="profile-grid">{identity.map(([key,value])=><div key={key}><dt>{key}</dt><dd>{key===p('card_status')?<Badge status={card.status}/>:value}</dd></div>)}</dl>

  <h3>{c('label_address')}</h3>
  <dl className="profile-grid">{(['division','district','upazila','union','village'] as const).map(field=><div key={field}><dt>{p(`card_${field}`)}</dt><dd>{address[field]||none}</dd></div>)}</dl>
  {missing.length>0&&<div className="notice"><div><p>{p('card_address_missing',{fields:missing.join('، ')})}</p></div></div>}

  <h3>{p('card_referred_by')}</h3>
  <p>{referral.kind==='partner'?<><b>{p('card_referred_partner',{name:String(referral.name??''),code:String(referral.code??'')})}</b>{referral.phone&&<><br/><small className="muted">{p('card_referred_phone',{phone:String(referral.phone)})}</small></>}</>:p(referral.kind==='company'?'card_referred_company':'card_referred_none')}</p>

  {card.refer_code&&<><h3>{p('card_my_code_link')}</h3>
   <p><b>{card.refer_code}</b></p>
   {card.refer_url&&<p className="ops-row is-wrap"><a href={card.refer_url} className="text-link">{card.refer_url}</a> <button type="button" className="button small" onClick={copyLink}><Copy size={14}/> {copied?p("card_copied"):p("card_copy")}</button></p>}</>}

  <div className="notice">{card.locked?<Lock size={19}/>:<LockOpen size={19}/>}<div><p>{p(card.locked?'lock_locked':'lock_open')}</p></div></div>
  {!card.locked&&<><h3>{p('lock_form_title')}</h3>
   <ActionForm action="/commands/profile/details" fields={[{name:'shop_name',label:p('lock_form_shop'),value:card.shop_name||''},{name:'village',label:p('lock_form_village'),value:address.village||''}]} submit={p('lock_form_save')}/></>}

  <h3>{translate('joining','change_title')}</h3>
  <p className="muted">{translate('joining','change_hint')}</p>
  <ActionForm action="/commands/profile/change-request" fields={[{name:'phone',label:translate('joining','change_field_phone'),value:''},{name:'union',label:translate('joining','change_field_union'),value:''},{name:'village',label:translate('joining','change_field_village'),value:''},{name:'note',label:translate('joining','change_note'),type:'textarea',required:true,value:''}]} submit={translate('joining','change_send')}/>

  <h3>{p('refs_title')}</h3>
  <dl className="profile-grid">
   <div><dt>{p('refs_direct')}</dt><dd>{number(refs.direct??0)}</dd></div>
   <div><dt>{p('refs_team')}</dt><dd>{number(refs.team??0)}</dd></div>
   <div><dt>{p('refs_sales')}</dt><dd>{money(refs.sales_total??0)}</dd></div>
  </dl>
  {(refs.items??[]).length===0?<p className="muted">{p('refs_empty')}</p>:<div className="table-scroll"><table className="mt-table"><thead><tr><th>{p('refs_col_name')}</th><th>{p('refs_col_code')}</th><th>{p('refs_col_joined')}</th><th>{p('refs_col_own')}</th><th>{p('refs_col_branch')}</th><th>{p('refs_col_team')}</th></tr></thead><tbody>{refs.items.map((item:Row)=><tr key={item.id}><td>{item.name}</td><td>{item.dealer_code||'—'}</td><td>{item.joined_on}</td><td>{money(item.own_sales)}</td><td>{money(item.branch_sales)}</td><td>{number(item.branch_members+1)}</td></tr>)}</tbody></table></div>}
  <small className="muted">{p('refs_note')}</small>
  {refs.truncated&&<small className="muted"> {p('refs_truncated',{count:String(refs.items.length)})}</small>}

  {card.rank&&<><h3>{p('rank_title')}</h3>
   <p><b>{p('rank_current',{rank:translate('portal',`net_rank_${card.rank.current}`)})}</b></p>
   <p className="muted">{p('rank_ladder')}</p>
   <ul>{card.rank.ladder.map((step:Row)=><li key={step.rank}><b>{translate('portal',`net_rank_${step.rank}`)}</b>: {step.kind==='own_purchases'?p('rank_need_purchases',{amount:money(step.amount)}):p('rank_need_leaders',{count:String(step.count),required:translate('portal',`net_rank_${step.required_rank}`)})}</li>)}</ul></>}
 </section>;
}
