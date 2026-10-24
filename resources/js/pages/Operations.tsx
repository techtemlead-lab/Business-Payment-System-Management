import React, {useState} from 'react';
import {Head, Link, useForm, usePage} from '@inertiajs/react';
import Shell from '../components/Shell';
import StaffAccessEditor from '../components/StaffAccessEditor';
import {actionAllowed} from '../components/action-permissions';
import {Badge, Empty, Modal, Pagination, Row, Table, label, money} from '../components/UI';
import {useT} from '../shared/i18n/t';

type Option = {value:string;label:string};
type Field = {name:string;label:string;type?:string;options?:Option[];required?:boolean;value?:string};
type Action = {title:string;action:string;fields:Field[];defaults?:Row;policy?:boolean;policyRules?:Row;fundingSources?:string[]};
const root = '/commands/operations';
const reason:Field = {name:'reason',label:'Reason and approval details',type:'textarea',required:true};
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
function parseJson(value: unknown): unknown {
    if (typeof value !== 'string') {
        return value;
    }
    try {
        return JSON.parse(value);
    } catch {
        return {error: 'Invalid stored JSON'};
    }
}
const field = (name:string,title:string,type='text',required=true):Field => ({name,label:title,type,required});
const options = (name:string,title:string,values:string[],required=true):Field => ({name,label:title,required,options:values.map(value=>({value,label:value==='1'?'Yes':value==='0'?'No':label(value)}))});
function OperationForm({title,action,fields,defaults={},policy,policyRules={},fundingSources=[],onDone}:Action & {onDone?:()=>void}) {
    const translate=useT();
    const form = useForm<Row>({...Object.fromEntries(fields.map(f=>[f.name,f.type==='file'?null:(f.value??'')])),...defaults});
    const [fundCount,setFundCount]=useState(1);
    const [itemCount,setItemCount] = useState(1);
    const itemFields = fields.filter(f=>f.name.startsWith('items.0.'));
    const recipientKeys = ['own_dealer_cashback','direct_referral','d2d_gen2','d2d_gen3','area_division','area_district','area_upazila','area_union'];
    const parameters = policy && form.data.scope!=='recipient' ? Object.fromEntries(Object.entries(policyRules[form.data.rule_key]?.parameters??{}).filter(([key])=>key!=='dynamic_compression')) : {};
    const parameterFields:Field[] = Object.entries(parameters).map(([key,value])=>typeof value==='boolean'
        ? {...options('parameters.'+key,label(key),['1','0']),value:value?'1':'0'}
        : {...field('parameters.'+key,label(key.replace(/_pct$/,' (%)')),'number'),value:String(value)});
    const fundingFields:Field[]=policy&&form.data.scope==='recipient'?[{name:'funding_mode',label:'Source for any increased rate',required:true,options:[{value:'company',label:'A · Company share'},{value:'allocations',label:'B · Other commissions / reserves'}]},...(form.data.funding_mode==='allocations'?Array.from({length:fundCount},(_,index)=>[
        {name:`funding_sources.${index}.category`,label:`Funding source ${index+1}`,required:true,options:fundingSources.filter(category=>category!==form.data.rule_key&&!Array.from({length:fundCount},(_,other)=>other===index?'':form.data[`funding_sources.${other}.category`]).includes(category)).map(value=>({value,label:label(value)}))},field(`funding_sources.${index}.percentage`,`Rate deducted from source ${index+1} (%)`,'number')
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
            const choices = policy&&f.name==='rule_key'&&form.data.scope==='recipient' ? recipientKeys.map(value=>({value,label:label(value)})) : f.options;
            const value=form.data[f.name]??f.value??'';
            return <label key={f.name}>{f.label}{choices?<select required={f.required} value={String(value)} onChange={e=>change(f.name,e.target.value)}><option value="">Select…</option>{choices.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:f.type==='textarea'?<textarea rows={3} required={f.required} value={value} onChange={e=>change(f.name,e.target.value)}/>:f.type==='file'?<input required={f.required} type="file" accept={f.name==='evidence'?'application/pdf,image/jpeg,image/png,.xlsx':'application/pdf,image/jpeg,image/png'} onChange={e=>change(f.name,e.target.files?.[0]??null)}/>:<input required={f.required} type={f.type??'text'} step={f.type==='number'?(f.name.endsWith('.quantity')?'1':'0.01'):undefined} value={value} onChange={e=>change(f.name,e.target.value)}/>} {form.errors[f.name]&&<span className="field-error">{String(form.errors[f.name])}</span>}</label>;
        })}
        {policy&&form.data.scope==='recipient'&&form.data.funding_mode==='allocations'&&<div style={{display:'flex',gap:8}}><button className="button" type="button" disabled={fundCount>=fundingSources.length-1} onClick={()=>setFundCount(fundCount+1)}>{translate('portal','ops_add_funding_source')}</button>{fundCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`funding_sources.${fundCount-1}.`))));setFundCount(fundCount-1);}}>{translate('portal','ops_remove_last_source')}</button>}</div>}
        {itemFields.length>0&&<div style={{display:'flex',gap:8}}><button className="button" type="button" disabled={itemCount>=100} onClick={()=>{for(const f of itemFields)form.setData(f.name.replace('items.0.',`items.${itemCount}.`),f.name.endsWith('.quantity')?'1':'');setItemCount(itemCount+1);}}>{translate('portal','ops_add_product')}</button>{itemCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`items.${itemCount-1}.`))));setItemCount(itemCount-1);}}>{translate('portal','ops_remove_last_product')}</button>}</div>}
        {Object.keys(form.errors).length>0&&<div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
        {form.progress&&<progress max={100} value={form.progress.percentage} aria-label={translate('portal','ops_upload_progress')}/>}
        <button className="button dark" disabled={form.processing}>{form.processing?translate('portal','ops_recording'):title}</button>
    </form>;
}

