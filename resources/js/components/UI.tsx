import React,{useEffect,useRef} from 'react';
import {Link,useForm,usePage} from '@inertiajs/react';
import {ArrowUpRight, X,Inbox} from 'lucide-react';
import {actionAllowed} from './action-permissions';
import {businessDate} from '../business-date';
import {useT} from '../shared/i18n/t';
export type Row=Record<string,any>;
export const money=(value:unknown)=>new Intl.NumberFormat('en-BD',{style:'currency',currency:'BDT',maximumFractionDigits:2}).format(Number(value??0));
export const date=businessDate;
const businessLabels:Record<string,string>={direct_referral:'Direct referral · Generation 1',d2d_gen2:'D2D · Generation 2',d2d_gen3:'D2D · Generation 3'};
export const label=(text:string)=>businessLabels[text]??text.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
export function Badge({status}:{status:string}) {return <span className={`badge ${status}`}>{label(status)}</span>}
export function Empty({title,body}:{title?:string,body?:string}) {
 const translate=useT();
 return <div className="empty"><Inbox size={30}/><h3>{title??translate('portal','empty_default_title')}</h3><p>{body??translate('portal','empty_default_body')}</p></div>;
}
export function Flash(){const {flash,errors}=usePage<any>().props;return <>{flash?.success&&<div className="success" role="status">{flash.success}</div>}{Object.keys(errors??{}).length>0&&<div className="error" role="alert">{Object.values(errors).join(' ')}</div>}</>}
export function Table({rows,columns,empty,emptyBody}:{rows:Row[],columns:{key:string,title:string,render?:(row:Row)=>React.ReactNode}[],empty?:string,emptyBody?:string}){return rows.length?<div className="table-wrap"><table><thead><tr>{columns.map(c=><th key={c.key}>{c.title}</th>)}</tr></thead><tbody>{rows.map((r,i)=><tr key={r.id??i}>{columns.map(c=><td key={c.key}>{c.render?c.render(r):String(r[c.key]??'—')}</td>)}</tr>)}</tbody></table></div>:<Empty title={empty} body={emptyBody}/>}
export function Pagination({page}:{page?:Row}){
 const translate=useT();
 return page&&page.last_page>1?<div className="pagination"><span>{translate('portal','pagination_summary',{page:String(page.current_page),pages:String(page.last_page),total:String(page.total)})}</span><div>{page.prev_page_url&&<Link href={page.prev_page_url}>{translate('portal','pagination_previous')}</Link>}{page.next_page_url&&<Link href={page.next_page_url}>{translate('portal','pagination_next')}</Link>}</div></div>:null;
}
export function Modal({title,onClose,children}:{title:string,onClose:()=>void,children:React.ReactNode}){const translate=useT();const ref=useRef<HTMLDialogElement>(null);useEffect(()=>{ref.current?.showModal();return ()=>ref.current?.close()},[]);return <dialog ref={ref} onCancel={onClose} onClick={e=>{if(e.target===ref.current)onClose()}} style={{position:'fixed',top:'50%',left:'50%',transform:'translate(-50%,-50%)',margin:0}}><div className="modal-heading"><h2>{title}</h2><button aria-label={translate('portal','close_dialog')} className="icon-button" onClick={onClose}><X/></button></div>{children}</dialog>}
export type Field={name:string,label:string,type?:string,options?:{value:string,label:string}[],required?:boolean,value?:any};
export function ActionForm({action,fields,defaults={},submit,method='post',onDone}:{action:string,fields:Field[],defaults?:Row,submit?:string,method?:'post'|'patch',onDone?:()=>void}){
 const translate=useT();
 const {portal,staffActions}=usePage<any>().props;
 const initial:Row={...Object.fromEntries(fields.map(f=>[f.name,f.value??''])),...defaults}; const f=useForm<Row>(initial);
 if((portal==='manage'||portal==='sadmin')&&!actionAllowed(action,staffActions,method))return null;
 return <form className="action-form" onSubmit={e=>{e.preventDefault();f.submit(method,action,{preserveScroll:true,onSuccess:()=>onDone?.()})}}>{fields.map(field=><label key={field.name}>{field.label}{field.type==='textarea'?<textarea rows={4} value={f.data[field.name]} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>:field.options?<select value={String(f.data[field.name])} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}><option value="">{translate('portal','select_placeholder')}</option>{field.options.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:<input type={field.type??'text'} value={f.data[field.name]} step={field.type==='number'?'any':undefined} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>} {f.errors[field.name]&&<span className="field-error">{String(f.errors[field.name])}</span>}</label>)}<button disabled={f.processing} className="button dark">{f.processing?translate('portal','saving'):(submit??translate('portal','save_changes'))}<ArrowUpRight size={17}/></button></form>
}
