import React, {useState} from 'react';
import {Head, Link, useForm, usePage} from '@inertiajs/react';
import Shell from '../components/Shell';
import StaffAccessEditor from '../components/StaffAccessEditor';
import {actionAllowed} from '../components/action-permissions';
import {Badge, Empty, Modal, Pagination, Row, Table, label, money, DateInput} from '../components/UI';
import {useT} from '../shared/i18n/t';

type Option = {value:string;label:string};
type Field = {name:string;label:string;type?:string;options?:Option[];required?:boolean;value?:string};
type Action = {title:string;action:string;fields:Field[];defaults?:Row;policy?:boolean;policyRules?:Row;fundingSources?:string[]};
const root = '/commands/operations';
const sectionTitleKeys:Record<string,string> = {
    'field-sales':'field_sales',
    'staff-operations':'staff_operations',
    payroll:'payroll',
    'my-payroll':'my_payroll',
    'payment-accounts':'payment_accounts',
    'policy-schedules':'policy_schedules',
    'reserve-payouts':'reserve_payouts',
    'commission-holds':'commission_holds',
    'financial-readiness':'financial_readiness',
    'financial-reports':'financial_reports',
    'dues-adjustments':'dues_adjustments',
    'finance-settings':'finance_settings',
    'access-management':'access_management',
    'video-marketing':'video_marketing',
};
function parseJson(value: unknown, invalidLabel = 'Invalid stored JSON'): unknown {
    if (typeof value !== 'string') {
        return value;
    }
    try {
        return JSON.parse(value);
    } catch {
        return {error: invalidLabel};
    }
}
const field = (name:string,title:string,type='text',required=true):Field => ({name,label:title,type,required});
const options = (name:string,title:string,values:string[],required=true, yes='Yes', no='No'):Field => ({name,label:title,required,options:values.map(value=>({value,label:value==='1'?yes:value==='0'?no:label(value)}))});
function OperationForm({title,action,fields,defaults={},policy,policyRules={},fundingSources=[],onDone}:Action & {onDone?:()=>void}) {
    const translate=useT();
    const form = useForm<Row>({...Object.fromEntries(fields.map(f=>[f.name,f.type==='file'?null:(f.value??'')])),...defaults});
    const [fundCount,setFundCount]=useState(1);
    const [itemCount,setItemCount] = useState(1);
    const itemFields = fields.filter(f=>f.name.startsWith('items.0.'));
    const recipientKeys = ['own_dealer_cashback','direct_referral','d2d_gen2','d2d_gen3','area_division','area_district','area_upazila','area_union'];
    const parameters = policy && form.data.scope!=='recipient' ? Object.fromEntries(Object.entries(policyRules[form.data.rule_key]?.parameters??{}).filter(([key])=>key!=='dynamic_compression')) : {};
    const parameterFields:Field[] = Object.entries(parameters).map(([key,value])=>typeof value==='boolean'
        ? {...options('parameters.'+key,label(key,translate),['1','0'],true,translate('portal','ops_fld_yes'),translate('portal','ops_fld_no')),value:value?'1':'0'}
        : {...field('parameters.'+key,label(key.replace(/_pct$/,' (%)'),translate),'number'),value:String(value)});
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
        } else form.setData(name,value);
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
        form.post(action,{preserveScroll:true,onSuccess:()=>{form.reset();setItemCount(1);for(const key of ['idempotency_key','request_key'])if(key in form.data)form.setData(key,crypto.randomUUID());onDone?.();}});
    }} aria-label={title}>
        {visibleFields.map(f=>{
            const choices = policy&&f.name==='rule_key'&&form.data.scope==='recipient' ? recipientKeys.map(value=>({value,label:label(value,translate)})) : f.options;
            const value=form.data[f.name]??f.value??'';
            return <label key={f.name}>{f.label}{choices?<select required={f.required} value={String(value)} onChange={e=>change(f.name,e.target.value)}><option value="">{translate('portal','ops_fld_select')}</option>{choices.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:f.type==='textarea'?<textarea rows={3} required={f.required} value={value} onChange={e=>change(f.name,e.target.value)}/>:f.type==='file'?<input required={f.required} type="file" accept={f.name==='evidence'?'application/pdf,image/jpeg,image/png,.xlsx':'application/pdf,image/jpeg,image/png'} onChange={e=>change(f.name,e.target.files?.[0]??null)}/>:<input required={f.required} type={f.type??'text'} step={f.type==='number'?(f.name.endsWith('.quantity')?'1':'0.01'):undefined} value={value} onChange={e=>change(f.name,e.target.value)}/>} {form.errors[f.name]&&<span className="field-error">{String(form.errors[f.name])}</span>}</label>;
        })}
        {policy&&form.data.scope==='recipient'&&form.data.funding_mode==='allocations'&&<div style={{display:'flex',gap:8}}><button className="button" type="button" disabled={fundCount>=fundingSources.length-1} onClick={()=>setFundCount(fundCount+1)}>{translate('portal','ops_add_funding_source')}</button>{fundCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`funding_sources.${fundCount-1}.`))));setFundCount(fundCount-1);}}>{translate('portal','ops_remove_last_source')}</button>}</div>}
        {itemFields.length>0&&<div style={{display:'flex',gap:8}}><button className="button" type="button" disabled={itemCount>=100} onClick={()=>{for(const f of itemFields)form.setData(f.name.replace('items.0.',`items.${itemCount}.`),f.name.endsWith('.quantity')?'1':'');setItemCount(itemCount+1);}}>{translate('portal','ops_add_product')}</button>{itemCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`items.${itemCount-1}.`))));setItemCount(itemCount-1);}}>{translate('portal','ops_remove_last_product')}</button>}</div>}
        {Object.keys(form.errors).length>0&&<div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
        {form.progress&&<progress max={100} value={form.progress.percentage} aria-label={translate('portal','ops_upload_progress')}/>}
        <button className="button dark" disabled={form.processing}>{form.processing?translate('portal','ops_recording'):title}</button>
    </form>;
}

