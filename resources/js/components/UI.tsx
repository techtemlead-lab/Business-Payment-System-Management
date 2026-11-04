import React,{useEffect,useMemo,useRef,useState} from 'react';
import {Link,useForm,usePage} from '@inertiajs/react';
import {ArrowUpRight, X, Inbox, ArrowUpDown, ArrowUp, ArrowDown, RotateCcw, ChevronLeft, ChevronRight} from 'lucide-react';
import {actionAllowed} from './action-permissions';
import {businessDate, businessTodayIso, isPastBusinessDate} from '../business-date';
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
function rowSearchText(value:unknown,depth=0):string{
 if(value==null||depth>2)return '';
 if(typeof value==='string'||typeof value==='number')return String(value);
 if(Array.isArray(value))return value.map(item=>rowSearchText(item,depth+1)).join(' ');
 if(typeof value==='object')return Object.values(value as Record<string,unknown>).map(item=>rowSearchText(item,depth+1)).join(' ');
 return '';
}

export type TableColumn<T extends Row = any> = {
 key: string;
 title?: string;
 header?: string;
 render?: (row: T, index: number) => React.ReactNode;
 sortable?: boolean;
 sortValue?: (row: T) => any;
 align?: 'left' | 'center' | 'right';
 className?: string;
 width?: string | number;
};

export interface CustomTableFilter {
 key: string;
 label: string;
 options: { value: string; label: string }[];
}

export interface TableProps<T extends Row = any> {
 rows?: T[];
 data?: T[];
 columns: TableColumn<T>[];
 empty?: string;
 emptyMessage?: string;
 emptyBody?: string;
 tools?: boolean;
 defaultSortKey?: string;
 defaultSortDir?: 'asc' | 'desc';
 pageSize?: number;
 searchPlaceholder?: string;
 filters?: CustomTableFilter[];
 extraTools?: React.ReactNode;
 onRowClick?: (row: T, index: number) => void;
 rowClassName?: (row: T, index: number) => string | undefined;
 rowStyle?: (row: T, index: number) => React.CSSProperties | undefined;
}

function getSortValue(row: any, key: string, col?: TableColumn<any>): any {
 if (col?.sortValue) {
  try {
   return col.sortValue(row);
  } catch {
   // fallback
  }
 }
 if (row == null) return undefined;
 if (row[key] !== undefined) return row[key];
 if (key.includes('.')) {
  return key.split('.').reduce((acc, part) => acc?.[part], row);
 }
 return undefined;
}

function compareTableValues(a: any, b: any, dir: 'asc' | 'desc'): number {
 if (a === b) return 0;
 if (a == null || a === '') return 1;
 if (b == null || b === '') return -1;

 if (typeof a === 'boolean' && typeof b === 'boolean') {
  const diff = a === b ? 0 : a ? 1 : -1;
  return dir === 'desc' ? -diff : diff;
 }

 const parseNum = (val: any): number => {
  if (typeof val === 'number') return val;
  if (typeof val === 'string') {
   const trimmed = val.trim();
   if (/^[\s৳$€£,.\d+-]+%?$/.test(trimmed) && /\d/.test(trimmed)) {
    const clean = trimmed.replace(/[^0-9.-]/g, '');
    const parsed = Number(clean);
    if (!Number.isNaN(parsed)) return parsed;
   }
  }
  return NaN;
 };

 const numA = parseNum(a);
 const numB = parseNum(b);
 if (!Number.isNaN(numA) && !Number.isNaN(numB)) {
  const diff = numA - numB;
  return dir === 'desc' ? -diff : diff;
 }

 if (typeof a === 'string' && typeof b === 'string' && /^\d{4}-\d{2}-\d{2}/.test(a) && /^\d{4}-\d{2}-\d{2}/.test(b)) {
  const dateA = Date.parse(a);
  const dateB = Date.parse(b);
  if (!Number.isNaN(dateA) && !Number.isNaN(dateB)) {
   const diff = dateA - dateB;
   return dir === 'desc' ? -diff : diff;
  }
 }

 const strA = String(a).toLowerCase();
 const strB = String(b).toLowerCase();
 const cmp = strA.localeCompare(strB, undefined, { numeric: true, sensitivity: 'base' });
 return dir === 'desc' ? -cmp : cmp;
}

