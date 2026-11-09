import React,{useState} from 'react';
import {Link,router} from '@inertiajs/react';
import {Download} from 'lucide-react';
import NetworkTreeExplorer from '../../NetworkTreeExplorer';
import {Row,Table,Pagination,Badge,Empty,money,number,date} from '../../UI';
import {useT} from '../../../shared/i18n/t';
import {pageTotalLabel,usePortalSection,type PortalSectionProps} from '../portalShared';
/** A dealer's team at a glance: size, direct members, new joiners, who bought this month and who has gone quiet. */
function TeamSummary({summary}:{summary:Row}){
 const translate=useT();
 const tile=(key:string,value:React.ReactNode,hint?:string)=><div className="metric"><span>{translate('portal',key)}</span><strong>{value}</strong>{hint&&<small>{hint}</small>}</div>;
 return <section className="panel team-summary" aria-labelledby="team-summary-title">
  <div className="panel-heading"><div><h2 id="team-summary-title">{translate('portal','team_summary_title')}</h2><p>{translate('portal','team_summary_hint')}</p></div></div>
  <div className="metric-grid three">
   {tile('team_summary_members',number(summary.members),translate('portal','team_summary_direct',{count:number(summary.direct)}))}
   {tile('team_summary_joined',number(summary.joined_this_month))}
   {tile('team_summary_sales',money(summary.team_sales_month))}
   {tile('team_summary_bought',number(summary.bought_this_month))}
   {tile('team_summary_quiet',number(summary.quiet_60_days),translate('portal','team_summary_quiet_hint'))}
  </div>
 </section>;
}
function PartnerNetwork({members,total,truncated=false}:{members:Row[];total?:number;truncated?:boolean}) {
 const translate=useT();
 const [view,setView]=useState<'tree'|'direct'|'all'>('tree');
 const renderBranch=(member:Row,ancestors:number[]=[]):React.ReactNode=>{
  if(ancestors.includes(member.id))return null;
  const children=members.filter(child=>child.sponsor_id===member.id);
  return <li key={member.id} className="ptl-branch"><details open={member.depth<2}><summary><b>{member.name}</b> · {member.dealer_code} · {translate('portal','depth_label',{depth:String(member.depth)})}</summary><Link className="text-link" href={'/network?member_id='+member.id}>{translate('portal','view_purchase_timeline')}</Link>{children.length>0&&<ul>{children.map(child=>renderBranch(child,[...ancestors,member.id]))}</ul>}</details></li>;
 };
 return <section className="panel"><h2>{translate('portal','referral_network')}</h2><p className="muted">{translate('portal','referral_network_hint')}</p><div className="filter-bar">{(['tree','direct','all'] as const).map(mode=><button type="button" className="button small" aria-pressed={view===mode} key={mode} onClick={()=>setView(mode)}>{mode==='tree'?translate('portal','network_tree'):mode==='direct'?translate('portal','direct_referrals'):translate('portal','all_referrals')}</button>)}<a className="button small" href="/exports/genealogy" title={translate('portal','net_export_hint')}><Download size={15}/>{translate('portal','net_export_csv')}</a></div>{view==='tree'?(members.length?<ul className="ptl-tree">{members.filter(member=>member.depth===1).map(member=>renderBranch(member))}</ul>:<Empty title={translate('portal','no_referrals_yet')}/>):<Table rows={view==='direct'?members.filter(member=>member.depth===1):members} columns={[{key:'name',title:translate('portal','col_name')},{key:'dealer_code',title:translate('portal','col_dealer_code')},{key:'depth',title:translate('portal','col_referral_depth')},{key:'timeline',title:translate('portal','col_purchases'),render:r=><Link className="text-link" href={'/network?member_id='+r.id}>{translate('portal','view_timeline')}</Link>},{key:'created_at',title:translate('portal','col_joined'),render:r=>date(r.created_at)}]}/>}{truncated&&<div className="notice ops-mt-3"><p>{translate('portal','net_truncated_notice',{shown:String(members.length),total:String(total??members.length)})}</p></div>}</section>;
}
/** Staff partner tree: it opens with the top levels; this loads the next 300 partners. */
export function TreeShowMore({data,base}:{data:Row,base:string}){
 const translate=useT();
 const [loading,setLoading]=useState(false);
 if(!data.dealers_truncated)return null;
 const shown=(data.dealers||[]).length;
 return <div className="notice ops-mt-3 ops-row is-between is-wrap"><p>{translate('portal','net_truncated_notice',{shown:String(shown),total:String(data.dealers_total||shown)})}</p>{data.dealers_next_limit&&<button type="button" className="button small" disabled={loading} onClick={()=>{setLoading(true);router.get(base+'/network-tree',{tree_limit:String(data.dealers_next_limit)},{preserveState:true,preserveScroll:true,replace:true,only:['data'],onFinish:()=>setLoading(false)});}}>{translate('portal','net_show_more',{count:String(Math.min(300,Number(data.dealers_total||0)-shown))})}</button>}</div>;
}
/** A partner's own team, or the whole tree for the office, and one member's purchases. */
export default function Network(props:PortalSectionProps){
 const {section,d,staff,translate}=usePortalSection(props);
 return <>
 {section==='network'&&!staff&&d.team_summary&&<TeamSummary summary={d.team_summary}/>}{section==='network'&&(staff||Array.isArray(d.dealers)?<div data-tour="network-panel"><NetworkTreeExplorer dealers={d.dealers||[]}/>{d.dealers_truncated&&<div className="notice ops-mt-3"><p>{translate('portal','net_truncated_notice',{shown:String((d.dealers||[]).length),total:String(d.dealers_total||(d.dealers||[]).length)})}</p></div>}</div>:<div data-tour="network-panel"><PartnerNetwork members={d.members||[]} total={d.members_total} truncated={!!d.members_truncated}/></div>)}
 {section==='network'&&d.member_orders&&<section className="panel table-panel"><h2>{d.selected_member?.name} · {translate('portal','sal_purchase_timeline')}</h2><Table printable totalCount={d.member_orders.total} totalLabel={pageTotalLabel(d.member_orders,translate)} rows={d.member_orders.data} columns={[{key:'created_at',title:translate('portal','col_date'),render:r=>date(r.created_at)},{key:'number',title:translate('portal','sal_col_order')},{key:'status',title:translate('portal','col_status'),render:r=><Badge status={r.status}/>},{key:'total',title:translate('portal','sal_col_purchase_value'),money:true,total:true,render:r=>money(r.total)},{key:'paid',title:translate('portal','sal_col_verified_payment'),money:true,total:true,render:r=>money(r.paid)}]}/><Pagination page={d.member_orders}/></section>}
 </>;
}