function Detail({value}:{value:any}):React.ReactNode {
    const translate=useT();
    if(value===null||value===undefined)return '—';
    if(typeof value!=='object')return typeof value==='boolean'?(value?translate('portal','ops_fld_yes'):translate('portal','ops_fld_no')):String(value);
    return <dl style={{margin:0}}>{Object.entries(value).map(([key,item])=><div key={key} style={{margin:'8px 0'}}><dt style={{fontWeight:600}}>{label(key)}</dt><dd style={{margin:'3px 0 3px 10px'}}><Detail value={item}/></dd></div>)}</dl>;
}

function Reports({salesOnly=false}:{salesOnly?:boolean}) {
    const translate=useT();
    const [period,setPeriod]=useState('month');
    const dhaka=new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Dhaka'}));
    const iso=(date:Date)=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const [from,setFrom]=useState(iso(new Date(dhaka.getFullYear(),dhaka.getMonth(),1)));
    const [to,setTo]=useState(iso(dhaka));
    return <section className="panel"><h2>{translate('portal','ops_download_reports')}</h2><p>{translate('portal','ops_download_reports_hint')}</p><form className="action-form" action={root+'/reports'} method="get">
        <label>{translate('portal','ops_report')}<select required name="type">{(salesOnly?['orders','dues','payroll']:['orders','products','product-summary','category-summary','dues','due-adjustments','commissions','settlements','payroll','withdrawals','ledger','rewards','rank-achievements','rank-incentives','refunds']).map(t=><option key={t} value={t}>{t==='products'?translate('portal','ops_report_products'):t==='refunds'?translate('portal','ops_report_refunds'):label(t.replaceAll('-','_'))}</option>)}</select></label>
        <label>{translate('portal','ops_period')}<select value={period} onChange={e=>{const value=e.target.value;setPeriod(value);if(value!=='custom'){const start=new Date(dhaka);if(value==='week')start.setDate(start.getDate()-((start.getDay()+6)%7));if(value==='month')start.setDate(1);setFrom(iso(start));setTo(iso(dhaka));}}}><option value="day">{translate('portal','ops_period_day')}</option><option value="week">{translate('portal','ops_period_week')}</option><option value="month">{translate('portal','ops_period_month')}</option><option value="custom">{translate('portal','ops_period_custom')}</option></select></label>
        <label>{translate('portal','date_from')}<DateInput name="from" required value={from} onChange={e=>{setFrom(e.target.value);setPeriod('custom');}}/></label><label>{translate('portal','ops_through')}<DateInput name="to" required min={from} value={to} onChange={e=>{setTo(e.target.value);setPeriod('custom');}}/></label>
        <label>{translate('portal','ops_format')}<select name="format"><option value="xlsx">{translate('portal','ops_format_xlsx')}</option><option value="pdf">{translate('portal','ops_format_pdf')}</option></select></label><button className="button dark">{translate('portal','ops_download_report')}</button>
    </form></section>;
}

