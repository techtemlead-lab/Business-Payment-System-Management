import React, {useState} from 'react';
import {Link, router, useForm, usePage} from '@inertiajs/react';
import OfficeHubTabs from '../OfficeHubTabs';
import {actionAllowed} from '../action-permissions';
import {Badge, Modal, Pagination, Row, Table, label, money, date, dateTime} from '../UI';
import {businessMonth} from '../../business-date';
import {useT} from '../../shared/i18n/t';
import {randomUuid} from '../../shared/randomUuid';
import {payoutMethods} from '../../shared/payoutMethods';

export type TranslateFn = (group:string,key:string,replace?:Record<string,string>)=>string;
export type Option = {value:string;label:string};
export type Field = {name:string;label:string;type?:string;options?:Option[];required?:boolean;value?:string};
export type Action = {title:string;action:string;fields:Field[];defaults?:Row;policy?:boolean;policyRules?:Row;fundingSources?:string[]};
export const root = '/commands/operations';
export type TableFilter = {searchable?:boolean;statuses?:string[];q?:string;status?:string};
/** Search box and status filter for one paged table; both run on the server, so they cover every page. */
export function TableSearch({tableKey,filter}:{tableKey:string;filter?:TableFilter}){
    const translate=useT();
    const [q,setQ]=useState(filter?.q??'');
    const statuses=filter?.statuses??[];
    if(!filter?.searchable&&statuses.length===0)return null;
    const apply=(next:{q?:string;status?:string})=>{
        const params=new URLSearchParams(window.location.search);
        [...params.keys()].filter(key=>/^page_\d+$/.test(key)).forEach(key=>params.delete(key));
        const values={q:next.q??q,status:next.status??filter?.status??''};
        for(const [name,value] of Object.entries(values)){
            if(value)params.set(`${tableKey}_${name}`,value);else params.delete(`${tableKey}_${name}`);
        }
        router.get(window.location.pathname,Object.fromEntries(params.entries()),{preserveState:true,preserveScroll:true});
    };
    const filtered=Boolean(filter?.q||filter?.status);
    return <form className="filter-bar order-filters" onSubmit={e=>{e.preventDefault();apply({q});}}>
        {filter?.searchable&&<div className="order-search"><div className="search-input"><input aria-label={translate('portal','table_search')} placeholder={translate('portal','table_search')} value={q} onChange={e=>setQ(e.target.value)}/></div><button className="button small dark" type="submit">{translate('portal','search')}</button></div>}
        {statuses.length>0&&<select className="order-status-select" aria-label={translate('portal','table_status')} value={filter?.status??''} onChange={e=>apply({status:e.target.value})}><option value="">{translate('portal','table_all')}</option>{statuses.map(item=><option key={item} value={item}>{label(item,translate)}</option>)}</select>}
        {filtered&&<div className="order-filter-actions"><button type="button" className="text-link" onClick={()=>{setQ('');apply({q:'',status:''});}}>{translate('portal','clear')}</button></div>}
    </form>;
}
export function parseJson(value: unknown, invalidLabel = 'Invalid stored JSON'): unknown {
    if (typeof value !== 'string') {
        return value;
    }
    try {
        return JSON.parse(value);
    } catch {
        return {error: invalidLabel};
    }
}
export const field = (name:string,title:string,type='text',required=true):Field => ({name,label:title,type,required});
export const options = (name:string,title:string,values:string[],required=true, yes='Yes', no='No', translate?:TranslateFn):Field => ({name,label:title,required,options:values.map(value=>({value,label:value==='1'?yes:value==='0'?no:label(value,translate)}))});
/** Amount columns in the generic operations tables (shown with money(), right-aligned). */
export const moneyKeys = new Set(['total','paid','due_credit','outstanding','target','bonus','gross','deduction','net','base_salary','allowance','amount','held','held_released','recovered','before_outstanding','after_outstanding','refunded_basis']);
/** Amount columns whose page sum means something (flows, not snapshots or rates). */
export const totalKeys = new Set(['total','paid','outstanding','gross','deduction','net','amount','held','recovered']);
/** Reserve fund names use their own Bangla and English labels instead of the stored code. */
export const reserveProgramLabel=(value:string,translate:TranslateFn):string=>{const key=`prc_reserve_program_${value}`;const text=translate('portal',key);return text!==key?text:label(value,translate);};
export const enumKeys = new Set(['kind','program','category','scope','level','role']);
export const isMoneyKey = (key:string) => moneyKeys.has(key) || key.endsWith('_amount');
/** Column heading for a database key: a portal translation when one exists, else a readable label. */
export function columnLabel(key:string, translate:TranslateFn):string {
    for (const candidate of [`ops_col_${key}`,`col_${key}`,`ops_fld_${key}`]) {
        const localized = translate('portal',candidate);
        if (localized !== candidate) {
            return localized;
        }
    }
    return label(key,translate);
}
/** Display value for a generic operations table cell: money, Dhaka dates, yes/no and translated codes. */
export function cellValue(key:string, value:unknown, translate:TranslateFn):React.ReactNode {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    if (key === 'status' || key === 'partner_status') {
        return <Badge status={String(value)}/>;
    }
    if (typeof value === 'boolean') {
        return translate('portal',value?'ops_fld_yes':'ops_fld_no');
    }
    if (isMoneyKey(key)) {
        return money(value);
    }
    if (/_at$/.test(key)) {
        return dateTime(String(value));
    }
    if (/(_date|_on)$/.test(key)) {
        return date(String(value));
    }
    if (/_month$/.test(key)) {
        return businessMonth(String(value));
    }
    if (key === 'program' && typeof value === 'string') {
        return reserveProgramLabel(value,translate);
    }
    if (enumKeys.has(key) && typeof value === 'string') {
        return label(value,translate);
    }
    return String(value);
}
export function OperationForm({title,action,fields,defaults={},policy,policyRules={},fundingSources=[],onDone}:Action & {onDone?:()=>void}) {
    const translate=useT();
    const form = useForm<Row>({...Object.fromEntries(fields.map(f=>[f.name,f.type==='file'?null:(f.value??'')])),...defaults});
    const [fundCount,setFundCount]=useState(1);
    const [itemCount,setItemCount] = useState(1);
    const itemFields = fields.filter(f=>f.name.startsWith('items.0.'));
    const recipientKeys = ['own_dealer_cashback','direct_referral','d2d_gen2','d2d_gen3','area_division','area_district','area_upazila','area_union'];
    const parameters = policy && form.data.scope!=='recipient' ? Object.fromEntries(Object.entries(policyRules[form.data.rule_key]?.parameters??{}).filter(([key])=>key!=='dynamic_compression')) : {};
    const parameterFields:Field[] = Object.entries(parameters).map(([key,value])=>typeof value==='boolean'
        ? {...options('parameters.'+key,label(key,translate),['1','0'],true,translate('portal','ops_fld_yes'),translate('portal','ops_fld_no')),value:value?'1':'0'}
        : {...field('parameters.'+key,label(key.replace(/_pct$/,' (%)'),translate),'number',!key.endsWith('_award_amount')),value:String(value??'')});
    const fundingFields:Field[]=policy&&form.data.scope==='recipient'?[{name:'funding_mode',label:translate('portal','ops_fld_funding_mode'),required:true,options:[{value:'company',label:translate('portal','ops_fld_funding_company')},{value:'allocations',label:translate('portal','ops_fld_funding_allocations')}]},...(form.data.funding_mode==='allocations'?Array.from({length:fundCount},(_,index)=>[
        {name:`funding_sources.${index}.category`,label:translate('portal','ops_fld_funding_source',{n:String(index+1)}),required:true,options:fundingSources.filter(category=>category!==form.data.rule_key&&!Array.from({length:fundCount},(_,other)=>other===index?'':form.data[`funding_sources.${other}.category`]).includes(category)).map(value=>({value,label:label(value,translate)}))},field(`funding_sources.${index}.percentage`,translate('portal','ops_fld_funding_rate',{n:String(index+1)}),'number')
    ]).flat():[])]:[];
    const visibleFields = [...fields.flatMap(f=>f.name.startsWith('items.0.')
        ? Array.from({length:itemCount},(_,index)=>({...f,name:f.name.replace('items.0.',`items.${index}.`),label:`${f.label} ${index+1}`})) : [f]), ...parameterFields, ...fundingFields];
    const change = (name:string,value:any) => {
        if (policy && ['scope','rule_key'].includes(name)) {
            form.setData((current:Row)=>{
                const next=Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith('parameters.')&&!key.startsWith('funding_sources.')));
                next[name]=value;
                if(name==='scope'){next.rule_key='';next.percentage='';}
                if(name==='rule_key')next.percentage=String(policyRules[value]?.percentage??'');
                return next;
            });
        } else if(name==='channel'&&'payment_account_id' in form.data){form.setData((current:Row)=>({...current,channel:value,payment_account_id:''}));} else form.setData(name,value);
    };
    return <form className="action-form" onSubmit={event=>{
        event.preventDefault();
        form.transform(input=>{
            const data:Row={};
            for (const [name,value] of Object.entries(input)) {
                if (value==='' && !fields.find(f=>f.name===name)?.required) continue;
                const path=name.split('.');let target=data;
                path.forEach((part,index)=>{if(index===path.length-1)target[part]=value;else {target[part]??=/^\d+$/.test(path[index+1])?[]:{};target=target[part];}});
            }
            if(policy){
                const rule:Row={percentage:data.percentage};
                if(data.scope!=='recipient'&&Object.keys(parameters).length){
                    rule.parameters={...parameters,...data.parameters};
                    for(const key of Object.keys(parameters))if(typeof parameters[key]==='boolean')rule.parameters[key]=rule.parameters[key]===true||rule.parameters[key]==='1';
                }
                if(data.scope==='recipient'){rule.funding_mode=data.funding_mode;if(data.funding_mode==='allocations')rule.funding_sources=Object.fromEntries((data.funding_sources??[]).map((source:Row)=>[source.category,source.percentage]));}delete data.funding_mode;delete data.funding_sources;
                data.rules={[data.rule_key]:rule};delete data.rule_key;delete data.percentage;delete data.parameters;if(data.scope==='global')delete data.user_id;
            }
            if(data.program==='marketing')delete data.user_id;
            return data;
        });
        form.post(action,{preserveScroll:true,onSuccess:()=>{form.reset();setItemCount(1);for(const key of ['idempotency_key','request_key'])if(key in form.data)form.setData(key,randomUuid());onDone?.();}});
    }} aria-label={title}>
        {visibleFields.map(f=>{
            const choices = policy&&f.name==='rule_key'&&form.data.scope==='recipient' ? recipientKeys.map(value=>({value,label:label(value,translate)})) : f.name==='payment_account_id' ? f.options?.filter(o=>(o as Row).channel===form.data.channel) : f.options;
            const value=form.data[f.name]??f.value??'';
            return <label key={f.name}>{f.label}{choices?<select required={f.required} value={String(value)} onChange={e=>change(f.name,e.target.value)}><option value="">{translate('portal','ops_fld_select')}</option>{choices.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:f.type==='textarea'?<textarea rows={3} required={f.required} value={value} onChange={e=>change(f.name,e.target.value)}/>:f.type==='file'?<input required={f.required} type="file" accept={f.name==='evidence'?'application/pdf,image/jpeg,image/png,.xlsx':'application/pdf,image/jpeg,image/png'} onChange={e=>change(f.name,e.target.files?.[0]??null)}/>:<input required={f.required} type={f.type??'text'} step={f.type==='number'?(f.name.endsWith('.quantity')?'1':'0.01'):undefined} value={value} onChange={e=>change(f.name,e.target.value)}/>} {form.errors[f.name]&&<span className="field-error">{String(form.errors[f.name])}</span>}</label>;
        })}
        {policy&&form.data.scope==='recipient'&&form.data.funding_mode==='allocations'&&<div className="ops-row"><button className="button" type="button" disabled={fundCount>=fundingSources.length-1} onClick={()=>setFundCount(fundCount+1)}>{translate('portal','ops_add_funding_source')}</button>{fundCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`funding_sources.${fundCount-1}.`))));setFundCount(fundCount-1);}}>{translate('portal','ops_remove_last_source')}</button>}</div>}
        {itemFields.length>0&&<div className="ops-row"><button className="button" type="button" disabled={itemCount>=100} onClick={()=>{for(const f of itemFields)form.setData(f.name.replace('items.0.',`items.${itemCount}.`),f.name.endsWith('.quantity')?'1':'');setItemCount(itemCount+1);}}>{translate('portal','ops_add_product')}</button>{itemCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`items.${itemCount-1}.`))));setItemCount(itemCount-1);}}>{translate('portal','ops_remove_last_product')}</button>}</div>}
        {Object.keys(form.errors).length>0&&<div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
        {form.progress&&<progress max={100} value={form.progress.percentage} aria-label={translate('portal','ops_upload_progress')}/>}
        <button className="button dark" disabled={form.processing}>{form.processing?translate('portal','ops_recording'):title}</button>
    </form>;
}