export function Table<T extends Row = any>({
 rows,
 data,
 columns,
 empty,
 emptyMessage,
 emptyBody,
 tools = true,
 defaultSortKey,
 defaultSortDir,
 pageSize,
 searchPlaceholder,
 filters,
 extraTools,
 onRowClick,
 rowClassName,
 rowStyle,
}: TableProps<T>){
 const translate = useT();
 const list = rows ?? data ?? [];
 const emptyTitle = empty ?? emptyMessage;
 const [query, setQuery] = useState('');
 const [status, setStatus] = useState('');
 const [customFilters, setCustomFilters] = useState<Record<string, string>>({});
 const [sortKey, setSortKey] = useState<string | null>(defaultSortKey ?? null);
 const [sortDir, setSortDir] = useState<'asc' | 'desc' | null>(defaultSortDir ?? null);
 const [currentPage, setCurrentPage] = useState(1);
 const [clientPageSize, setClientPageSize] = useState<number | null>(pageSize ?? null);

 const statuses = useMemo(() => [
  ...new Set(list.map(row => typeof (row as Row).status === 'string' ? (row as Row).status as string : '').filter(Boolean))
 ], [list]);

 const handleHeaderClick = (colKey: string, isSortable: boolean) => {
  if (!isSortable) return;
  if (sortKey !== colKey) {
   setSortKey(colKey);
   setSortDir('asc');
  } else if (sortDir === 'asc') {
   setSortDir('desc');
  } else {
   setSortKey(null);
   setSortDir(null);
  }
 };

 const filtered = useMemo(() => {
  const needle = query.trim().toLowerCase();
  return list.filter(row => {
   if (status && String((row as Row).status ?? '') !== status) return false;
   for (const [fKey, fVal] of Object.entries(customFilters)) {
    if (fVal && String((row as Row)[fKey] ?? '') !== fVal) return false;
   }
   if (!needle) return true;
   return rowSearchText(row).toLowerCase().includes(needle);
  });
 }, [query, list, status, customFilters]);

 const sorted = useMemo(() => {
  if (!sortKey || !sortDir) return filtered;
  const col = columns.find(c => c.key === sortKey);
  return [...filtered].sort((left, right) => {
   const valL = getSortValue(left, sortKey, col);
   const valR = getSortValue(right, sortKey, col);
   return compareTableValues(valL, valR, sortDir);
  });
 }, [filtered, sortKey, sortDir, columns]);

 const activePageSize = clientPageSize;
 const totalPages = activePageSize ? Math.ceil(sorted.length / activePageSize) : 1;
 const pagedRows = useMemo(() => {
  if (!activePageSize || activePageSize >= sorted.length) return sorted;
  const start = (currentPage - 1) * activePageSize;
  return sorted.slice(start, start + activePageSize);
 }, [sorted, activePageSize, currentPage]);

 const hasActiveFilters = Boolean(query || status || Object.values(customFilters).some(Boolean) || sortKey);

 const resetFilters = () => {
  setQuery('');
  setStatus('');
  setCustomFilters({});
  setSortKey(defaultSortKey ?? null);
  setSortDir(defaultSortDir ?? null);
  setCurrentPage(1);
 };

 const body = pagedRows.length ? pagedRows.map((r, i) => (
  <tr
   key={(r as any).id ?? (r as any).row_key ?? i}
   onClick={onRowClick ? () => onRowClick(r, i) : undefined}
   className={rowClassName ? rowClassName(r, i) : undefined}
   style={rowStyle ? rowStyle(r, i) : (onRowClick ? { cursor: 'pointer' } : undefined)}
  >
   {columns.map(c => (
    <td
     key={c.key}
     style={{ textAlign: c.align ?? 'left' }}
     className={c.className}
    >
     {c.render ? c.render(r, i) : String((r as any)[c.key] ?? '—')}
    </td>
   ))}
  </tr>
 )) : (
  <tr>
   <td colSpan={columns.length}>
    <Empty
     title={list.length ? translate('portal', 'table_no_matches') : emptyTitle}
     body={list.length ? translate('portal', 'table_no_matches_hint') : emptyBody}
    />
   </td>
  </tr>
 );

 return (
  <div className="table-block">
   {tools && (
    <div className="table-tools">
     <input
      type="search"
      value={query}
      onChange={e => { setQuery(e.target.value); setCurrentPage(1); }}
      placeholder={searchPlaceholder ?? translate('portal', 'table_search')}
      aria-label={searchPlaceholder ?? translate('portal', 'table_search')}
     />
     {statuses.length > 1 && (
      <select
       aria-label={translate('portal', 'table_status')}
       value={status}
       onChange={e => { setStatus(e.target.value); setCurrentPage(1); }}
      >
       <option value="">{translate('portal', 'table_all')}</option>
       {statuses.map(item => (
        <option key={item} value={item}>{label(item, translate)}</option>
       ))}
      </select>
     )}
     {filters?.map(f => (
      <select
       key={f.key}
       aria-label={f.label}
       value={customFilters[f.key] ?? ''}
       onChange={e => {
        setCustomFilters(prev => ({ ...prev, [f.key]: e.target.value }));
        setCurrentPage(1);
       }}
      >
       <option value="">{f.label}</option>
       {f.options.map(opt => (
        <option key={opt.value} value={opt.value}>{opt.label}</option>
       ))}
      </select>
     ))}
     {hasActiveFilters && (
      <button
       type="button"
       className="button small"
       style={{ padding: '4px 8px', fontSize: 11, height: 32, minHeight: 32, display: 'inline-flex', alignItems: 'center', gap: 4 }}
       onClick={resetFilters}
       title={translate('portal', 'table_clear_filters')}
      >
       <RotateCcw size={12} />
       <span>{translate('portal', 'clear')}</span>
      </button>
     )}
     {extraTools}
     <span className="table-count-badge">
      {translate('portal', 'table_showing_count', {
       shown: String(sorted.length),
       total: String(list.length),
      })}
     </span>
    </div>
   )}
   <div className="table-wrap">
    <table>
     <thead>
      <tr>
       {columns.map(c => {
        const colHeading = c.title ?? c.header ?? '';
        const isSortable = c.sortable ?? (c.key !== 'action' && c.key !== 'actions' && (colHeading.trim() !== ''));
        const isSorted = sortKey === c.key;
        return (
         <th
          key={c.key}
          style={{
           textAlign: c.align ?? 'left',
           width: c.width,
           cursor: isSortable ? 'pointer' : 'default',
           userSelect: isSortable ? 'none' : 'auto',
          }}
          className={`${isSortable ? 'table-th-sortable' : ''} ${isSorted ? 'is-sorted' : ''} ${c.className ?? ''}`}
          onClick={() => handleHeaderClick(c.key, isSortable)}
          aria-sort={isSorted ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}
         >
          {isSortable ? (
           <span
            className="table-sort-trigger"
            style={{
             justifyContent: c.align === 'right' ? 'flex-end' : c.align === 'center' ? 'center' : 'flex-start',
            }}
            title={
             isSorted
              ? (sortDir === 'asc' ? translate('portal', 'table_sort_desc') : translate('portal', 'table_sort_reset'))
              : translate('portal', 'table_sort_asc')
            }
           >
            <span>{colHeading}</span>
            <span className="table-sort-icon" aria-hidden="true" style={{ opacity: isSorted ? 1 : 0.35 }}>
             {isSorted ? (
              sortDir === 'asc' ? <ArrowUp size={12} /> : <ArrowDown size={12} />
             ) : (
              <ArrowUpDown size={12} />
             )}
            </span>
           </span>
          ) : (
           colHeading
          )}
         </th>
        );
       })}
      </tr>
     </thead>
     <tbody>{body}</tbody>
    </table>
   </div>
   {activePageSize && totalPages > 1 && (
    <div className="table-client-pagination">
     <span>
      {translate('portal', 'pagination_summary', {
       page: String(currentPage),
       pages: String(totalPages),
       total: String(sorted.length),
      })}
     </span>
     <div className="page-btns">
      <button
       type="button"
       className="page-btn"
       disabled={currentPage <= 1}
       onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
       aria-label={translate('portal', 'pagination_previous')}
      >
       <ChevronLeft size={14} />
      </button>
      <span>{currentPage} / {totalPages}</span>
      <button
       type="button"
       className="page-btn"
       disabled={currentPage >= totalPages}
       onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
       aria-label={translate('portal', 'pagination_next')}
      >
       <ChevronRight size={14} />
      </button>
      <select
       className="page-size-select"
       value={activePageSize}
       onChange={e => {
        const val = Number(e.target.value);
        setClientPageSize(val);
        setCurrentPage(1);
       }}
       aria-label={translate('portal', 'table_rows_per_page')}
      >
       {[10, 15, 25, 50, 100].map(sz => (
        <option key={sz} value={sz}>{sz} / page</option>
       ))}
      </select>
     </div>
    </div>
   )}
  </div>
 );
}
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
export type Field={name:string,label:string,type?:string,options?:{value:string,label:string}[],required?:boolean,value?:any,warnPast?:boolean};
export function ActionForm({action,fields,defaults={},submit,method='post',onDone}:{action:string,fields:Field[],defaults?:Row,submit?:string,method?:'post'|'patch',onDone?:()=>void}){
 const translate=useT();
 const {portal,staffActions}=usePage<any>().props;
 const initial:Row={...Object.fromEntries(fields.map(f=>[f.name,f.value??''])),...defaults}; const f=useForm<Row>(initial);
 if((portal==='manage'||portal==='sadmin')&&!actionAllowed(action,staffActions,method))return null;
 const pastField=fields.find(field=>field.warnPast&&isPastBusinessDate(String(f.data[field.name]??'')));
 return <form className="action-form" onSubmit={e=>{e.preventDefault();if(pastField){f.setError(pastField.name,translate('portal','err_stock_expiry_past'));return;}f.clearErrors();f.submit(method,action,{preserveScroll:true,onSuccess:()=>onDone?.()})}}>{fields.map(field=>{const past=field.warnPast&&isPastBusinessDate(String(f.data[field.name]??''));const message=past?translate('portal','err_stock_expiry_past'):(f.errors[field.name]?String(f.errors[field.name]):'');return <label key={field.name}>{field.label}{field.type==='textarea'?<textarea rows={4} value={f.data[field.name]} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>:field.type==='date'?<DateInput value={f.data[field.name]} required={field.required} min={field.warnPast?businessTodayIso():undefined} onChange={e=>f.setData(field.name,e.target.value)}/>:field.options?<select value={String(f.data[field.name])} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}><option value="">{translate('portal','select_placeholder')}</option>{field.options.map(o=><option key={o.value} value={o.value}>{o.label}</option>)}</select>:<input type={field.type??'text'} value={f.data[field.name]} step={field.type==='number'?'any':undefined} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>} {message&&<span className="field-error" role="alert">{message}</span>}</label>})}<button disabled={f.processing||Boolean(pastField)} className="button dark">{f.processing?translate('portal','saving'):(submit??translate('portal','save_changes'))}<ArrowUpRight size={17}/></button></form>
}