export default function Operations({section,tables,paymentAccounts=[],partners=[],partnerSearch='',staff=[],summary={},policy,readiness,deploymentStatus,startupMode,regions=[],products=[],accountHistory=[],adjustableOrders=[],withdrawalSetting,permissionModules=[],staffAccess=[],customRoles=[],rolePresets=[],videoSetting={},videoProducts=[]}:Row) {
    const translate=useT();
    const metricLabel=(key:string)=>{const localized=translate('portal',`ops_chk_${key}`);return localized===`ops_chk_${key}`?label(key):localized;};
    const sectionTitle=translate('portal', sectionTitleKeys[section] ?? section);
    const {auth,financeActive,staffActions}=usePage<any>().props;
    const capabilities:string[]=auth.capabilities;
    const [selected,setSelected]=useState<Action|null>(null);
    const [account,setAccount]=useState<Row|null>(null);
    const [adjustmentOrder,setAdjustmentOrder]=useState('');
    const selectedOrder=adjustmentOrder||String(adjustableOrders[0]?.id??'');
    const yes=translate('portal','ops_fld_yes');
    const no=translate('portal','ops_fld_no');
    const reason:Field={name:'reason',label:translate('portal','ops_fld_reason'),type:'textarea',required:true};
    const choose=(rows:Row[],name:string,title:string,required=true):Field=>({name,label:title,required,options:rows.map(r=>({value:String(r.id),label:r.dealer_code?`${r.name} · ${r.dealer_code}`:(r.name??`${r.label} · ${r.channel} · ${r.account_number}`)}))});
    const staffField=choose(staff,'staff_id',translate('portal','ops_fld_staff'));
    const partnerField=choose(partners,'user_id',translate('portal','ops_fld_partner'));
    const review=(title:string,action:string,decisions:string[]):Action=>({title,action,fields:[options('decision',translate('portal','ops_fld_decision'),decisions),reason]});
    const payment=(title:string,action:string):Action=>({title,action,fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.pays_out),'payment_account_id',translate('portal','ops_fld_payout_account')),field('payment_reference',translate('portal','ops_fld_payment_ref')),field('paid_at',translate('portal','ops_fld_paid_at'),'datetime-local'),field('receipt',translate('portal','ops_fld_payment_receipt'),'file'),reason]});
    const forms:Action[]=[];
    if(section==='finance-settings'&&withdrawalSetting)forms.push({title:translate('portal','ops_save_withdrawal_minimum'),action:root+'/settings/withdrawal-minimum',fields:[field('amount',translate('portal','ops_fld_min_withdrawal'),'number'),reason],defaults:{amount:withdrawalSetting.amount,version:withdrawalSetting.version}});
    if(section==='video-marketing')forms.push({title:translate('portal','ops_save_homepage_video'),action:root+'/marketing/homepage-video',fields:[options('enabled',translate('portal','ops_fld_show_video'),['1','0'],true,yes,no),choose(videoProducts,'product_id',translate('portal','ops_fld_product_video'),false),field('title',translate('portal','ops_fld_section_title')),field('description',translate('portal','ops_fld_introduction'),'textarea',false),reason],defaults:{...videoSetting,enabled:videoSetting.enabled?'1':'0',title:videoSetting.title??translate('portal','ops_video_title_default')}});
    if(section==='dues-adjustments'&&selectedOrder)forms.push({title:translate('portal','ops_propose_due_adjustment'),action:`${root}/orders/${selectedOrder}/due-adjustments`,fields:[options('kind',translate('portal','ops_fld_adjustment_type'),['credit','debit_correction']),field('amount',translate('portal','ops_fld_amount_bdt'),'number'),field('reference',translate('portal','ops_fld_credit_ref')),reason],defaults:{request_key:crypto.randomUUID()}});
    if(section==='staff-operations') {
        forms.push({title:translate('portal','ops_assign_territory'),action:root+'/staff/territories',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id',translate('portal','ops_fld_sales_rep')),options('level',translate('portal','ops_fld_territory_level'),['division','district','upazila','union']),field('division',translate('portal','ops_fld_division')),field('district',translate('portal','ops_fld_district'),'text',false),field('upazila',translate('portal','ops_fld_upazila'),'text',false),field('union',translate('portal','ops_fld_union'),'text',false)]});
        forms.push({title:translate('portal','ops_set_monthly_target'),action:root+'/staff/targets',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id',translate('portal','ops_fld_sales_rep')),field('period_month',translate('portal','ops_fld_target_month'),'month'),field('target',translate('portal','ops_fld_sales_target'),'number'),field('bonus',translate('portal','ops_fld_bonus'),'number'),field('version',translate('portal','ops_fld_version_revise'),'number',false)]});
    }
    if(section==='field-sales') {
        if(capabilities.includes('sr_orders'))forms.push({title:translate('portal','ops_submit_partner_order'),action:root+'/staff/orders',fields:[partnerField,choose(products,'items.0.product_id',translate('portal','ops_fld_product')),field('items.0.quantity',translate('portal','ops_fld_quantity'),'number'),field('note',translate('portal','ops_fld_order_note'),'textarea',false)],defaults:{idempotency_key:crypto.randomUUID()}});
        if(capabilities.includes('sr_onboarding'))forms.push({title:translate('portal','ops_submit_dealer_application'),action:root+'/staff/applications',fields:[field('name',translate('portal','ops_fld_full_name')),field('email',translate('portal','ops_fld_email'),'email'),field('phone',translate('portal','ops_fld_phone')),options('role',translate('portal','ops_fld_account_type'),['dealer','sub_dealer']),field('division',translate('portal','ops_fld_division')),field('district',translate('portal','ops_fld_district')),field('upazila',translate('portal','ops_fld_upazila'),'text',false),field('union',translate('portal','ops_fld_union'),'text',false),field('area_code',translate('portal','ops_fld_area_code'),'text',false),choose(partners.filter((p:Row)=>p.role==='dealer'),'sponsor_id',translate('portal','ops_fld_sponsor_optional'),false)]});
    }
    if(section==='payroll') {
        if(capabilities.includes('finance'))forms.push({title:translate('portal','ops_create_salary_contract'),action:root+'/payroll/contracts',fields:[staffField,field('effective_month',translate('portal','ops_fld_effective_month'),'month'),field('base_salary',translate('portal','ops_fld_base_salary'),'number'),field('allowance',translate('portal','ops_fld_allowance'),'number'),field('deduction',translate('portal','ops_fld_deduction'),'number'),reason]});
        forms.push({title:translate('portal','ops_prepare_payroll'),action:root+'/payroll/prepare',fields:[field('period_month',translate('portal','ops_fld_closed_salary_month'),'month')]});
    }
    if(section==='policy-schedules')forms.push({title:translate('portal','ops_propose_commission_schedule'),action:root+'/policy-schedules',policy:true,policyRules:policy?.rules??{},fundingSources:policy?.funding_sources??[],fields:[options('scope',translate('portal','ops_fld_schedule_scope'),['global','buyer','recipient']),{...partnerField,required:false,label:translate('portal','ops_fld_partner_custom')},{name:'rule_key',label:translate('portal','ops_fld_commission_rule'),required:true,options:Object.entries(policy?.rules??{}).map(([key,value]:[string,any])=>{const localized=translate('portal',`rule_name_${key}`);return{value:key,label:localized!==`rule_name_${key}`?localized:(value.name??key)};})},field('percentage',translate('portal','ops_fld_rate_pct'),'number'),field('effective_at',translate('portal','ops_fld_starts_dhaka'),'datetime-local'),field('expires_at',translate('portal','ops_fld_expires_dhaka'),'datetime-local',false),reason]});
    if(section==='reserve-payouts')forms.push({title:translate('portal','ops_request_reserve'),action:root+'/reserves',fields:[options('program',translate('portal','ops_fld_reserve_program'),['tour','additional_rank','bike','car','marketing']),{...partnerField,required:false},field('payee_name',translate('portal','ops_fld_expense_payee'),'text',false),field('amount',translate('portal','ops_fld_approved_amount'),'number'),reason],defaults:{request_key:crypto.randomUUID()}});
    if(section==='financial-readiness')forms.push({title:translate('portal','ops_submit_opening_balances'),action:root+'/opening-balances',fields:[field('wallet_available',translate('portal','ops_fld_wallet_available'),'number'),field('wallet_held',translate('portal','ops_fld_wallet_held'),'number'),field('order_paid',translate('portal','ops_fld_order_paid'),'number'),field('withdrawal_paid',translate('portal','ops_fld_withdrawal_paid'),'number'),field('source_reference',translate('portal','ops_fld_source_ref')),field('evidence',translate('portal','ops_fld_evidence'),'file')]});
    const accountFields:Field[]=[field('label',translate('portal','ops_fld_account_label')),options('channel',translate('portal','ops_fld_gateway'),['Bank','bKash','Nagad','Rocket']),field('holder',translate('portal','ops_fld_holder')),field('account_number',translate('portal','ops_fld_account_number')),field('details',translate('portal','ops_fld_payment_instructions'),'textarea',false),options('collects_payments',translate('portal','ops_fld_accept_collections'),['1','0'],true,yes,no),options('pays_out',translate('portal','ops_fld_allow_payouts'),['1','0'],true,yes,no),options('is_active',translate('portal','ops_fld_account_active'),['1','0'],true,yes,no),reason];
    if(section==='payment-accounts')forms.push({title:account?translate('portal','ops_save_account_version'):translate('portal','ops_add_payment_account'),action:root+'/payment-accounts'+(account?'/'+account.id:''),fields:accountFields,defaults:account?{...account,collects_payments:account.collects_payments?'1':'0',pays_out:account.pays_out?'1':'0',is_active:account.is_active?'1':'0',_method:'patch'}:{collects_payments:'1',pays_out:'0',is_active:'1'}});
    const rowActions=(row:Row,tableKey:string)=> {
        const actions:Action[]=[];
        if(tableKey==='due_adjustments'&&row.status==='requested'&&financeActive&&row.created_by!==auth.user.id)actions.push(review(translate('portal','ops_review_due_adjustment'),`${root}/due-adjustments/${row.id}/review`,['approved','rejected']));
        if(tableKey==='territories'&&row.is_active)actions.push({title:translate('portal','ops_remove_territory'),action:`${root}/staff/territories/${row.id}/remove`,fields:[reason]});
        if(tableKey==='dealer_applications'&&row.status==='pending'&&capabilities.includes('accounts')&&row.submitted_by!==auth.user.id)actions.push(review(translate('portal','ops_review_application'),`${root}/staff/applications/${row.id}/review`,['approved','rejected']));
        if(tableKey==='salary_register'&&section==='payroll'&&financeActive&&capabilities.includes('finance')) {
            if(row.status==='draft'&&![row.staff_id,row.prepared_by].includes(auth.user.id))actions.push(review(translate('portal','ops_review_payroll'),`${root}/payroll/${row.id}/review`,['approved','rejected']));
            if(row.status==='approved'&&![row.staff_id,row.approved_by].includes(auth.user.id))actions.push(payment(translate('portal','ops_record_salary_payment'),`${root}/payroll/${row.id}/pay`));
        }
        if(tableKey==='policy_schedules')actions.push(review(translate('portal','ops_review_schedule'),`${root}/policy-schedules/${row.id}/review`,[...(row.status==='draft'&&row.created_by!==auth.user.id?['approved']:[]),...(['draft','approved'].includes(row.status)?['cancelled']:[])]));
        if(tableKey==='reserve_requests'&&financeActive) {
            if(row.status==='requested'&&![row.created_by,row.user_id].includes(auth.user.id))actions.push(review(translate('portal','ops_review_reserve_request'),`${root}/reserves/${row.id}/review`,['approved','rejected']));
            if(row.program==='marketing'&&row.status==='approved') {
                if(row.approved_by!==auth.user.id)actions.push(payment(translate('portal','ops_record_expense_payment'),`${root}/reserves/${row.id}/pay`));
                actions.push(review(translate('portal','ops_cancel_unpaid_expense'),`${root}/reserves/${row.id}/review`,['cancelled']));
            }
        }
        if(tableKey==='held_allocations'&&financeActive&&row.partner_status!=='active'&&['direct_referral','d2d_gen2','d2d_gen3'].includes(row.category)&&(staffActions?.finance?.edit??true))actions.push({title:translate('portal','ops_select_replacement_release'),action:`${root}/holds/${row.id}/reassign`,fields:[choose(partners,'user_id',translate('portal','ops_fld_replacement_partner')),reason],defaults:{request_key:crypto.randomUUID()}});
        if(tableKey==='opening_holds'&&financeActive)actions.push({title:translate('portal','ops_review_and_release_opening_hold'),action:`${root}/imports/opening-holds/${row.id}/release`,fields:[reason]});
        if(tableKey==='held_allocations'&&financeActive)actions.push({title:translate('portal','ops_review_and_release_hold'),action:`${root}/holds/${row.id}/release`,fields:[reason]});
        if(tableKey==='opening_reviews'&&!row.verified_at&&row.created_by!==auth.user.id)actions.push({title:translate('portal','ops_verify_opening_evidence'),action:`${root}/opening-balances/${row.id}/verify`,fields:[]});
        if(tableKey==='reserve_clawbacks'&&Number(row.amount)>Number(row.recovered)&&financeActive)actions.push({...payment(translate('portal','ops_record_recovered_reserve'),`${root}/reserves/clawbacks/${row.id}/recover`),fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.collects_payments),'payment_account_id',translate('portal','ops_fld_collection_account')),field('amount',translate('portal','ops_fld_recovered_amount'),'number'),field('payment_reference',translate('portal','ops_fld_collection_ref')),field('paid_at',translate('portal','ops_fld_collection_time'),'datetime-local'),field('receipt',translate('portal','ops_fld_recovery_receipt'),'file'),reason]});
        const receiptKind=tableKey==='salary_register'?'payroll':tableKey==='reserve_requests'?'reserve':tableKey==='clawback_recoveries'?'recovery':tableKey==='opening_reviews'?'opening':null;
        return <div style={{display:'flex',gap:8,flexWrap:'wrap'}}>{tableKey==='territory_orders'&&capabilities.includes('sr_orders')&&<Link className="button small" href={`${root}/staff/orders/${row.id}`}>{translate('portal','ops_fld_order_details')}</Link>}{actions.filter(a=>actionAllowed(a.action,staffActions)).filter(a=>!a.fields.find(f=>f.name==='decision')?.options?.length&&a.fields.some(f=>f.name==='decision')?false:true).map(a=><button key={a.title} className="button small" onClick={()=>setSelected(a)}>{a.title}</button>)}{receiptKind&&(row.has_receipt||receiptKind==='opening')&&<a className="button small" href={`${root}/receipts/${receiptKind}/${row.id}`}>{translate('portal','ops_fld_private_evidence')}</a>}</div>;
    };
    return <Shell section={section}><Head title={sectionTitle}/><p className="muted">{translate('portal','ops_fld_brand_line')}</p>
        {!financeActive&&capabilities.includes('finance')&&<div className="notice"><p>{translate('portal','ops_preview_finance_notice')}</p></div>}
        {section==='video-marketing'&&<section className="panel"><h2>{translate('portal','ops_promote_video')}</h2><p>{translate('portal','ops_promote_video_body')} <Link className="text-link" href="/catalog">{translate('portal','product_management')}</Link>. {translate('portal','ops_promote_video_hide')}</p>{videoProducts.length===0&&<p className="muted">{translate('portal','ops_no_product_videos')}</p>}</section>}
        {section==='access-management'&&<StaffAccessEditor modules={permissionModules} staff={staffAccess} roles={customRoles} presets={rolePresets}/>}
        {section==='dues-adjustments'&&<section className="panel"><h2>{translate('portal','ops_credit_notes')}</h2><p>{translate('portal','ops_credit_notes_body')}</p><form method="get" className="action-form"><label>{translate('portal','ops_find_order')}<input type="search" name="q" placeholder={translate('portal','ops_order_number')}/></label><button className="button">{translate('portal','ops_search_orders')}</button></form>{adjustableOrders.length===0?<Empty title={translate('portal','ops_no_adjustable_orders')} body={translate('portal','ops_no_adjustable_orders_body')}/>:<><label>{translate('portal','orders')}<select value={selectedOrder} onChange={e=>setAdjustmentOrder(e.target.value)}>{adjustableOrders.map((order:Row)=><option key={order.id} value={order.id}>{order.name}</option>)}</select></label><p className="muted">{translate('portal','ops_credit_notes_review')}</p></>}</section>}
        {section==='finance-settings'&&withdrawalSetting&&<section className="panel"><h2>{translate('portal','ops_withdrawal_policy')}</h2><p>{translate('portal','ops_withdrawal_policy_body',{amount:money(withdrawalSetting.amount)})}</p></section>}
        {section==='policy-schedules'&&<p>{translate('portal','ops_policy_schedules_hint')}</p>}
        {section==='payroll'&&<p>{translate('portal','ops_payroll_hint')}</p>}
        {section==='reserve-payouts'&&<p>{translate('portal','ops_reserve_payouts_hint')}</p>}
        {Object.keys(summary).length>0&&<section className="panel"><dl className="profile-grid">{Object.entries(summary).map(([key,value])=><div key={key}><dt>{metricLabel(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {section==='field-sales'&&<section className="panel"><h2>{translate('portal','ops_my_territories')}</h2>{regions.length?regions.map((r:Row)=><p key={r.id}>{[r.division,r.district,r.upazila,r.union].filter(Boolean).join(' / ')}</p>):<Empty title={translate('portal','ops_no_territory')} body={translate('portal','ops_no_territory_body')}/>}{capabilities.includes('accounts')&&<p className="muted" style={{marginTop:12}}>{translate('portal','ops_accounts_partnerships_hint')} <Link className="text-link" href="/partnerships">{translate('portal','partnerships')}</Link>.</p>}</section>}
        {['field-sales','policy-schedules','reserve-payouts','commission-holds'].includes(section)&&<form method="get" className="action-form"><label>{translate('portal','ops_find_partner')}<input name="q" type="search" defaultValue={partnerSearch}/></label><button className="button">{translate('portal','ops_search_partners')}</button></form>}
        {readiness&&<section className="panel"><h2>{translate('portal','ops_ledger_reconciliation')}</h2><p>{translate('portal','ops_startup_mode')}: <strong>{startupMode?.label??translate('portal','ops_startup_not_selected')}</strong>. {startupMode?.description} {translate('portal','ops_startup_configure')} <Link className="text-link" href="/operations/service-integrations">{translate('portal','service_integrations')}</Link> {translate('portal','ops_or')} <Link className="text-link" href="/operations/data-imports">{translate('portal','data_imports')}</Link>.</p><p>{translate('portal','ops_startup_paths')}</p><Table rows={Object.entries(readiness.checks).map(([name,passed])=>({name:metricLabel(name),status:passed?'passed':'needs_review'}))} columns={[{key:'name',title:translate('portal','ops_check')},{key:'status',title:translate('portal','ops_result'),render:r=><Badge status={r.status}/>}]} /><p>{translate('portal','ops_opening_evidence')}: {readiness.opening_verified?translate('portal','ops_verified'):translate('portal','ops_awaiting_approval')}. {translate('portal','ops_internal_reconciliation')}: {readiness.internally_reconciled?translate('portal','ops_passed'):translate('portal','ops_requires_review')}.</p><dl className="profile-grid">{Object.entries(readiness.totals).map(([key,value])=><div key={key}><dt>{metricLabel(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {deploymentStatus&&<section className="panel"><h2>{translate('portal','ops_deployment_readiness')}</h2><Table rows={Object.entries(deploymentStatus.checks).map(([name,passed])=>({name:metricLabel(name),status:passed?'passed':'needs_review'}))} columns={[{key:'name',title:translate('portal','ops_service_config')},{key:'status',title:translate('portal','ops_result'),render:r=><Badge status={r.status}/>}]} />{Array.isArray(deploymentStatus.warnings)&&deploymentStatus.warnings.length>0?<div className="notice" style={{marginTop:12}}><div><strong>{translate('portal','ops_go_live_warnings')}</strong><ul style={{margin:'8px 0 0',paddingLeft:18}}>{deploymentStatus.warnings.map((warning:string)=><li key={warning}>{translate('portal',`ops_warn_${warning}`)}</li>)}</ul></div></div>:<p className="muted" style={{marginTop:12}}>{translate('portal','ops_no_go_live_warnings')}</p>}<p>{translate('portal','ops_deployment_hint')}</p></section>}
        {section==='financial-reports'&&<Reports salesOnly={!capabilities.includes('finance')&&!capabilities.includes('reports')}/>}
        {section==='payment-accounts'&&<section className="panel"><h2>{translate('portal','ops_account_registry')}</h2><Table rows={paymentAccounts} columns={[{key:'label',title:translate('portal','ops_account')},{key:'channel',title:translate('portal','ops_method')},{key:'holder',title:translate('portal','ops_holder')},{key:'account_number',title:translate('portal','ops_number')},{key:'is_active',title:translate('portal','ops_status'),render:r=><Badge status={r.is_active?'active':'inactive'}/>},{key:'edit',title:translate('portal','ops_manage'),render:r=><div style={{display:'flex',gap:8}}><button className="button small" onClick={()=>setAccount(r)}>{translate('portal','ops_edit_version',{version:String(r.version)})}</button><Link className="button small" href={'/operations/payment-accounts?account_history='+r.id}>{translate('portal','ops_version_history')}</Link></div>}]}/>{account&&<button className="button" onClick={()=>setAccount(null)}>{translate('portal','ops_create_another_account')}</button>}</section>}
        {accountHistory.length>0&&<section className="panel"><h2>{translate('portal','ops_payment_account_history')}</h2><Table rows={accountHistory} columns={[{key:'version',title:translate('portal','ops_version')},{key:'label',title:translate('portal','ops_account')},{key:'account_number',title:translate('portal','ops_number_at_version')},{key:'recorded_at',title:translate('portal','ops_recorded_utc')},{key:'reason',title:translate('portal','ops_reason')}]}/></section>}{forms.filter(a=>actionAllowed(a.action,staffActions,a.defaults?._method??'post')).map(a=><section className="panel narrow-panel" key={a.title+(account?.version??'')}><h2>{a.title}</h2><OperationForm {...a} onDone={()=>{if(section==='payment-accounts')setAccount(null);}}/></section>)}
        {tables.map((table:Row)=>{const rows=table.page.data as Row[];const tableKey=String(table.key??table.title??'');const tableTitle=translate('portal',`ops_tbl_${tableKey}`);const keys=[...new Set(rows.flatMap(r=>Object.keys(r)))].filter(k=>!['staff_id','submitted_by','created_by','approved_by','verified_by','user_id','has_receipt','territory_key','territories','created_at','updated_at'].includes(k));return <section className="panel" key={tableKey}><h2>{tableTitle===`ops_tbl_${tableKey}`?label(tableKey):tableTitle}</h2><Table rows={rows} columns={[...keys.map(key=>({key,title:label(key),render:(row:Row)=>key==='status'?<Badge status={row[key]}/>:['rules','metadata','data','snapshot'].includes(key)?<details><summary>{translate('portal','ops_view_details')}</summary><div style={{minWidth:240,maxWidth:420,overflowWrap:'anywhere'}}><Detail value={parseJson(row[key], translate('portal','ops_invalid_json'))}/></div></details>:String(row[key]??'—')})),{key:'actions',title:translate('portal','ops_actions'),render:r=>rowActions(r,tableKey)}]}/><Pagination page={table.page}/></section>;})}
        {selected&&<Modal title={selected.title} onClose={()=>setSelected(null)}><OperationForm {...selected} onDone={()=>setSelected(null)}/></Modal>}
    </Shell>;
}