export function Detail({value}:{value:any}):React.ReactNode {
    const translate=useT();
    if(value===null||value===undefined)return '—';
    if(typeof value!=='object')return typeof value==='boolean'?(value?translate('portal','ops_fld_yes'):translate('portal','ops_fld_no')):String(value);
    return <dl className="opr-detail">{Object.entries(value).map(([key,item])=><div key={key}><dt>{label(key,translate)}</dt><dd><Detail value={item}/></dd></div>)}</dl>;
}

/**
 * The names an Operations section reads, the same ones the page used before it was split, so forms and markup moved over unchanged.
 * The open dialog (`selected`) and the account being edited (`account`) belong to the calling section.
 */
export function useOperationsPage(props:Row){
    const {paymentAccounts,partners,staff,secondApprovers}=props;
    const translate=useT();
    const metricLabel=(key:string)=>{const localized=translate('portal',`ops_chk_${key}`);return localized===`ops_chk_${key}`?label(key,translate):localized;};
    const {auth,financeActive,staffActions,portalBase,collectionAccounts=[]}=usePage<any>().props;
    const capabilities:string[]=auth.capabilities;
    const [selected,setSelected]=useState<Action|null>(null);
    const [account,setAccount]=useState<Row|null>(null);
    const yes=translate('portal','ops_fld_yes');
    const no=translate('portal','ops_fld_no');
    const choice=(name:string,title:string,values:string[],required=true):Field=>options(name,title,values,required,yes,no,translate);
    const reason:Field={name:'reason',label:translate('portal','ops_fld_reason'),type:'textarea',required:true};
    const choose=(rows:Row[],name:string,title:string,required=true):Field=>({name,label:title,required,options:rows.map(r=>({value:String(r.id),label:r.dealer_code?`${r.name} · ${r.dealer_code}`:(r.name??`${r.label} · ${r.channel} · ${r.account_number}`)}))});
    const staffField=choose(staff.filter((s:Row)=>s.role!=='super_admin'),'staff_id',translate('portal','ops_fld_staff'));
    const partnerField=choose(partners,'user_id',translate('portal','ops_fld_partner'));
    const review=(title:string,action:string,decisions:string[]):Action=>({title,action,fields:[choice('decision',translate('portal','ops_fld_decision'),decisions),reason]});
    const payment=(title:string,action:string):Action=>({title,action,fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.pays_out),'payment_account_id',translate('portal','ops_fld_payout_account')),field('payment_reference',translate('portal','ops_fld_payment_ref')),field('paid_at',translate('portal','ops_fld_paid_at'),'datetime-local'),field('receipt',translate('portal','ops_fld_payment_receipt'),'file'),reason]});
    const confirmAlone=(label:string):Field=>({name:'self_approve',label,required:true,options:[{value:'1',label:translate('self_approval','confirm_yes')}]});
    const aloneFor=(people:number[])=>auth.user.role==='super_admin'&&(secondApprovers as number[]).filter(id=>!people.includes(id)).length===0;
    const alone=(a:Action):Action=>({...a,title:`${a.title} (${translate('self_approval','alone_tag')})`,fields:[...a.fields.map(f=>f.name==='receipt'?{...f,required:true}:f),confirmAlone(translate('self_approval','confirm_label'))]});
    const names={translate,metricLabel,auth,financeActive,staffActions,portalBase,collectionAccounts,capabilities,selected,setSelected,account,setAccount,yes,no,choice,reason,choose,staffField,partnerField,review,payment,confirmAlone,aloneFor,alone};
    return {...props,...names} as Row&typeof names;
}
export type OperationsPage=ReturnType<typeof useOperationsPage>;