function Detail({value}:{value:any}):React.ReactNode {
    if(value===null||value===undefined)return '—';
    if(typeof value!=='object')return typeof value==='boolean'?(value?'Yes':'No'):String(value);
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
        <label>{translate('portal','date_from')}<input name="from" required type="date" value={from} onChange={e=>{setFrom(e.target.value);setPeriod('custom');}}/></label><label>{translate('portal','ops_through')}<input name="to" required type="date" min={from} value={to} onChange={e=>{setTo(e.target.value);setPeriod('custom');}}/></label>
        <label>{translate('portal','ops_format')}<select name="format"><option value="xlsx">{translate('portal','ops_format_xlsx')}</option><option value="pdf">{translate('portal','ops_format_pdf')}</option></select></label><button className="button dark">{translate('portal','ops_download_report')}</button>
    </form></section>;
}

export default function Operations({section,tables,paymentAccounts=[],partners=[],partnerSearch='',staff=[],summary={},policy,readiness,deploymentStatus,startupMode,regions=[],products=[],accountHistory=[],adjustableOrders=[],withdrawalSetting,permissionModules=[],staffAccess=[],customRoles=[],rolePresets=[],videoSetting={},videoProducts=[]}:Row) {
    const translate=useT();
    const sectionTitle=translate('portal', sectionTitleKeys[section] ?? section);
    const {auth,financeActive,staffActions}=usePage<any>().props;
    const capabilities:string[]=auth.capabilities;
    const [selected,setSelected]=useState<Action|null>(null);
    const [account,setAccount]=useState<Row|null>(null);
    const [adjustmentOrder,setAdjustmentOrder]=useState('');
    const selectedOrder=adjustmentOrder||String(adjustableOrders[0]?.id??'');
    const choose=(rows:Row[],name:string,title:string,required=true):Field=>({name,label:title,required,options:rows.map(r=>({value:String(r.id),label:r.dealer_code?`${r.name} · ${r.dealer_code}`:(r.name??`${r.label} · ${r.channel} · ${r.account_number}`)}))});
    const staffField=choose(staff,'staff_id','Staff member');
    const partnerField=choose(partners,'user_id','Partner (use search to locate)');
    const review=(title:string,action:string,decisions:string[]):Action=>({title,action,fields:[options('decision','Decision',decisions),reason]});
    const payment=(title:string,action:string):Action=>({title,action,fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.pays_out),'payment_account_id','Company payout account'),field('payment_reference','Actual payment reference'),field('paid_at','Actual payment time (Dhaka)','datetime-local'),field('receipt','Payment receipt (PDF or image, up to 5 MB)','file'),reason]});
    const forms:Action[]=[];
    if(section==='finance-settings'&&withdrawalSetting)forms.push({title:translate('portal','ops_save_withdrawal_minimum'),action:root+'/settings/withdrawal-minimum',fields:[field('amount','Minimum new withdrawal (BDT)','number'),reason],defaults:{amount:withdrawalSetting.amount,version:withdrawalSetting.version}});
    if(section==='video-marketing')forms.push({title:translate('portal','ops_save_homepage_video'),action:root+'/marketing/homepage-video',fields:[options('enabled','Show video on homepage?',['1','0']),choose(videoProducts,'product_id','Published product video',false),field('title','Section title'),field('description','Introduction','textarea',false),reason],defaults:{...videoSetting,enabled:videoSetting.enabled?'1':'0',title:videoSetting.title??'Discover our products'}});
    if(section==='dues-adjustments'&&selectedOrder)forms.push({title:translate('portal','ops_propose_due_adjustment'),action:`${root}/orders/${selectedOrder}/due-adjustments`,fields:[options('kind','Adjustment type',['credit','debit_correction']),field('amount','Amount (BDT)','number'),field('reference','Unique credit-note / correction reference'),reason],defaults:{request_key:crypto.randomUUID()}});
    if(section==='staff-operations') {
        forms.push({title:translate('portal','ops_assign_territory'),action:root+'/staff/territories',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id','Sales representative'),options('level','Territory level',['division','district','upazila','union']),field('division','Division'),field('district','District','text',false),field('upazila','Upazila','text',false),field('union','Union','text',false)]});
        forms.push({title:translate('portal','ops_set_monthly_target'),action:root+'/staff/targets',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id','Sales representative'),field('period_month','Target month','month'),field('target','Net posted sales target (BDT)','number'),field('bonus','Bonus on achievement (BDT)','number'),field('version','Current version when revising','number',false)]});
    }
    if(section==='field-sales') {
        if(capabilities.includes('sr_orders'))forms.push({title:translate('portal','ops_submit_partner_order'),action:root+'/staff/orders',fields:[partnerField,choose(products,'items.0.product_id','Product'),field('items.0.quantity','Quantity','number'),field('note','Order note','textarea',false)],defaults:{idempotency_key:crypto.randomUUID()}});
        if(capabilities.includes('sr_onboarding'))forms.push({title:translate('portal','ops_submit_dealer_application'),action:root+'/staff/applications',fields:[field('name','Full name'),field('email','Email','email'),field('phone','Phone'),options('role','Account type',['dealer','sub_dealer']),field('division','Division'),field('district','District'),field('upazila','Upazila','text',false),field('union','Union','text',false),field('area_code','Area code','text',false),choose(partners.filter((p:Row)=>p.role==='dealer'),'sponsor_id','Sponsor (optional)',false)]});
    }
    if(section==='payroll') {
        if(capabilities.includes('finance'))forms.push({title:translate('portal','ops_create_salary_contract'),action:root+'/payroll/contracts',fields:[staffField,field('effective_month','Effective month','month'),field('base_salary','Base salary (BDT)','number'),field('allowance','Allowance (BDT)','number'),field('deduction','Deduction (BDT)','number'),reason]});
        forms.push({title:translate('portal','ops_prepare_payroll'),action:root+'/payroll/prepare',fields:[field('period_month','Closed salary month','month')]});
    }
    if(section==='policy-schedules')forms.push({title:translate('portal','ops_propose_commission_schedule'),action:root+'/policy-schedules',policy:true,policyRules:policy?.rules??{},fundingSources:policy?.funding_sources??[],fields:[options('scope','Schedule applies to',['global','buyer','recipient']),{...partnerField,required:false,label:'Partner (required for buyer / recipient customization)'},{name:'rule_key',label:'Commission rule',required:true,options:Object.entries(policy?.rules??{}).map(([key,value]:[string,any])=>({value:key,label:value.name}))},field('percentage','Rate (%)','number'),field('effective_at','Starts (Dhaka)','datetime-local'),field('expires_at','Expires (optional, Dhaka)','datetime-local',false),reason]});
    if(section==='reserve-payouts')forms.push({title:translate('portal','ops_request_reserve'),action:root+'/reserves',fields:[options('program','Reserve program',['tour','additional_rank','bike','car','marketing']),{...partnerField,required:false},field('payee_name','Company expense payee','text',false),field('amount','Approved amount requested (BDT)','number'),reason],defaults:{request_key:crypto.randomUUID()}});
    if(section==='financial-readiness')forms.push({title:translate('portal','ops_submit_opening_balances'),action:root+'/opening-balances',fields:[field('wallet_available','Verified available wallet total (BDT)','number'),field('wallet_held','Verified held wallet total (BDT)','number'),field('order_paid','Verified collected order total (BDT)','number'),field('withdrawal_paid','Verified paid withdrawal total (BDT)','number'),field('source_reference','Source / statement reference'),field('evidence','Independent statement (PDF, image or Excel, up to 10 MB)','file')]});
    const accountFields:Field[]=[field('label','Account label'),options('channel','Gateway / payment method',['Bank','bKash','Nagad','Rocket']),field('holder','Account holder'),field('account_number','Account number'),field('details','Payment instructions','textarea',false),options('collects_payments','Accept collections?',['1','0']),options('pays_out','Allow payouts?',['1','0']),options('is_active','Account active?',['1','0']),reason];
    if(section==='payment-accounts')forms.push({title:account?translate('portal','ops_save_account_version'):translate('portal','ops_add_payment_account'),action:root+'/payment-accounts'+(account?'/'+account.id:''),fields:accountFields,defaults:account?{...account,collects_payments:account.collects_payments?'1':'0',pays_out:account.pays_out?'1':'0',is_active:account.is_active?'1':'0',_method:'patch'}:{collects_payments:'1',pays_out:'0',is_active:'1'}});
    const rowActions=(row:Row,title:string)=> {
        const actions:Action[]=[];
        if(title==='Due adjustment requests'&&row.status==='requested'&&financeActive&&row.created_by!==auth.user.id)actions.push(review(translate('portal','ops_review_due_adjustment'),`${root}/due-adjustments/${row.id}/review`,['approved','rejected']));
        if(title==='Territories'&&row.is_active)actions.push({title:translate('portal','ops_remove_territory'),action:`${root}/staff/territories/${row.id}/remove`,fields:[reason]});
        if(title==='Dealer applications'&&row.status==='pending'&&capabilities.includes('accounts')&&row.submitted_by!==auth.user.id)actions.push(review(translate('portal','ops_review_application'),`${root}/staff/applications/${row.id}/review`,['approved','rejected']));
        if(title==='Salary register'&&section==='payroll'&&financeActive&&capabilities.includes('finance')) {
            if(row.status==='draft'&&![row.staff_id,row.prepared_by].includes(auth.user.id))actions.push(review(translate('portal','ops_review_payroll'),`${root}/payroll/${row.id}/review`,['approved','rejected']));
            if(row.status==='approved'&&![row.staff_id,row.approved_by].includes(auth.user.id))actions.push(payment(translate('portal','ops_record_salary_payment'),`${root}/payroll/${row.id}/pay`));
        }
        if(title==='Policy schedules')actions.push(review(translate('portal','ops_review_schedule'),`${root}/policy-schedules/${row.id}/review`,[...(row.status==='draft'&&row.created_by!==auth.user.id?['approved']:[]),...(['draft','approved'].includes(row.status)?['cancelled']:[])]));
        if(title==='Reward and expense requests'&&financeActive) {
            if(row.status==='requested'&&![row.created_by,row.user_id].includes(auth.user.id))actions.push(review(translate('portal','ops_review_reserve_request'),`${root}/reserves/${row.id}/review`,['approved','rejected']));
            if(row.program==='marketing'&&row.status==='approved') {
                if(row.approved_by!==auth.user.id)actions.push(payment(translate('portal','ops_record_expense_payment'),`${root}/reserves/${row.id}/pay`));
                actions.push(review(translate('portal','ops_cancel_unpaid_expense'),`${root}/reserves/${row.id}/review`,['cancelled']));
            }
        }
        if(title==='Held allocations'&&financeActive&&row.partner_status!=='active'&&['direct_referral','d2d_gen2','d2d_gen3'].includes(row.category)&&(staffActions?.finance?.edit??true))actions.push({title:translate('portal','ops_select_replacement_release'),action:`${root}/holds/${row.id}/reassign`,fields:[choose(partners,'user_id','Replacement partner'),reason],defaults:{request_key:crypto.randomUUID()}});
        if(title==='Opening commission holds'&&financeActive)actions.push({title:translate('portal','ops_review_and_release_opening_hold'),action:`${root}/imports/opening-holds/${row.id}/release`,fields:[reason]});
        if(title==='Held allocations'&&financeActive)actions.push({title:translate('portal','ops_review_and_release_hold'),action:`${root}/holds/${row.id}/release`,fields:[reason]});
        if(title==='Opening balance reviews'&&!row.verified_at&&row.created_by!==auth.user.id)actions.push({title:translate('portal','ops_verify_opening_evidence'),action:`${root}/opening-balances/${row.id}/verify`,fields:[]});
        if(title==='Company reserve clawbacks'&&Number(row.amount)>Number(row.recovered)&&financeActive)actions.push({...payment(translate('portal','ops_record_recovered_reserve'),`${root}/reserves/clawbacks/${row.id}/recover`),fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.collects_payments),'payment_account_id','Company collection account'),field('amount','Recovered amount (BDT)','number'),field('payment_reference','Collection reference'),field('paid_at','Collection time (Dhaka)','datetime-local'),field('receipt','Recovery receipt','file'),reason]});
        const receiptKind=title==='Salary register'?'payroll':title==='Reward and expense requests'?'reserve':title==='Clawback recoveries'?'recovery':title==='Opening balance reviews'?'opening':null;
        return <div style={{display:'flex',gap:8,flexWrap:'wrap'}}>{title==='Territory orders and dues'&&capabilities.includes('sr_orders')&&<Link className="button small" href={`${root}/staff/orders/${row.id}`}>Order details</Link>}{actions.filter(a=>actionAllowed(a.action,staffActions)).filter(a=>!a.fields.find(f=>f.name==='decision')?.options?.length&&a.fields.some(f=>f.name==='decision')?false:true).map(a=><button key={a.title} className="button small" onClick={()=>setSelected(a)}>{a.title}</button>)}{receiptKind&&(row.has_receipt||receiptKind==='opening')&&<a className="button small" href={`${root}/receipts/${receiptKind}/${row.id}`}>Private evidence</a>}</div>;
    };
    return <Shell section={section}><Head title={sectionTitle}/><p className="muted">Madina Natural Health Care · BDT · Asia/Dhaka</p>
        {!financeActive&&capabilities.includes('finance')&&<div className="notice"><p>{translate('portal','ops_preview_finance_notice')}</p></div>}
        {section==='video-marketing'&&<section className="panel"><h2>{translate('portal','ops_promote_video')}</h2><p>{translate('portal','ops_promote_video_body')} <Link className="text-link" href="/catalog">{translate('portal','product_management')}</Link>. {translate('portal','ops_promote_video_hide')}</p>{videoProducts.length===0&&<p className="muted">{translate('portal','ops_no_product_videos')}</p>}</section>}
        {section==='access-management'&&<StaffAccessEditor modules={permissionModules} staff={staffAccess} roles={customRoles} presets={rolePresets}/>}
        {section==='dues-adjustments'&&<section className="panel"><h2>{translate('portal','ops_credit_notes')}</h2><p>{translate('portal','ops_credit_notes_body')}</p><form method="get" className="action-form"><label>{translate('portal','ops_find_order')}<input type="search" name="q" placeholder={translate('portal','ops_order_number')}/></label><button className="button">{translate('portal','ops_search_orders')}</button></form>{adjustableOrders.length===0?<Empty title={translate('portal','ops_no_adjustable_orders')} body={translate('portal','ops_no_adjustable_orders_body')}/>:<><label>{translate('portal','orders')}<select value={selectedOrder} onChange={e=>setAdjustmentOrder(e.target.value)}>{adjustableOrders.map((order:Row)=><option key={order.id} value={order.id}>{order.name}</option>)}</select></label><p className="muted">{translate('portal','ops_credit_notes_review')}</p></>}</section>}
        {section==='finance-settings'&&withdrawalSetting&&<section className="panel"><h2>{translate('portal','ops_withdrawal_policy')}</h2><p>{translate('portal','ops_withdrawal_policy_body',{amount:money(withdrawalSetting.amount)})}</p></section>}
        {section==='policy-schedules'&&<p>{translate('portal','ops_policy_schedules_hint')}</p>}
        {section==='payroll'&&<p>{translate('portal','ops_payroll_hint')}</p>}
        {section==='reserve-payouts'&&<p>{translate('portal','ops_reserve_payouts_hint')}</p>}
        {Object.keys(summary).length>0&&<section className="panel"><dl className="profile-grid">{Object.entries(summary).map(([key,value])=><div key={key}><dt>{label(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {section==='field-sales'&&<section className="panel"><h2>{translate('portal','ops_my_territories')}</h2>{regions.length?regions.map((r:Row)=><p key={r.id}>{[r.division,r.district,r.upazila,r.union].filter(Boolean).join(' / ')}</p>):<Empty title={translate('portal','ops_no_territory')} body={translate('portal','ops_no_territory_body')}/>}{capabilities.includes('accounts')&&<p className="muted" style={{marginTop:12}}>{translate('portal','ops_accounts_partnerships_hint')} <Link className="text-link" href="/partnerships">{translate('portal','partnerships')}</Link>.</p>}</section>}
        {['field-sales','policy-schedules','reserve-payouts','commission-holds'].includes(section)&&<form method="get" className="action-form"><label>{translate('portal','ops_find_partner')}<input name="q" type="search" defaultValue={partnerSearch}/></label><button className="button">{translate('portal','ops_search_partners')}</button></form>}
        {readiness&&<section className="panel"><h2>{translate('portal','ops_ledger_reconciliation')}</h2><p>{translate('portal','ops_startup_mode')}: <strong>{startupMode?.label??translate('portal','ops_startup_not_selected')}</strong>. {startupMode?.description} {translate('portal','ops_startup_configure')} <Link className="text-link" href="/operations/service-integrations">{translate('portal','service_integrations')}</Link> {translate('portal','ops_or')} <Link className="text-link" href="/operations/data-imports">{translate('portal','data_imports')}</Link>.</p><p>{translate('portal','ops_startup_paths')}</p><Table rows={Object.entries(readiness.checks).map(([name,passed])=>({name:label(name),status:passed?'passed':'needs_review'}))} columns={[{key:'name',title:translate('portal','ops_check')},{key:'status',title:translate('portal','ops_result'),render:r=><Badge status={r.status}/>}]} /><p>{translate('portal','ops_opening_evidence')}: {readiness.opening_verified?translate('portal','ops_verified'):translate('portal','ops_awaiting_approval')}. {translate('portal','ops_internal_reconciliation')}: {readiness.internally_reconciled?translate('portal','ops_passed'):translate('portal','ops_requires_review')}.</p><dl className="profile-grid">{Object.entries(readiness.totals).map(([key,value])=><div key={key}><dt>{label(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {deploymentStatus&&<section className="panel"><h2>{translate('portal','ops_deployment_readiness')}</h2><Table rows={Object.entries(deploymentStatus.checks).map(([name,passed])=>({name:label(name),status:passed?'passed':'needs_review'}))} columns={[{key:'name',title:translate('portal','ops_service_config')},{key:'status',title:translate('portal','ops_result'),render:r=><Badge status={r.status}/>}]} /><p>{translate('portal','ops_deployment_hint')}</p></section>}
        {section==='financial-reports'&&<Reports salesOnly={!capabilities.includes('finance')&&!capabilities.includes('reports')}/>}
        {section==='payment-accounts'&&<section className="panel"><h2>{translate('portal','ops_account_registry')}</h2><Table rows={paymentAccounts} columns={[{key:'label',title:translate('portal','ops_account')},{key:'channel',title:translate('portal','ops_method')},{key:'holder',title:translate('portal','ops_holder')},{key:'account_number',title:translate('portal','ops_number')},{key:'is_active',title:translate('portal','ops_status'),render:r=><Badge status={r.is_active?'active':'inactive'}/>},{key:'edit',title:translate('portal','ops_manage'),render:r=><div style={{display:'flex',gap:8}}><button className="button small" onClick={()=>setAccount(r)}>{translate('portal','ops_edit_version',{version:String(r.version)})}</button><Link className="button small" href={'/operations/payment-accounts?account_history='+r.id}>{translate('portal','ops_version_history')}</Link></div>}]}/>{account&&<button className="button" onClick={()=>setAccount(null)}>{translate('portal','ops_create_another_account')}</button>}</section>}
        {accountHistory.length>0&&<section className="panel"><h2>{translate('portal','ops_payment_account_history')}</h2><Table rows={accountHistory} columns={[{key:'version',title:translate('portal','ops_version')},{key:'label',title:translate('portal','ops_account')},{key:'account_number',title:translate('portal','ops_number_at_version')},{key:'recorded_at',title:translate('portal','ops_recorded_utc')},{key:'reason',title:translate('portal','ops_reason')}]}/></section>}{forms.filter(a=>actionAllowed(a.action,staffActions,a.defaults?._method??'post')).map(a=><section className="panel narrow-panel" key={a.title+(account?.version??'')}><h2>{a.title}</h2><OperationForm {...a} onDone={()=>{if(section==='payment-accounts')setAccount(null);}}/></section>)}
        {tables.map((table:Row)=>{const rows=table.page.data as Row[];const keys=[...new Set(rows.flatMap(r=>Object.keys(r)))].filter(k=>!['staff_id','submitted_by','created_by','approved_by','verified_by','user_id','has_receipt','territory_key','territories','created_at','updated_at'].includes(k));return <section className="panel" key={table.title}><h2>{table.title}</h2><Table rows={rows} columns={[...keys.map(key=>({key,title:label(key),render:(row:Row)=>key==='status'?<Badge status={row[key]}/>:['rules','metadata','data','snapshot'].includes(key)?<details><summary>{translate('portal','ops_view_details')}</summary><div style={{minWidth:240,maxWidth:420,overflowWrap:'anywhere'}}><Detail value={parseJson(row[key])}/></div></details>:String(row[key]??'—')})),{key:'actions',title:translate('portal','ops_actions'),render:r=>rowActions(r,table.title)}]}/><Pagination page={table.page}/></section>;})}
        {selected&&<Modal title={selected.title} onClose={()=>setSelected(null)}><OperationForm {...selected} onDone={()=>setSelected(null)}/></Modal>}
    </Shell>;
}
