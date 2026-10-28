import React,{useEffect,useRef} from 'react';
import {Link,useForm,usePage} from '@inertiajs/react';
import {ArrowUpRight, X,Inbox} from 'lucide-react';
import {actionAllowed} from './action-permissions';
import {businessDate} from '../business-date';
import {useT} from '../shared/i18n/t';
import DateInput from './DateInput';

export { default as DateInput } from './DateInput';
export type Row=Record<string,any>;
export const money=(value:unknown)=>{
 const num=Number(value??0);
 return `৳ ${num.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}`;
};
export const date=businessDate;
type TranslateFn=(group:string,key:string,replace?:Record<string,string>)=>string;
export const label=(text:string,translate?:TranslateFn)=>{
 if(!text){
  return text;
 }
 if(translate){
  const candidates=[
   `report_cat_${text}`,
   `label_${text}`,
   `acct_role_${text}`,
   `settle_kind_${text}`,
   `jr_account_${text}`,
   `status_${text}`,
   text,
  ];
  for(const key of candidates){
   const localized=translate('portal',key);
   if(localized!==key){
    return localized;
   }
  }
 }
 return text.replaceAll('_',' ').replace(/\b\w/g,c=>c.toUpperCase());
};
export function useLabel(){
 const translate=useT();
 return (text:string)=>label(text,translate);
}
export function Badge({status}:{status:string}) {
 const translate=useT();
 const key=`status_${status}`;
 const localized=translate('portal',key);
 return <span className={`badge ${status}`}>{localized!==key?localized:label(status,translate)}</span>;
}
export function Empty({title,body}:{title?:string,body?:string}) {
 const translate=useT();
 return <div className="empty"><Inbox size={30}/><h3>{title??translate('portal','empty_default_title')}</h3><p>{body??translate('portal','empty_default_body')}</p></div>;
}
export function Flash(){const {flash,errors}=usePage<any>().props;return <>{flash?.success&&<div className="success" role="status">{flash.success}</div>}{Object.keys(errors??{}).length>0&&<div className="error" role="alert">{Object.values(errors).join(' ')}</div>}</>}
export function Table<T extends Row = any>({rows,columns,empty,emptyBody}:{rows:T[],columns:{key:string,title:string,render?:(row:any)=>React.ReactNode}[],empty?:string,emptyBody?:string}){return rows.length?<div className="table-wrap"><table><thead><tr>{columns.map(c=><th key={c.key}>{c.title}</th>)}</tr></thead><tbody>{rows.map((r,i)=><tr key={(r as any).id??i}>{columns.map(c=><td key={c.key}>{c.render?c.render(r):String((r as any)[c.key]??'—')}</td>)}</tr>)}</tbody></table></div>:<Empty title={empty} body={emptyBody}/>}
export function Pagination({page}:{page?:Row}){
 const translate=useT();
 return page&&page.last_page>1?<div className="pagination"><span>{translate('portal','pagination_summary',{page:String(page.current_page),pages:String(page.last_page),total:String(page.total)})}</span><div>{page.prev_page_url&&<Link href={page.prev_page_url}>{translate('portal','pagination_previous')}</Link>}{page.next_page_url&&<Link href={page.next_page_url}>{translate('portal','pagination_next')}</Link>}</div></div>:null;
}
export function Modal({title,onClose,children}:{title:string,onClose:()=>void,children:React.ReactNode}){
 const translate=useT();
 const ref=useRef<HTMLDialogElement>(null);
 useEffect(()=>{
  const dialog=ref.current;
  dialog?.showModal();
  const previous=document.activeElement as HTMLElement|null;
  dialog?.querySelector<HTMLElement>('button, [href], input, select, textarea')?.focus();
  return ()=>{dialog?.close();previous?.focus?.()};
 },[]);
 return (
  <dialog
   ref={ref}
   className="portal-modal"
   onCancel={onClose}
   onClick={e=>{if(e.target===ref.current)onClose()}}
  >
   <div className="modal-heading">
    <h2>{title}</h2>
    <button type="button" aria-label={translate('portal','close_dialog')} title={translate('portal','close_dialog')} className="icon-button" onClick={onClose}><X/></button>
   </div>
   <div className="modal-body">{children}</div>
  </dialog>
 );
}
export type Field={name:string,label:string,type?:string,options?:{value:string,label:string}[],required?:boolean,value?:any};
export function ActionForm({action,fields,defaults={},submit,method='post',onDone}:{action:string,fields:Field[],defaults?:Row,submit?:string,method?:'post'|'patch',onDone?:()=>void}){
 const translate=useT();
 const {portal,staffActions}=usePage<any>().props;
 const initial:Row={...Object.fromEntries(fields.map(f=>[f.name,f.value??''])),...defaults}; const f=useForm<Row>(initial);
 if((portal==='manage'||portal==='sadmin')&&!actionAllowed(action,staffActions,method))return null;
 return <form className="action-form" onSubmit={e=>{e.preventDefault();f.submit(method,action,{preserveScroll:true,onSuccess:()=>onDone?.()})}}>{fields.map(field=><label key={field.name}>{field.label}{field.type==='textarea'?<textarea rows={4} value={f.data[field.name]} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>:field.type==='date'?<DateInput value={f.data[field.name]} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>:field.options?<select value={String(f.data[field.name])} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}><option value="">{translate('portal','select_placeholder')}</option>{field.options.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:<input type={field.type??'text'} value={f.data[field.name]} step={field.type==='number'?'any':undefined} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>} {f.errors[field.name]&&<span className="field-error">{String(f.errors[field.name])}</span>}</label>)}<button disabled={f.processing} className="button dark">{f.processing?translate('portal','saving'):(submit??translate('portal','save_changes'))}<ArrowUpRight size={17}/></button></form>
}
