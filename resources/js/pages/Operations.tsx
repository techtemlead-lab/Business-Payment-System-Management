import React, {useState} from 'react';
import {Head, Link, useForm, usePage} from '@inertiajs/react';
import Shell from '../components/Shell';
import {Badge, Empty, Modal, Pagination, Row, Table, label, money} from '../components/UI';

type Option = {value:string;label:string};
type Field = {name:string;label:string;type?:string;options?:Option[];required?:boolean;value?:string};
type Action = {title:string;action:string;fields:Field[];defaults?:Row;policy?:boolean;policyRules?:Row};
const root = '/commands/operations';
const reason:Field = {name:'reason',label:'Reason and approval details',type:'textarea',required:true};
const field = (name:string,title:string,type='text',required=true):Field => ({name,label:title,type,required});
const options = (name:string,title:string,values:string[],required=true):Field => ({name,label:title,required,options:values.map(value=>({value,label:value==='1'?'Yes':value==='0'?'No':label(value)}))});
const titles:Record<string,string> = {'field-sales':'Field sales workspace','staff-operations':'Territories & targets',payroll:'Staff payroll','my-payroll':'My payroll','payment-accounts':'Payment accounts','policy-schedules':'Commission schedules','reserve-payouts':'Reserve payouts','commission-holds':'Commission holds','financial-readiness':'Opening balance review','financial-reports':'Financial reports'};

