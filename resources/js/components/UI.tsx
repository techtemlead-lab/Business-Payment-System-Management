import React,{useEffect,useMemo,useRef,useState} from 'react';
import {Link,useForm,usePage} from '@inertiajs/react';
import {ArrowUpRight, X, Inbox, ArrowUpDown, ArrowUp, ArrowDown, ArrowLeftRight, RotateCcw, ChevronLeft, ChevronRight, ChevronDown, ChevronUp, Download, Printer} from 'lucide-react';
import {actionAllowed} from './action-permissions';
import {businessDate, businessDateTime, businessTodayIso, isPastBusinessDate, localizeDigits} from '../business-date';
import {useT} from '../shared/i18n/t';
import DateInput from './DateInput';
import {AutoSelect} from './SearchSelect';

export { default as DateInput } from './DateInput';
export type Row=Record<string,any>;
const moneyFormat=new Intl.NumberFormat('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
const countFormat=new Intl.NumberFormat('en-IN',{maximumFractionDigits:2});
/**
 * Read an amount sent by the server (number or decimal string such as "1234.50" or "1,234.50").
 * Anything unreadable counts as zero.
 */
function toAmount(value:unknown):number{
 if(typeof value==='number'){
  return Number.isFinite(value)?value:0;
 }
 if(typeof value==='string'){
  const parsed=Number(value.replace(/[৳,\s]/g,''));
  return Number.isFinite(parsed)?parsed:0;
 }
 if(typeof value==='bigint'){
  return Number(value);
 }
 return 0;
}
/**
 * Taka amount with lakh grouping ("৳ 1,23,456.00"); Bangla digits on Bangla pages.
 * Pass `{symbol:false}` when the ৳ sign is drawn separately. The sign is joined
 * with a no-break space so a narrow card never puts ৳ and the amount on two lines.
 */
export const money=(value:unknown,{symbol=true}:{symbol?:boolean}={})=>{
 const rounded=Math.round(toAmount(value)*100)/100;
 const text=localizeDigits(moneyFormat.format(rounded===0?0:rounded));
 return symbol?`৳ ${text}`:text;
};
/**
 * Count or quantity with lakh grouping ("1,23,456"); Bangla digits on Bangla pages.
 */
export const number=(value:unknown)=>{
 const amount=toAmount(value);
 return localizeDigits(countFormat.format(amount===0?0:amount));
};
export const date=businessDate;
export const dateTime=businessDateTime;
type TranslateFn=(group:string,key:string,replace?:Record<string,string>)=>string;
const CODE_PATTERN=/^[A-Za-z0-9]+(?:_[A-Za-z0-9]+)*$/;
/**
 * Last resort for a code with no translation: "partially_dispatched" becomes
 * "Partially dispatched". Ordinary text such as a name or "SKU" is left as it is.
 */
export const humanize=(text:string)=>{
 if(!text||!CODE_PATTERN.test(text)||(!text.includes('_')&&text!==text.toLowerCase())){
  return text;
 }
 const words=text.replaceAll('_',' ').toLowerCase();
 return words.charAt(0).toUpperCase()+words.slice(1);
};
const OVERRIDE_RESERVE_SUFFIX='_override_reserve';
export const label=(text:string,translate?:TranslateFn):string=>{
 if(!text){
  return text;
 }
 if(translate&&text.endsWith(OVERRIDE_RESERVE_SUFFIX)){
  const key='report_cat_override_reserve';
  const localized=translate('portal',key,{category:label(text.slice(0,-OVERRIDE_RESERVE_SUFFIX.length),translate)});
  if(localized!==key){
   return localized;
  }
 }
 if(translate){
  const candidates=[
   `report_cat_${text}`,
   `label_${text}`,
   `acct_role_${text}`,
   `staff_role_${text}`,
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
 return humanize(text);
};
/** A payment channel in the page's language; a channel with no translation stays as saved. */
export const channelLabel=(channel:unknown,translate:TranslateFn):string=>{
 const saved=String(channel??'');
 const key=`pay_channel_${saved.toLowerCase()}`;
 const localized=translate('portal',key);
 return localized!==key?localized:saved;
};
/** A readable name for an activity-log action such as "order.submitted"; unknown actions fall back to plain words. */
export const auditActionLabel=(action:string,translate:TranslateFn):string=>{
 const code=String(action||'').replaceAll('.','_');
 const key=`event_${code}`;
 const localized=translate('portal',key);
 return localized!==key?localized:label(code,translate);
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
/** With `line`, one quiet line of text instead of the icon and two lines: pass the words, or `true` to use `title`. */
export function Empty({title,body,line}:{title?:string,body?:string,line?:string|boolean}) {
 const translate=useT();
 if(line){
  return <p className="mt-empty">{typeof line==='string'?line:(title??translate('portal','empty_default_title'))}</p>;
 }
 return <div className="empty"><Inbox size={30}/><h3>{title??translate('portal','empty_default_title')}</h3><p>{body??translate('portal','empty_default_body')}</p></div>;
}
/*
 * Forms that show their own error box (often inside a dialog) register here so the
 * page-level Flash does not print the same validation message a second time.
 */
let formErrorOwners=0;
const formErrorListeners=new Set<()=>void>();
const notifyFormErrorOwners=()=>formErrorListeners.forEach(listener=>listener());
function useFormErrorsOwned(){
 const [owned,setOwned]=useState(formErrorOwners>0);
 useEffect(()=>{const update=()=>setOwned(formErrorOwners>0);formErrorListeners.add(update);update();return ()=>{formErrorListeners.delete(update)}},[]);
 return owned;
}
/**
 * Error box for a form. While the form has any refusal, the page Flash stays quiet, so a refusal is said once.
 * Fields named in besideFields show their own message under the box and are left out here.
 */
export function FormErrors({errors,className='error',besideFields=[]}:{errors:Record<string,unknown>|undefined,className?:string,besideFields?:string[]}){
 const translate=useT();
 const {portal}=usePage<any>().props;
 const refusals=Object.entries(errors??{}).filter(([,message])=>Boolean(message));
 const messages=refusals.filter(([name])=>!besideFields.includes(name)).map(([,message])=>String(message));
 const anyRefusal=refusals.length>0;
 useEffect(()=>{if(!anyRefusal)return undefined;formErrorOwners++;notifyFormErrorOwners();return ()=>{formErrorOwners--;notifyFormErrorOwners()}},[anyRefusal]);
 // Before the owner's "new start" every money step is refused; the office gets the page that clears it.
 const opensStartingBalances=['manage','sadmin'].includes(portal)&&messages.includes(translate('portal','err_wallet_posting_unverified'));
 return messages.length>0?<div className={className} role="alert">{messages.join(' ')}{opensStartingBalances&&<> <Link className="text-link" href="/operations/financial-readiness">{translate('portal','financial_readiness')}</Link></>}</div>:null;
}
export function Flash(){const {flash,errors}=usePage<any>().props;const owned=useFormErrorsOwned();return <>{flash?.success&&<div className="success" role="status">{flash.success}</div>}{flash?.warning&&<div className="notice warning" role="alert">{flash.warning}</div>}{!owned&&Object.keys(errors??{}).length>0&&<div className="error" role="alert">{Object.values(errors).join(' ')}</div>}</>}
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
 /** Money column: right-aligned, totals use money() and CSV export gets a plain number. */
 money?: boolean;
 /** Show the sum of this column (sortValue, else row[key]) for the filtered rows in the footer row. */
 total?: boolean;
 /** Custom footer cell; receives the filtered rows. Wins over `total`. */
 footer?: (rows: T[]) => React.ReactNode;
 /** Plain text/number used for CSV export and printing instead of the rendered cell. */
 exportValue?: (row: T) => string | number | null | undefined;
 /** Set false to leave the column out of CSV export and printouts. Action columns are always left out. */
 exportable?: boolean;
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
 /**
  * Set false when a server-side filter bar already filters these rows (server-paginated lists):
  * hides the built-in search box and status picker, which would only look at the current page.
  */
 searchable?: boolean;
 /** Total number of records across all server pages, for the "X of Y records" line. */
 totalCount?: number;
 /** Label of the footer totals row. Defaults to "Total". */
 totalLabel?: string;
 /** Show the "Export CSV" button. Defaults to true when `filename` is given. */
 exportable?: boolean;
 /** Show the "Print" button. Defaults to the value of `exportable`. */
 printable?: boolean;
 /** CSV file name without extension; today's date is appended. */
 filename?: string;
 /** Heading printed above the table. Defaults to the page title. */
 printTitle?: string;
 /** Keep the header row (and totals row) in view while scrolling long tables. Defaults to true. */
 stickyHeader?: boolean;
 /** Column keys to keep on phones; the other columns move into a row that opens on tap. */
 phoneColumns?: string[];
}

const STICKY_HEADER_MIN_ROWS = 12;
const STATUS_COLUMN_KEYS = new Set(['status', 'order_status']);

/**
 * Watches a table's scrolling box. When the table is wider than its panel, the Status and
 * action columns stay pinned on the right and a hint says the table scrolls sideways.
 */
export function useTableOverflow() {
 const [box, scrollBox] = useState<HTMLDivElement | null>(null);
 const [isOverflowing, setIsOverflowing] = useState(false);

 useEffect(() => {
  if (!box || typeof ResizeObserver === 'undefined') {
   return;
  }
  const measure = () => {
   setIsOverflowing(box.scrollWidth - box.clientWidth > 1);
   box.style.setProperty('--table-pin-action-width', `${box.querySelector<HTMLElement>('th.table-pin-action')?.offsetWidth ?? 0}px`);
  };
  measure();
  const observer = new ResizeObserver(measure);
  observer.observe(box);
  const table = box.querySelector('table');
  if (table) {
   observer.observe(table);
  }
  return () => observer.disconnect();
 }, [box]);

 return { scrollBox, isOverflowing };
}

function columnHeading(col: TableColumn<any>): string {
 return col.title ?? col.header ?? '';
}

function columnAlign(col: TableColumn<any>): 'left' | 'center' | 'right' {
 return col.align ?? (col.money ? 'right' : 'left');
}

function isActionColumn(col: TableColumn<any>): boolean {
 return col.key === 'action' || col.key === 'actions' || columnHeading(col).trim() === '';
}

function sumColumn<T extends Row>(rows: T[], col: TableColumn<T>): number {
 const cents = rows.reduce((sum, row) => sum + Math.round(toAmount(getSortValue(row, col.key, col)) * 100), 0);
 return cents / 100;
}

/** Plain text of a rendered cell: walks elements and their children, reads Badge statuses. */
function nodeText(node: React.ReactNode, translate: TranslateFn): string {
 if (node == null || typeof node === 'boolean') return '';
 if (typeof node === 'string' || typeof node === 'number' || typeof node === 'bigint') return String(node);
 if (Array.isArray(node)) return node.map(item => nodeText(item, translate)).filter(Boolean).join(' ');
 if (React.isValidElement(node)) {
  const props = node.props as { children?: React.ReactNode; status?: unknown };
  if (node.type === Badge && typeof props.status === 'string') return label(props.status, translate);
  return nodeText(props.children, translate);
 }
 return '';
}

function cellText<T extends Row>(col: TableColumn<T>, row: T, index: number, translate: TranslateFn, forCsv: boolean): string {
 if (col.exportValue) {
  const value = col.exportValue(row);
  return value == null ? '' : String(value);
 }
 const raw = getSortValue(row, col.key, col);
 const hasRaw = raw != null && raw !== '' && typeof raw !== 'object';
 if (forCsv && hasRaw && (col.money || col.total)) {
  return col.money ? toAmount(raw).toFixed(2) : String(toAmount(raw));
 }
 const text = col.render ? nodeText(col.render(row, index), translate).replace(/\s+/g, ' ').trim() : '';
 if (text) return text;
 if (hasRaw) return col.money && !forCsv ? money(raw) : String(raw);
 return forCsv ? '' : '—';
}

/** Quote a CSV field and neutralise spreadsheet formulas (= + - @ at the start). */
function csvCell(value: string): string {
 const isPlainNumber = /^-?\d+(\.\d+)?$/.test(value);
 const safe = !isPlainNumber && /^(\s*[=+\-@]|[\t\r])/.test(value) ? `'${value}` : value;
 return `"${safe.replace(/"/g, '""')}"`;
}

function csvFileName(name?: string): string {
 const base = (name ?? 'report').trim().replace(/[\\/:*?"<>|\s]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '') || 'report';
 return `${base}-${businessTodayIso()}.csv`;
}

function downloadCsv(content: string, name?: string): void {
 const blob = new Blob(['﻿', content], { type: 'text/csv;charset=utf-8' });
 const url = URL.createObjectURL(blob);
 const link = document.createElement('a');
 link.href = url;
 link.download = csvFileName(name);
 link.style.display = 'none';
 document.body.appendChild(link);
 link.click();
 link.remove();
 setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function escapeHtml(text: string): string {
 return text.replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch] as string));
}

type PrintReport = {
 title: string;
 subtitle: string;
 headings: string[];
 aligns: string[];
 body: string[][];
 footer: string[] | null;
};

/** Print only this table: a clean copy goes into a hidden frame, so the page around it is left out. */
function printReport({ title, subtitle, headings, aligns, body, footer }: PrintReport): void {
 document.querySelectorAll('iframe[data-table-print]').forEach(frame => frame.remove());
 const cell = (tag: 'th' | 'td', text: string, index: number) => `<${tag} style="text-align:${aligns[index] ?? 'left'}">${escapeHtml(text)}</${tag}>`;
 const row = (cells: string[], tag: 'th' | 'td') => `<tr>${cells.map((text, index) => cell(tag, text, index)).join('')}</tr>`;
 const styles = [
  `@page{size:${headings.length > 7 ? 'landscape' : 'portrait'};margin:12mm}`,
  '*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}',
  "body{margin:0;color:#111827;font:11px/1.4 'Noto Sans Bengali','Nirmala UI','Instrument Sans',system-ui,-apple-system,'Segoe UI',sans-serif}",
  'h1{font-size:16px;margin:0 0 4px}',
  '.meta{margin:0 0 12px;color:#4b5563;font-size:10px}',
  'table{width:100%;border-collapse:collapse}',
  'th,td{border:1px solid #d1d5db;padding:5px 7px;vertical-align:top;font-variant-numeric:tabular-nums}',
  'th{background:#f3f4f6;font-weight:600}',
  'thead{display:table-header-group}',
  'tr{break-inside:avoid}',
  'tfoot td{font-weight:700;background:#f9fafb;border-top:2px solid #111827}',
 ].join('');
 const lang = escapeHtml(document.documentElement.lang || 'en');
 const html = `<!DOCTYPE html><html lang="${lang}"><head><meta charset="utf-8"><title>${escapeHtml(title)}</title><style>${styles}</style></head><body>`
  + `<h1>${escapeHtml(title)}</h1><p class="meta">${escapeHtml(subtitle)}</p>`
  + `<table><thead>${row(headings, 'th')}</thead><tbody>${body.map(cells => row(cells, 'td')).join('')}</tbody>`
  + `${footer ? `<tfoot>${row(footer, 'td')}</tfoot>` : ''}</table></body></html>`;
 const frame = document.createElement('iframe');
 frame.setAttribute('data-table-print', '');
 frame.setAttribute('aria-hidden', 'true');
 frame.tabIndex = -1;
 frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
 frame.onload = () => {
  const win = frame.contentWindow;
  if (!win) return;
  win.addEventListener('afterprint', () => setTimeout(() => frame.remove(), 0));
  win.focus();
  win.print();
 };
 frame.srcdoc = html;
 document.body.appendChild(frame);
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
 searchable = true,
 totalCount,
 totalLabel,
 exportable,
 printable,
 filename,
 printTitle,
 stickyHeader = true,
 phoneColumns,
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
 const [expandedRows, setExpandedRows] = useState<Set<string>>(() => new Set());
 const { scrollBox, isOverflowing } = useTableOverflow();
 const hasPhoneColumns = Boolean(phoneColumns?.length);
 const isPhoneExtra = (c: TableColumn<T>) => hasPhoneColumns && !phoneColumns!.includes(c.key);
 const extraPhoneColumns = columns.filter(isPhoneExtra);
 const toggleExpandedRow = (key: string) => setExpandedRows(current => {
  const next = new Set(current);
  if (!next.delete(key)) {
   next.add(key);
  }
  return next;
 });
 const canExport = exportable ?? Boolean(filename);
 const canPrint = printable ?? canExport;

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

 const hasFooter = sorted.length > 0 && columns.some(c => c.total || c.footer);
 const totals = useMemo(() => {
  const sums: Record<string, number> = {};
  if (!hasFooter) return sums;
  for (const c of columns) {
   if (c.total && !c.footer) sums[c.key] = sumColumn(sorted, c);
  }
  return sums;
 }, [hasFooter, columns, sorted]);
 const footerLabel = totalLabel ?? translate('portal', 'table_total');
 const footerCell = (c: TableColumn<T>, index: number, labelIndex: number): React.ReactNode => {
  if (c.footer) return c.footer(sorted);
  if (c.total) return c.money ? money(totals[c.key]) : number(totals[c.key]);
  return index === labelIndex ? footerLabel : null;
 };
 const labelIndexOf = (cols: TableColumn<T>[]) => cols.findIndex(c => !c.total && !c.footer);
 const footerLabelIndex = labelIndexOf(columns);

 const reportColumns = columns.filter(c => c.exportable !== false && !isActionColumn(c));
 const reportFooter = (forCsv: boolean): string[] | null => {
  if (!hasFooter) return null;
  const labelIndex = labelIndexOf(reportColumns);
  return reportColumns.map((c, index) => {
   if (c.footer) return nodeText(c.footer(sorted), translate).replace(/\s+/g, ' ').trim();
   if (c.total) {
    if (forCsv) return c.money ? totals[c.key].toFixed(2) : String(totals[c.key]);
    return c.money ? money(totals[c.key]) : number(totals[c.key]);
   }
   return index === labelIndex ? footerLabel : '';
  });
 };
 const reportTitle = () => printTitle ?? (typeof document !== 'undefined' ? document.title : '');
 const countLine = translate('portal', 'table_showing_count', {
  shown: number(sorted.length),
  total: number(totalCount ?? list.length),
 });

 const exportCsv = () => {
  const lines = [reportColumns.map(c => csvCell(columnHeading(c)))];
  sorted.forEach((row, index) => lines.push(reportColumns.map(c => csvCell(cellText(c, row, index, translate, true)))));
  const footer = reportFooter(true);
  if (footer) lines.push(footer.map(csvCell));
  downloadCsv(lines.map(line => line.join(',')).join('\r\n'), filename);
 };

 const printTable = () => {
  printReport({
   title: reportTitle(),
   subtitle: `${translate('portal', 'table_printed_on', { date: businessDateTime(new Date().toISOString()) })} · ${countLine}`,
   headings: reportColumns.map(columnHeading),
   aligns: reportColumns.map(columnAlign),
   body: sorted.map((row, index) => reportColumns.map(c => cellText(c, row, index, translate, false))),
   footer: reportFooter(false),
  });
 };

 const lastColumn = columns[columns.length - 1];
 const pinnedActionKey = lastColumn && isActionColumn(lastColumn) ? lastColumn.key : null;
 const statusCandidate = columns[columns.length - (pinnedActionKey ? 2 : 1)];
 const pinnedStatusKey = statusCandidate && STATUS_COLUMN_KEYS.has(statusCandidate.key) ? statusCandidate.key : null;
 const pinClass = (c: TableColumn<T>) => (c.key === pinnedActionKey ? 'table-pin-action' : c.key === pinnedStatusKey ? 'table-pin-status' : '');
 const cellClass = (c: TableColumn<T>) => [c.className, c.money || c.total || columnAlign(c) === 'right' ? 'is-num' : '', isPhoneExtra(c) ? 'is-phone-extra' : '', pinClass(c)].filter(Boolean).join(' ') || undefined;

 const body = pagedRows.length ? pagedRows.map((r, i) => {
  const rowKey = String((r as any).id ?? (r as any).row_key ?? i);
  const expanded = expandedRows.has(rowKey);
  return <React.Fragment key={rowKey}>
  <tr
   onClick={onRowClick ? () => onRowClick(r, i) : undefined}
   className={rowClassName ? rowClassName(r, i) : undefined}
   style={rowStyle ? rowStyle(r, i) : (onRowClick ? { cursor: 'pointer' } : undefined)}
  >
   {hasPhoneColumns && (
    <td className="table-phone-toggle">
     <button
      type="button"
      className="icon-button"
      aria-expanded={expanded}
      aria-label={translate('website_admin', expanded ? 'table_show_less' : 'table_show_more')}
      onClick={event => { event.stopPropagation(); toggleExpandedRow(rowKey); }}
     >
      {expanded ? <ChevronUp size={16} aria-hidden="true" /> : <ChevronDown size={16} aria-hidden="true" />}
     </button>
    </td>
   )}
   {columns.map(c => (
    <td
     key={c.key}
     data-col={c.key}
     style={{ textAlign: columnAlign(c) }}
     className={cellClass(c)}
    >
     {c.render ? c.render(r, i) : String((r as any)[c.key] ?? '—')}
    </td>
   ))}
  </tr>
  {hasPhoneColumns && expanded && (
   <tr className="table-phone-detail">
    <td colSpan={columns.length + 1}>
     <dl>
      {extraPhoneColumns.map(c => (
       <div key={c.key}>
        <dt>{columnHeading(c)}</dt>
        <dd>{c.render ? c.render(r, i) : String((r as any)[c.key] ?? '—')}</dd>
       </div>
      ))}
     </dl>
    </td>
   </tr>
  )}
  </React.Fragment>;
 }) : (
  <tr className="table-empty-row">
   <td colSpan={columns.length + (hasPhoneColumns ? 1 : 0)}>
    <Empty
     title={list.length ? translate('portal', 'table_no_matches') : emptyTitle}
     body={list.length ? translate('portal', 'table_no_matches_hint') : emptyBody}
    />
   </td>
  </tr>
 );

 const showToolbar = tools || canExport || canPrint;
 const isPartnerPortal = ['dealer', 'subdealer'].includes(String(usePage<any>().props.portal));
 const sticky = stickyHeader && pagedRows.length > STICKY_HEADER_MIN_ROWS;

 return (
  <div className="table-block">
   {showToolbar && (
    <div className="table-tools">
     {tools && searchable && (
      <input
       type="search"
       value={query}
       onChange={e => { setQuery(e.target.value); setCurrentPage(1); }}
       placeholder={searchPlaceholder ?? translate('portal', 'table_search')}
       aria-label={searchPlaceholder ?? translate('portal', 'table_search')}
      />
     )}
     {tools && searchable && statuses.length > 1 && (
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
     {tools && filters?.map(f => (
      <AutoSelect
       key={f.key}
       className="ss-filter"
       ariaLabel={f.label}
       value={customFilters[f.key] ?? ''}
       onChange={value => {
        setCustomFilters(prev => ({ ...prev, [f.key]: value }));
        setCurrentPage(1);
       }}
       emptyLabel={f.label}
       options={f.options.map(opt => ({ value: opt.value, label: opt.label }))}
      />
     ))}
     {tools && hasActiveFilters && (
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
     {tools && extraTools}
     <span className="table-count-badge">{countLine}</span>
     {(canExport || canPrint) && (
      <span className="table-report-actions">
       {canExport && (
        <button type="button" className="button small table-report-button" onClick={exportCsv} disabled={!sorted.length}>
         <Download size={13} aria-hidden="true" />
         <span>{isPartnerPortal ? translate('partner_hub', 'export_excel') : translate('portal', 'export_csv')}</span>
        </button>
       )}
       {canPrint && (
        <button type="button" className="button small table-report-button" onClick={printTable} disabled={!sorted.length}>
         <Printer size={13} aria-hidden="true" />
         <span>{translate('portal', 'table_print')}</span>
        </button>
       )}
      </span>
     )}
    </div>
   )}
   {isOverflowing && (
    <p className="table-scroll-hint">
     <ArrowLeftRight size={14} aria-hidden="true" />
     <span>{translate('portal', 'table_scroll_hint')}</span>
    </p>
   )}
   <div ref={scrollBox} className={`table-wrap${sticky ? ' is-sticky' : ''}${isOverflowing ? ' is-overflowing' : ''}`}>
    <table>
     <thead>
      <tr>
       {hasPhoneColumns && <th className="table-phone-toggle" scope="col" aria-hidden="true" />}
       {columns.map(c => {
        const colHeading = columnHeading(c);
        const isSortable = c.sortable ?? (c.key !== 'action' && c.key !== 'actions' && (colHeading.trim() !== ''));
        const isSorted = sortKey === c.key;
        const align = columnAlign(c);
        return (
         <th
          key={c.key}
          data-col={c.key}
          scope="col"
          style={{
           textAlign: align,
           width: c.width,
           cursor: isSortable ? 'pointer' : 'default',
           userSelect: isSortable ? 'none' : 'auto',
          }}
          className={`${isSortable ? 'table-th-sortable' : ''} ${isSorted ? 'is-sorted' : ''} ${c.money || align === 'right' ? 'is-num' : ''} ${c.className ?? ''} ${isPhoneExtra(c) ? 'is-phone-extra' : ''} ${pinClass(c)}`}
          onClick={() => handleHeaderClick(c.key, isSortable)}
          aria-sort={isSorted ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'}
         >
          {isSortable ? (
           <span
            className="table-sort-trigger"
            style={{
             justifyContent: align === 'right' ? 'flex-end' : align === 'center' ? 'center' : 'flex-start',
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
     {hasFooter && (
      <tfoot>
       <tr className="table-total-row">
        {hasPhoneColumns && <td className="table-phone-toggle" />}
        {columns.map((c, index) => (
         <td key={c.key} style={{ textAlign: columnAlign(c) }} className={cellClass(c)}>
          {footerCell(c, index, footerLabelIndex)}
         </td>
        ))}
       </tr>
      </tfoot>
     )}
    </table>
   </div>
   {activePageSize && totalPages > 1 && (
    <div className="table-client-pagination">
     <span>
      {translate('portal', 'pagination_summary', {
       page: number(currentPage),
       pages: number(totalPages),
       total: number(sorted.length),
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
      <span>{number(currentPage)} / {number(totalPages)}</span>
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
        <option key={sz} value={sz}>{number(sz)} / page</option>
       ))}
      </select>
     </div>
    </div>
   )}
  </div>
 );
}

type PageNumber = number | 'gap';

/** Page numbers to show: first, last, and the pages around the current one, with gaps in between. */
function pageWindow(current: number, last: number): PageNumber[] {
 const wanted = new Set<number>([1, last, current - 1, current, current + 1]);
 if (current <= 3) [2, 3, 4].forEach(p => wanted.add(p));
 if (current >= last - 2) [last - 1, last - 2, last - 3].forEach(p => wanted.add(p));
 const pages = [...wanted].filter(p => p >= 1 && p <= last).sort((a, b) => a - b);
 const result: PageNumber[] = [];
 let previous = 0;
 for (const p of pages) {
  if (p - previous === 2) result.push(previous + 1);
  else if (p - previous > 2) result.push('gap');
  result.push(p);
  previous = p;
 }
 return result;
}

/** Name of the page query parameter ("page", or "page_0" when a screen has several paginated tables). */
function paginatorPageName(page: Row): string {
 const base = 'http://paginator.local';
 try {
  const first = new URL(String(page.first_page_url ?? ''), base).searchParams;
  const last = new URL(String(page.last_page_url ?? ''), base).searchParams;
  for (const [key, value] of last) {
   if (value === String(page.last_page) && first.get(key) === '1') return key;
  }
 } catch {
  // fall back to Laravel's default
 }
 return 'page';
}

/**
 * Link to another page of the same list, keeping the filters that are on screen
 * even when the server paginator was built without withQueryString().
 */
function paginatorHref(target: string, currentUrl: string): string {
 const base = 'http://paginator.local';
 const next = new URL(target, base);
 const current = new URL(currentUrl || '/', base);
 const params = next.pathname === current.pathname ? new URLSearchParams(current.search) : new URLSearchParams();
 next.searchParams.forEach((value, key) => params.set(key, value));
 const search = params.toString();
 return `${next.pathname}${search ? `?${search}` : ''}`;
}

export function Pagination({page}:{page?:Row}){
 const translate = useT();
 const {url: currentUrl} = usePage();
 if (!page) return null;
 const current = Number(page.current_page ?? 1);
 const last = page.last_page == null ? null : Number(page.last_page);
 if (last != null ? last <= 1 : !page.prev_page_url && !page.next_page_url) return null;

 const links: Row[] = Array.isArray(page.links) ? page.links : [];
 const linkFor = new Map(links.filter(link => link.url && /^\d+$/.test(String(link.label).trim())).map(link => [Number(String(link.label).trim()), String(link.url)]));
 const pageName = paginatorPageName(page);
 const hrefFor = (target: number): string | null => {
  const known = linkFor.get(target);
  if (known) return paginatorHref(known, currentUrl);
  if (!page.first_page_url) return null;
  const built = new URL(String(page.first_page_url), 'http://paginator.local');
  built.searchParams.set(pageName, String(target));
  return paginatorHref(`${built.pathname}${built.search}`, currentUrl);
 };
 const prevHref = page.prev_page_url ? paginatorHref(String(page.prev_page_url), currentUrl) : null;
 const nextHref = page.next_page_url ? paginatorHref(String(page.next_page_url), currentUrl) : null;
 const summary = last != null && page.total != null
  ? translate('portal', 'pagination_summary', { page: number(current), pages: number(last), total: number(page.total) })
  : translate('portal', 'pagination_page', { page: number(current) });

 return (
  <nav className="pagination" aria-label={translate('portal', 'pagination_label')}>
   <span className="pagination-summary">{summary}</span>
   <div className="pagination-links">
    {prevHref
     ? <Link className="page-link page-step" href={prevHref} rel="prev">{translate('portal', 'pagination_previous')}</Link>
     : <span className="page-link page-step is-disabled" aria-disabled="true">{translate('portal', 'pagination_previous')}</span>}
    {last != null && pageWindow(current, last).map((item, index) => {
     if (item === 'gap') return <span key={`gap-${index}`} className="page-gap" aria-hidden="true">…</span>;
     if (item === current) return <span key={item} className="page-link is-current" aria-current="page">{number(item)}</span>;
     const href = hrefFor(item);
     return href
      ? <Link key={item} className="page-link" href={href} aria-label={translate('portal', 'pagination_page', { page: number(item) })}>{number(item)}</Link>
      : null;
    })}
    {nextHref
     ? <Link className="page-link page-step" href={nextHref} rel="next">{translate('portal', 'pagination_next')}</Link>
     : <span className="page-link page-step is-disabled" aria-disabled="true">{translate('portal', 'pagination_next')}</span>}
   </div>
  </nav>
 );
}
/** How long the closing fade of a dialog lasts; keep in step with portalModalOut in dashboard CSS. */
const MODAL_CLOSE_MS=160;
/** A dialog. `drawer` opens one row from the right (full screen on a phone), with `badge` beside the title and `footer` for its buttons; `wide` is for permissions. */
export function Modal({title,onClose,children,variant='dialog',badge,footer}:{title:string,onClose:()=>void,children:React.ReactNode,variant?:'dialog'|'drawer'|'wide',badge?:React.ReactNode,footer?:React.ReactNode}){
 const translate=useT();
 const ref=useRef<HTMLDialogElement>(null);
 const [closing,setClosing]=useState(false);
 const onCloseRef=useRef(onClose);
 onCloseRef.current=onClose;
 useEffect(()=>{
  const dialog=ref.current;
  dialog?.showModal();
  const previous=document.activeElement as HTMLElement|null;
  dialog?.querySelector<HTMLElement>('button, [href], input, select, textarea')?.focus();
  return ()=>{dialog?.close();previous?.focus?.()};
 },[]);
 useEffect(()=>{
  if(!closing){
   return;
  }
  const timer=window.setTimeout(()=>onCloseRef.current(),MODAL_CLOSE_MS);
  return ()=>window.clearTimeout(timer);
 },[closing]);
 /** Close button, Esc and a click outside fade the dialog out first; reduced motion closes at once. */
 const requestClose=()=>{
  if(closing){
   return;
  }
  if(window.matchMedia?.('(prefers-reduced-motion: reduce)').matches){
   onClose();
   return;
  }
  setClosing(true);
 };
 return (
  <dialog
   ref={ref}
   className={`portal-modal${variant==='dialog'?'':` portal-modal-${variant}`}${closing?' is-closing':''}`}
   onCancel={e=>{e.preventDefault();requestClose()}}
   onClick={e=>{if(e.target===ref.current)requestClose()}}
  >
   <div className="modal-heading">
    <h2>{title}</h2>
    {badge&&<span className="modal-badge">{badge}</span>}
    <button type="button" aria-label={translate('portal','close_dialog')} title={translate('portal','close_dialog')} className="icon-button" onClick={requestClose}><X/></button>
   </div>
   <div className="modal-body">{children}</div>
   {footer&&<div className="modal-footer">{footer}</div>}
  </dialog>
 );
}
export type Field={name:string,label:string,type?:string,options?:{value:string,label:string}[],required?:boolean,value?:any,warnPast?:boolean,accept?:string,hint?:string};
export function ActionForm({action,fields,defaults={},submit,method='post',onDone}:{action:string,fields:Field[],defaults?:Row,submit?:string,method?:'post'|'patch',onDone?:()=>void}){
 const translate=useT();
 const {portal,staffActions}=usePage<any>().props;
 const initial:Row={...Object.fromEntries(fields.map(f=>[f.name,f.value??''])),...defaults}; const f=useForm<Row>(initial);
 if((portal==='manage'||portal==='sadmin')&&!actionAllowed(action,staffActions,method))return null;
 const pastField=fields.find(field=>field.warnPast&&isPastBusinessDate(String(f.data[field.name]??'')));
 return <form className="action-form" onSubmit={e=>{e.preventDefault();if(pastField){f.setError(pastField.name,translate('portal','err_stock_expiry_past'));return;}f.clearErrors();f.submit(method,action,{preserveScroll:true,onSuccess:()=>onDone?.()})}}>{fields.map(field=>{const past=field.warnPast&&isPastBusinessDate(String(f.data[field.name]??''));const message=past?translate('portal','err_stock_expiry_past'):(f.errors[field.name]?String(f.errors[field.name]):'');return <label key={field.name}>{field.label}{field.type==='textarea'?<textarea rows={4} value={f.data[field.name]} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>:field.type==='file'?<input type="file" accept={field.accept} required={field.required} onChange={e=>f.setData(field.name,e.target.files?.[0]??null)}/>:field.type==='date'?<DateInput value={f.data[field.name]} required={field.required} min={field.warnPast?businessTodayIso():undefined} onChange={e=>f.setData(field.name,e.target.value)}/>:field.options?<AutoSelect value={String(f.data[field.name])} required={field.required} onChange={value=>f.setData(field.name,value)} emptyLabel={translate('portal','select_placeholder')} options={field.options.map(o=>({value:o.value,label:o.label}))}/>:<input type={field.type??'text'} value={f.data[field.name]} step={field.type==='number'?'any':undefined} required={field.required} onChange={e=>f.setData(field.name,e.target.value)}/>} {field.hint&&<small className="field-hint">{field.hint}</small>}{message&&<span className="field-error" role="alert">{message}</span>}</label>})}{/* A refusal about something with no box here (such as an email already in use) still shows inside the form. */}<FormErrors errors={f.errors} besideFields={fields.map(field=>field.name)}/><button disabled={f.processing||Boolean(pastField)} className="button dark">{f.processing?translate('portal','saving'):(submit??translate('portal','save_changes'))}<ArrowUpRight size={17}/></button></form>
}