/** What every Operations section shares: hub tabs, notices, its own forms, its tables, and one dialog. `top`, `middle` and `below` hold the section's own panels. */
export function OperationsFrame({page,forms=[],top,middle,below}:{page:OperationsPage,forms?:Action[],top?:React.ReactNode,middle?:React.ReactNode,below?:React.ReactNode}){
    const {section,tables,hub,paymentAccounts,partners,partnerSearch,staff,summary,policy,translate,metricLabel,auth,financeActive,staffActions,portalBase,capabilities,selected,setSelected,account,setAccount,reason,choose,review,payment,aloneFor,alone}=page;
    const rowActions=(row:Row,tableKey:string)=> {
        const actions:Action[]=[];
        if(tableKey==='territories'&&row.is_active)actions.push({title:translate('portal','ops_remove_territory'),action:`${root}/staff/territories/${row.id}/remove`,fields:[reason]});
        if(tableKey==='dealer_applications'&&row.status==='pending'&&capabilities.includes('accounts')&&row.submitted_by!==auth.user.id){const applicationReview=review(translate('portal','ops_review_application'),`${root}/staff/applications/${row.id}/review`,['approved','rejected']);actions.push({...applicationReview,fields:[...applicationReview.fields,field('place_code',translate('portal','ops_fld_place_code_review'),'text',false)]});}
        if(tableKey==='salary_register'&&section==='payroll'&&financeActive&&capabilities.includes('finance')) {
            if(row.status==='draft'&&row.staff_id!==auth.user.id){const a=review(translate('portal','ops_review_payroll'),`${root}/payroll/${row.id}/review`,['approved','rejected']);if(row.prepared_by!==auth.user.id)actions.push(a);else if(aloneFor([row.prepared_by,row.staff_id]))actions.push(alone(a));}
            if(row.status==='approved'&&row.staff_id!==auth.user.id){const a=payment(translate('portal','ops_record_salary_payment'),`${root}/payroll/${row.id}/pay`);if(row.approved_by!==auth.user.id)actions.push(a);else if(aloneFor([row.approved_by,row.staff_id]))actions.push(alone(a));}
        }
        if(tableKey==='policy_schedules'){const isOwner=auth.user.role==='super_admin';const isMine=row.created_by===auth.user.id;actions.push(review(translate('portal','ops_review_schedule'),`${root}/policy-schedules/${row.id}/review`,[...(row.status==='draft'&&isOwner&&!isMine?['approved','rejected']:[]),...(row.status==='draft'&&(isMine||isOwner||capabilities.includes('finance'))?['cancelled']:[]),...(row.status==='approved'&&isOwner&&row.origin!=='rule_edit'?['cancelled']:[])]));}
        if(tableKey==='reserve_requests'&&financeActive) {
            if(row.status==='requested'&&row.user_id!==auth.user.id){const a=review(translate('portal','ops_review_reserve_request'),`${root}/reserves/${row.id}/review`,['approved','rejected']);if(row.created_by!==auth.user.id)actions.push(a);else if(aloneFor([row.created_by,row.user_id]))actions.push(alone(a));}
            if(row.program==='marketing'&&row.status==='approved') {
                // Same method details as a withdrawal payout; the server checks which ones each method needs.
                if(row.approved_by!==auth.user.id||aloneFor([row.approved_by]))actions.push((row.approved_by===auth.user.id?alone:(a:Action)=>a)({title:translate('portal','ops_record_expense_payment'),action:`${root}/reserves/${row.id}/pay`,fields:[{name:'method',label:translate('portal','pay_fld_method'),required:true,options:payoutMethods.map(m=>({value:m,label:translate('portal',`payout_method_${m}`)}))},{...choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.pays_out),'payment_account_id',translate('portal','ops_fld_payout_account_not_cash')),required:false},field('payment_reference',translate('portal','ops_fld_payment_ref_not_cash'),'text',false),field('bank_name',translate('portal','ops_fld_bank_name_bank'),'text',false),field('account_number',translate('portal','ops_fld_account_number_bank'),'text',false),field('wallet_number',translate('portal','ops_fld_wallet_number_mobile'),'text',false),field('received_by',translate('portal','ops_fld_received_by_cash'),'text',false),field('paid_at',translate('portal','ops_fld_paid_at'),'datetime-local'),field('receipt',translate('portal','ops_fld_payment_receipt_not_cash'),'file',false),reason]}));
                actions.push(review(translate('portal','ops_cancel_unpaid_expense'),`${root}/reserves/${row.id}/review`,['cancelled']));
            }
        }
        if(tableKey==='held_allocations'&&financeActive&&row.partner_status!=='active'&&['direct_referral','d2d_gen2','d2d_gen3'].includes(row.category)&&(staffActions?.finance?.edit??true))actions.push({title:translate('portal','ops_select_replacement_release'),action:`${root}/holds/${row.id}/reassign`,fields:[choose(partners,'user_id',translate('portal','ops_fld_replacement_partner')),reason],defaults:{request_key:randomUuid()}});
        if(tableKey==='opening_holds'&&financeActive)actions.push({title:translate('portal','ops_review_and_release_opening_hold'),action:`${root}/imports/opening-holds/${row.id}/release`,fields:[reason]});
        if(tableKey==='held_allocations'&&financeActive)actions.push({title:translate('portal','ops_review_and_release_hold'),action:`${root}/holds/${row.id}/release`,fields:[reason]});
        if(tableKey==='opening_reviews'&&!row.verified_at){const a:Action={title:translate('portal','ops_verify_opening_evidence'),action:`${root}/opening-balances/${row.id}/verify`,fields:[]};if(row.created_by!==auth.user.id)actions.push(a);else if(aloneFor([row.created_by]))actions.push(alone({...a,fields:[reason]}));}
        if(tableKey==='reserve_clawbacks'&&Number(row.amount)>Number(row.recovered)&&financeActive)actions.push({...payment(translate('portal','ops_record_recovered_reserve'),`${root}/reserves/clawbacks/${row.id}/recover`),fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.collects_payments),'payment_account_id',translate('portal','ops_fld_collection_account')),field('amount',translate('portal','ops_fld_recovered_amount'),'number'),field('payment_reference',translate('portal','ops_fld_collection_ref')),field('paid_at',translate('portal','ops_fld_collection_time'),'datetime-local'),field('receipt',translate('portal','ops_fld_recovery_receipt'),'file'),reason]});
        const receiptKind=tableKey==='salary_register'?'payroll':tableKey==='reserve_requests'?'reserve':tableKey==='clawback_recoveries'?'recovery':tableKey==='opening_reviews'?'opening':null;
        return <div className="ops-row is-wrap">{tableKey==='territory_orders'&&capabilities.includes('sr_orders')&&<Link className="button small" href={`${root}/staff/orders/${row.id}`}>{translate('portal','ops_fld_order_details')}</Link>}{actions.filter(a=>actionAllowed(a.action,staffActions)).filter(a=>!a.fields.find(f=>f.name==='decision')?.options?.length&&a.fields.some(f=>f.name==='decision')?false:true).map(a=><button key={a.title} className="button small" onClick={()=>setSelected(a)}>{a.title}</button>)}{receiptKind&&(row.has_receipt||(receiptKind==='opening'&&!row.fresh_start))&&<a className="button small" href={`${root}/receipts/${receiptKind}/${row.id}`}>{translate('portal','ops_fld_private_evidence')}</a>}{tableKey==='reserve_requests'&&row.payout_receipt_id&&<a className="button small" href={`${portalBase??''}/payment-receipts/${row.payout_receipt_id}/print`} target="_blank" rel="noopener">{translate('portal','prc_view_print')}</a>}</div>;
    };
    return <><p className="muted">{translate('portal','ops_fld_brand_line')}</p>{hub&&<OfficeHubTabs hub={hub}/>}
        {!financeActive&&capabilities.includes('finance')&&<div className="notice"><p>{translate('portal','ops_preview_finance_notice')}</p></div>}
        {top}
        {Object.keys(summary).length>0&&<section className="panel"><dl className="profile-grid">{Object.entries(summary).map(([key,value])=><div key={key}><dt>{metricLabel(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {middle}
        {['field-sales','policy-schedules','reserve-payouts','commission-holds'].includes(section)&&<form method="get" className="action-form"><label>{translate('portal','ops_find_partner')}<input name="q" type="search" defaultValue={partnerSearch}/></label><button className="button">{translate('portal','ops_search_partners')}</button></form>}
        {below}
        {forms.filter(a=>actionAllowed(a.action,staffActions,a.defaults?._method??'post')).map(a=><section className="panel narrow-panel" key={a.title+(account?.version??'')}><h2>{a.title}</h2><OperationForm {...a} onDone={()=>{if(section==='payment-accounts')setAccount(null);}}/></section>)}
        {tables.map((table:Row)=>{const rows=table.page.data as Row[];const tableKey=String(table.key??table.title??'');const tableTitle=translate('portal',`ops_tbl_${tableKey}`);const shown=[...new Set(rows.flatMap(r=>Object.keys(r)))].filter(k=>!['staff_id','submitted_by','created_by','approved_by','verified_by','user_id','has_receipt','payout_receipt_id','territory_key','territories','created_at','updated_at'].includes(k)&&!(k==='id'&&rows.some(r=>r.number)));const keys=rows.some(r=>r.created_at)?['created_at',...shown]:shown;const multiPage=Number(table.page.last_page??1)>1;return <section className="panel" key={tableKey}><h2>{tableTitle===`ops_tbl_${tableKey}`?label(tableKey,translate):tableTitle}</h2><TableSearch tableKey={tableKey} filter={table.filter}/><Table searchable={false} printable totalCount={table.page.total} totalLabel={multiPage?translate('portal','table_page_total'):undefined} rows={rows} columns={[...keys.map(key=>({key,title:columnLabel(key,translate),money:isMoneyKey(key),total:totalKeys.has(key),render:(row:Row)=>['rules','metadata','data','snapshot'].includes(key)?<details><summary>{translate('portal','ops_view_details')}</summary><div className="opr-json"><Detail value={parseJson(row[key], translate('portal','ops_invalid_json'))}/></div></details>:cellValue(key,row[key],translate)})),{key:'actions',title:translate('portal','ops_actions'),render:r=>rowActions(r,tableKey)}]}/><Pagination page={table.page}/></section>;})}
        {selected&&<Modal title={selected.title} onClose={()=>setSelected(null)}><OperationForm {...selected} onDone={()=>setSelected(null)}/></Modal>}
    </>;
}