function OperationForm({title,action,fields,defaults={},policy,policyRules={},onDone}:Action & {onDone?:()=>void}) {
    const form = useForm<Row>({...Object.fromEntries(fields.map(f=>[f.name,f.type==='file'?null:(f.value??'')])),...defaults});
    const [itemCount,setItemCount] = useState(1);
    const itemFields = fields.filter(f=>f.name.startsWith('items.0.'));
    const recipientKeys = ['own_dealer_cashback','direct_referral','d2d_gen2','d2d_gen3','area_division','area_district','area_upazila','area_union'];
    const parameters = policy && form.data.scope!=='recipient' ? (policyRules[form.data.rule_key]?.parameters??{}) : {};
    const parameterFields:Field[] = Object.entries(parameters).map(([key,value])=>typeof value==='boolean'
        ? {...options('parameters.'+key,label(key),['1','0']),value:value?'1':'0'}
        : {...field('parameters.'+key,label(key.replace(/_pct$/,' (%)')),'number'),value:String(value)});
    const visibleFields = [...fields.flatMap(f=>f.name.startsWith('items.0.')
        ? Array.from({length:itemCount},(_,index)=>({...f,name:f.name.replace('items.0.',`items.${index}.`),label:`${f.label} ${index+1}`})) : [f]), ...parameterFields];
    const change = (name:string,value:any) => {
        if (policy && ['scope','rule_key'].includes(name)) {
            form.setData((current:Row)=>{
                const next=Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith('parameters.')));
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
            return <label key={f.name}>{f.label}{choices?<select required={f.required} value={String(value)} onChange={e=>change(f.name,e.target.value)}><option value="">Select…</option>{choices.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:f.type==='textarea'?<textarea rows={3} required={f.required} value={value} onChange={e=>change(f.name,e.target.value)}/>:f.type==='file'?<input required={f.required} type="file" accept="application/pdf,image/jpeg,image/png" onChange={e=>change(f.name,e.target.files?.[0]??null)}/>:<input required={f.required} type={f.type??'text'} step={f.type==='number'?(f.name.endsWith('.quantity')?'1':'0.01'):undefined} value={value} onChange={e=>change(f.name,e.target.value)}/>} {form.errors[f.name]&&<span className="field-error">{String(form.errors[f.name])}</span>}</label>;
        })}
        {itemFields.length>0&&<div style={{display:'flex',gap:8}}><button className="button" type="button" disabled={itemCount>=100} onClick={()=>{for(const f of itemFields)form.setData(f.name.replace('items.0.',`items.${itemCount}.`),f.name.endsWith('.quantity')?'1':'');setItemCount(itemCount+1);}}>Add product</button>{itemCount>1&&<button className="button" type="button" onClick={()=>{form.setData((current:Row)=>Object.fromEntries(Object.entries(current).filter(([key])=>!key.startsWith(`items.${itemCount-1}.`))));setItemCount(itemCount-1);}}>Remove last product</button>}</div>}
        {Object.keys(form.errors).length>0&&<div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
        {form.progress&&<progress max={100} value={form.progress.percentage} aria-label="Upload progress"/>}
        <button className="button dark" disabled={form.processing}>{form.processing?'Recording…':title}</button>
    </form>;
}

function Detail({value}:{value:any}):React.ReactNode {
    if(value===null||value===undefined)return '—';
    if(typeof value!=='object')return typeof value==='boolean'?(value?'Yes':'No'):String(value);
    return <dl style={{margin:0}}>{Object.entries(value).map(([key,item])=><div key={key} style={{margin:'8px 0'}}><dt style={{fontWeight:600}}>{label(key)}</dt><dd style={{margin:'3px 0 3px 10px'}}><Detail value={item}/></dd></div>)}</dl>;
}

function Reports({salesOnly=false}:{salesOnly?:boolean}) {
    const [period,setPeriod]=useState('month');
    const dhaka=new Date(new Date().toLocaleString('en-US',{timeZone:'Asia/Dhaka'}));
    const iso=(date:Date)=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    const [from,setFrom]=useState(iso(new Date(dhaka.getFullYear(),dhaka.getMonth(),1)));
    const [to,setTo]=useState(iso(dhaka));
    return <section className="panel"><h2>Download financial reports</h2><p>Choose a reporting period. Excel includes the complete dataset; PDF supports up to 5,000 rows. Amounts are in BDT. Report dates use Asia/Dhaka.</p><form className="action-form" action={root+'/reports'} method="get">
        <label>Report<select required name="type">{(salesOnly?['orders','dues','payroll']:['orders','products','dues','commissions','settlements','payroll','withdrawals','ledger','rewards','refunds']).map(t=><option key={t} value={t}>{t==='products'?'Delivered product sales':t==='refunds'?'Commission refund adjustments':label(t)}</option>)}</select></label>
        <label>Period<select value={period} onChange={e=>{const value=e.target.value;setPeriod(value);if(value!=='custom'){const start=new Date(dhaka);if(value==='week')start.setDate(start.getDate()-((start.getDay()+6)%7));if(value==='month')start.setDate(1);setFrom(iso(start));setTo(iso(dhaka));}}}><option value="day">Today</option><option value="week">This week</option><option value="month">This month</option><option value="custom">Custom range</option></select></label>
        <label>From<input name="from" required type="date" value={from} onChange={e=>{setFrom(e.target.value);setPeriod('custom');}}/></label><label>Through<input name="to" required type="date" min={from} value={to} onChange={e=>{setTo(e.target.value);setPeriod('custom');}}/></label>
        <label>Format<select name="format"><option value="xlsx">Excel workbook (.xlsx)</option><option value="pdf">PDF document (.pdf)</option></select></label><button className="button dark">Download report</button>
    </form></section>;
}

export default function Operations({section,tables,paymentAccounts=[],partners=[],staff=[],summary={},policy,readiness,regions=[],products=[],accountHistory=[]}:Row) {
    const {auth,financeActive}=usePage<any>().props;
    const capabilities:string[]=auth.capabilities;
    const [selected,setSelected]=useState<Action|null>(null);
    const [account,setAccount]=useState<Row|null>(null);
    const choose=(rows:Row[],name:string,title:string,required=true):Field=>({name,label:title,required,options:rows.map(r=>({value:String(r.id),label:r.name??`${r.label} · ${r.channel} · ${r.account_number}`}))});
    const staffField=choose(staff,'staff_id','Staff member');
    const partnerField=choose(partners,'user_id','Partner (use search to locate)');
    const review=(title:string,action:string,decisions:string[]):Action=>({title,action,fields:[options('decision','Decision',decisions),reason]});
    const payment=(title:string,action:string):Action=>({title,action,fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.pays_out),'payment_account_id','Company payout account'),field('payment_reference','Actual payment reference'),field('paid_at','Actual payment time (Dhaka)','datetime-local'),field('receipt','Payment receipt (PDF or image, up to 5 MB)','file'),reason]});
    const forms:Action[]=[];
    if(section==='staff-operations') {
        forms.push({title:'Assign territory',action:root+'/staff/territories',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id','Sales representative'),options('level','Territory level',['division','district','upazila','union']),field('division','Division'),field('district','District','text',false),field('upazila','Upazila','text',false),field('union','Union','text',false)]});
        forms.push({title:'Set monthly target',action:root+'/staff/targets',fields:[choose(staff.filter((s:Row)=>s.field_sales),'staff_id','Sales representative'),field('period_month','Target month','month'),field('target','Net posted sales target (BDT)','number'),field('bonus','Bonus on achievement (BDT)','number'),field('version','Current version when revising','number',false)]});
    }
    if(section==='field-sales') {
        if(capabilities.includes('sr_orders'))forms.push({title:'Submit partner order',action:root+'/staff/orders',fields:[partnerField,choose(products,'items.0.product_id','Product'),field('items.0.quantity','Quantity','number'),field('note','Order note','textarea',false)],defaults:{idempotency_key:crypto.randomUUID()}});
        if(capabilities.includes('sr_onboarding'))forms.push({title:'Submit dealer application',action:root+'/staff/applications',fields:[field('name','Full name'),field('email','Email','email'),field('phone','Phone'),options('role','Account type',['dealer','sub_dealer']),field('division','Division'),field('district','District'),field('upazila','Upazila','text',false),field('union','Union','text',false),field('area_code','Area code','text',false),choose(partners.filter((p:Row)=>p.role==='dealer'),'sponsor_id','Sponsor (optional)',false)]});
    }
    if(section==='payroll') {
        if(capabilities.includes('finance'))forms.push({title:'Create effective salary contract',action:root+'/payroll/contracts',fields:[staffField,field('effective_month','Effective month','month'),field('base_salary','Base salary (BDT)','number'),field('allowance','Allowance (BDT)','number'),field('deduction','Deduction (BDT)','number'),reason]});
        forms.push({title:'Prepare closed-month payroll',action:root+'/payroll/prepare',fields:[field('period_month','Closed salary month','month')]});
    }
    if(section==='policy-schedules')forms.push({title:'Propose commission schedule',action:root+'/policy-schedules',policy:true,policyRules:policy?.rules??{},fields:[options('scope','Schedule applies to',['global','buyer','recipient']),{...partnerField,required:false},{name:'rule_key',label:'Commission rule',required:true,options:Object.entries(policy?.rules??{}).map(([key,value]:[string,any])=>({value:key,label:value.name}))},field('percentage','Rate (%)','number'),field('effective_at','Starts (Dhaka)','datetime-local'),field('expires_at','Expires (optional, Dhaka)','datetime-local',false),reason]});
    if(section==='reserve-payouts')forms.push({title:'Request reward or company expense',action:root+'/reserves',fields:[options('program','Reserve program',['tour','additional_rank','bike','car','marketing']),{...partnerField,required:false},field('payee_name','Company expense payee','text',false),field('amount','Approved amount requested (BDT)','number'),reason],defaults:{request_key:crypto.randomUUID()}});
    if(section==='financial-readiness')forms.push({title:'Submit evidenced opening balances',action:root+'/opening-balances',fields:[field('wallet_available','Verified available wallet total (BDT)','number'),field('wallet_held','Verified held wallet total (BDT)','number'),field('order_paid','Verified collected order total (BDT)','number'),field('withdrawal_paid','Verified paid withdrawal total (BDT)','number'),field('source_reference','Source / statement reference'),field('evidence','Independent evidence (PDF or image, up to 10 MB)','file')]});
    const accountFields:Field[]=[field('label','Account label'),options('channel','Gateway / payment method',['Bank','bKash','Nagad','Rocket']),field('holder','Account holder'),field('account_number','Account number'),field('details','Payment instructions','textarea',false),options('collects_payments','Accept collections?',['1','0']),options('pays_out','Allow payouts?',['1','0']),options('is_active','Account active?',['1','0']),reason];
    if(section==='payment-accounts')forms.push({title:account?'Save account version':'Add company payment account',action:root+'/payment-accounts'+(account?'/'+account.id:''),fields:accountFields,defaults:account?{...account,collects_payments:account.collects_payments?'1':'0',pays_out:account.pays_out?'1':'0',is_active:account.is_active?'1':'0',_method:'patch'}:{collects_payments:'1',pays_out:'0',is_active:'1'}});
    const rowActions=(row:Row,title:string)=> {
        const actions:Action[]=[];
        if(title==='Territories'&&row.is_active)actions.push({title:'Remove territory',action:`${root}/staff/territories/${row.id}/remove`,fields:[reason]});
        if(title==='Dealer applications'&&row.status==='pending'&&capabilities.includes('accounts')&&row.submitted_by!==auth.user.id)actions.push(review('Review application',`${root}/staff/applications/${row.id}/review`,['approved','rejected']));
        if(title==='Salary register'&&section==='payroll'&&financeActive&&capabilities.includes('finance')) {
            if(row.status==='draft'&&![row.staff_id,row.prepared_by].includes(auth.user.id))actions.push(review('Review payroll',`${root}/payroll/${row.id}/review`,['approved','rejected']));
            if(row.status==='approved'&&![row.staff_id,row.approved_by].includes(auth.user.id))actions.push(payment('Record salary payment',`${root}/payroll/${row.id}/pay`));
        }
        if(title==='Policy schedules')actions.push(review('Review schedule',`${root}/policy-schedules/${row.id}/review`,[...(row.status==='draft'&&row.created_by!==auth.user.id?['approved']:[]),...(['draft','approved'].includes(row.status)?['cancelled']:[])]));
        if(title==='Reward and expense requests'&&financeActive) {
            if(row.status==='requested'&&![row.created_by,row.user_id].includes(auth.user.id))actions.push(review('Review reserve request',`${root}/reserves/${row.id}/review`,['approved','rejected']));
            if(row.program==='marketing'&&row.status==='approved') {
                if(row.approved_by!==auth.user.id)actions.push(payment('Record expense payment',`${root}/reserves/${row.id}/pay`));
                actions.push(review('Cancel unpaid expense',`${root}/reserves/${row.id}/review`,['cancelled']));
            }
        }
        if(title==='Held allocations'&&financeActive)actions.push({title:'Review and release hold',action:`${root}/holds/${row.id}/release`,fields:[reason]});
        if(title==='Opening balance reviews'&&!row.verified_at&&row.created_by!==auth.user.id)actions.push({title:'Verify opening evidence and totals',action:`${root}/opening-balances/${row.id}/verify`,fields:[]});
        if(title==='Company reserve clawbacks'&&Number(row.amount)>Number(row.recovered)&&financeActive)actions.push({...payment('Record recovered reserve',`${root}/reserves/clawbacks/${row.id}/recover`),fields:[choose(paymentAccounts.filter((a:Row)=>a.is_active&&a.collects_payments),'payment_account_id','Company collection account'),field('amount','Recovered amount (BDT)','number'),field('payment_reference','Collection reference'),field('paid_at','Collection time (Dhaka)','datetime-local'),field('receipt','Recovery receipt','file'),reason]});
        const receiptKind=title==='Salary register'?'payroll':title==='Reward and expense requests'?'reserve':title==='Clawback recoveries'?'recovery':title==='Opening balance reviews'?'opening':null;
        return <div style={{display:'flex',gap:8,flexWrap:'wrap'}}>{title==='Territory orders and dues'&&capabilities.includes('sr_orders')&&<Link className="button small" href={`${root}/staff/orders/${row.id}`}>Order details</Link>}{actions.filter(a=>!a.fields.find(f=>f.name==='decision')?.options?.length&&a.fields.some(f=>f.name==='decision')?false:true).map(a=><button key={a.title} className="button small" onClick={()=>setSelected(a)}>{a.title}</button>)}{receiptKind&&(row.has_receipt||receiptKind==='opening')&&<a className="button small" href={`${root}/receipts/${receiptKind}/${row.id}`}>Private evidence</a>}</div>;
    };
    return <Shell section={section}><Head title={titles[section]}/><p className="muted">Madina Natural Health Care · BDT · Asia/Dhaka</p>
        {!financeActive&&capabilities.includes('finance')&&<div className="notice"><p>Financial posting is disabled. Draft preparation and configuration are available. Review the approved policy and opening balances before enabling payments.</p></div>}
        {section==='policy-schedules'&&<p>Global and buyer schedules set the terms for new orders. Recipient schedules cap an individual partner’s earning rate; the remainder stays in reserve. A separate Finance reviewer approves each schedule.</p>}
        {section==='payroll'&&<p>Salary contracts retain every revision. Review the captured contract, target and earned bonus before approval. Approved entries require a separate payer and a private receipt.</p>}
        {section==='reserve-payouts'&&<p>Tour rewards use the selected sub-dealer’s own reserve. Approved partner rewards credit their earnings wallet. Marketing expenses require a separate payer, actual bank or gateway reference and receipt.</p>}
        {Object.keys(summary).length>0&&<section className="panel"><dl className="profile-grid">{Object.entries(summary).map(([key,value])=><div key={key}><dt>{label(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {section==='field-sales'&&<section className="panel"><h2>My territories</h2>{regions.length?regions.map((r:Row)=><p key={r.id}>{[r.division,r.district,r.upazila,r.union].filter(Boolean).join(' / ')}</p>):<Empty title="No territory assigned" body="Ask your manager to assign your sales territory."/>}</section>}
        {['field-sales','policy-schedules','reserve-payouts'].includes(section)&&<form method="get" className="action-form"><label>Find a partner by name (up to 200 matches)<input name="q" type="search"/></label><button className="button">Search partners</button></form>}
        {readiness&&<section className="panel"><h2>Ledger reconciliation</h2><Table rows={Object.entries(readiness.checks).map(([name,passed])=>({name:label(name),status:passed?'passed':'needs_review'}))} columns={[{key:'name',title:'Check'},{key:'status',title:'Result',render:r=><Badge status={r.status}/>}]} /><p>Opening evidence: {readiness.opening_verified?'verified':'awaiting independent approval'}. Internal reconciliation: {readiness.internally_reconciled?'passed':'requires review'}.</p><dl className="profile-grid">{Object.entries(readiness.totals).map(([key,value])=><div key={key}><dt>{label(key)}</dt><dd>{money(value)}</dd></div>)}</dl></section>}
        {section==='financial-reports'&&<Reports salesOnly={!capabilities.includes('finance')&&!capabilities.includes('reports')}/>}
        {section==='payment-accounts'&&<section className="panel"><h2>Company account registry</h2><Table rows={paymentAccounts} columns={[{key:'label',title:'Account'},{key:'channel',title:'Method'},{key:'holder',title:'Holder'},{key:'account_number',title:'Number'},{key:'is_active',title:'Status',render:r=><Badge status={r.is_active?'active':'inactive'}/>},{key:'edit',title:'Manage',render:r=><div style={{display:'flex',gap:8}}><button className="button small" onClick={()=>setAccount(r)}>Edit version {r.version}</button><Link className="button small" href={'/company/operations/payment-accounts?account_history='+r.id}>Version history</Link></div>}]}/>{account&&<button className="button" onClick={()=>setAccount(null)}>Create another account</button>}</section>}
        {accountHistory.length>0&&<section className="panel"><h2>Payment account version history</h2><Table rows={accountHistory} columns={[{key:'version',title:'Version'},{key:'label',title:'Account'},{key:'account_number',title:'Number at this version'},{key:'recorded_at',title:'Recorded (UTC)'},{key:'reason',title:'Reason'}]}/></section>}{forms.map(a=><section className="panel narrow-panel" key={a.title+(account?.version??'')}><h2>{a.title}</h2><OperationForm {...a} onDone={()=>{if(section==='payment-accounts')setAccount(null);}}/></section>)}
        {tables.map((table:Row)=>{const rows=table.page.data as Row[];const keys=[...new Set(rows.flatMap(r=>Object.keys(r)))].filter(k=>!['staff_id','submitted_by','created_by','approved_by','verified_by','user_id','has_receipt','territory_key','territories','created_at','updated_at'].includes(k));return <section className="panel" key={table.title}><h2>{table.title}</h2><Table rows={rows} columns={[...keys.map(key=>({key,title:label(key),render:(row:Row)=>key==='status'?<Badge status={row[key]}/>:['rules','metadata','data','snapshot'].includes(key)?<details><summary>View details</summary><div style={{minWidth:240,maxWidth:420,overflowWrap:'anywhere'}}><Detail value={typeof row[key]==='string'?JSON.parse(row[key]):row[key]}/></div></details>:String(row[key]??'—')})),{key:'actions',title:'Actions',render:r=>rowActions(r,table.title)}]}/><Pagination page={table.page}/></section>;})}
        {selected&&<Modal title={selected.title} onClose={()=>setSelected(null)}><OperationForm {...selected} onDone={()=>setSelected(null)}/></Modal>}
    </Shell>;
}
