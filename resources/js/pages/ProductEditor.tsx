import {useState, useEffect, useMemo} from 'react';
import {Head,Link,router,useForm,usePage} from '@inertiajs/react';
import Shell from '../components/Shell';
import {type Row} from '../components/UI';
import {useT} from '../shared/i18n/t';

type CatalogSubcategory = {id:number;name:string};
type CatalogCategory = {id:number;name:string;subcategories:CatalogSubcategory[]};
type CatalogUnit = {id:number;name:string;code:string};

export default function ProductEditor({
 product:p,
 catalog_categories=[],
 catalog_units=[],
 supports_product_expiry=true,
}:{
 product:Row|null;
 catalog_categories?:CatalogCategory[];
 catalog_units?:CatalogUnit[];
 categories?:string[];
 sub_categories?:string[];
 supports_product_expiry?:boolean;
}){
 const translate=useT();
 const {auth,staffActions,publicBase}=usePage<any>().props;const caps:string[]=auth.capabilities;const catalog=caps.includes('catalog')&&(staffActions?.catalog?.[p?'edit':'create']??true),pricing=caps.includes('pricing')&&(staffActions?.pricing?.[p?'edit':'create']??true),inventory=caps.includes('inventory')&&(staffActions?.inventory?.[p?'edit':'create']??true),cost=caps.includes('cost_read');const editable=catalog||inventory||pricing;
 const isOwner = Boolean(auth?.user?.role && ['super_admin', 'md', 'chairman'].includes(auth.user.role));
 const initialMrp = parseFloat(p?.mrp ?? '0') || 0;
 const initialDealer = parseFloat(p?.dealer_price ?? '0') || 0;
 const initialPct = initialMrp > 0 && initialDealer > 0 && initialDealer <= initialMrp ? String(Math.round(((initialMrp - initialDealer) / initialMrp) * 100)) : '';
 const [discountPct, setDiscountPct] = useState<string>(initialPct);

 const initialSubDealer = parseFloat(p?.sub_dealer_price ?? '0') || 0;
 const initialSubDealerPct = initialMrp > 0 && initialSubDealer > 0 && initialSubDealer <= initialMrp ? String(Math.round(((initialMrp - initialSubDealer) / initialMrp) * 100)) : '';
 const [subDealerDiscountPct, setSubDealerDiscountPct] = useState<string>(initialSubDealerPct);

 const form=useForm<Row>({
  name:p?.name??'',
  sku:p?.sku??'',
  category:p?.category??'',
  sub_category:p?.sub_category??'',
  product_category_id:p?.product_category_id?String(p.product_category_id):'',
  product_subcategory_id:p?.product_subcategory_id?String(p.product_subcategory_id):'',
  strength:p?.strength??'',
  description:p?.description??'',
  unit:p?.unit??'',
  product_unit_id:p?.product_unit_id?String(p.product_unit_id):'',
  expires_on:p?.expires_on??'',
  mrp:p?.mrp??'',
  dealer_price:p?.dealer_price??'',
  dealer_discount_pct:initialPct,
  sub_dealer_price:p?.sub_dealer_price??'',
  sub_dealer_discount_pct:initialSubDealerPct,
  cost_price:p?.cost_price??'',
  low_stock_at:p?.low_stock_at??10,
  active:p?.active?'1':'0',
  featured:p?.featured?'1':'0',
  image:null as File|null,
  remove_image:false,
  video_source:p?.video_source??'none',
  youtube_url:p?.youtube_url??'',
  vimeo_url:p?.vimeo_url??'',
  external_video_url:p?.external_video_url??'',
  video:null as File|null,
  reason:'',
  version:p?.version??'',
  _method:p?'patch':'post',
 });
 useEffect(()=>{form.setData('version',p?.version??'');},[p?.version]);

 const selectedCategory = useMemo(
  () => catalog_categories.find(c => String(c.id) === String(form.data.product_category_id)),
  [catalog_categories, form.data.product_category_id],
 );
 const subcategoryOptions = selectedCategory?.subcategories ?? [];
 const selectedCategoryName = selectedCategory?.name ?? form.data.category ?? '';
 const selectedSubcategoryName = subcategoryOptions.find(s => String(s.id) === String(form.data.product_subcategory_id))?.name
  ?? form.data.sub_category
  ?? '';
 const selectedUnitName = catalog_units.find(u => String(u.id) === String(form.data.product_unit_id))?.name
  ?? form.data.unit
  ?? '';

 const setCategorySelection = (categoryId: string) => {
  const next = catalog_categories.find(c => String(c.id) === categoryId);
  form.setData((data: Row) => ({
   ...data,
   product_category_id: categoryId,
   product_subcategory_id: '',
   category: next?.name ?? '',
   sub_category: '',
  }));
 };

 const setSubcategorySelection = (subcategoryId: string) => {
  const next = subcategoryOptions.find(s => String(s.id) === subcategoryId);
  form.setData((data: Row) => ({
   ...data,
   product_subcategory_id: subcategoryId,
   sub_category: next?.name ?? '',
   category: selectedCategoryName || data.category,
  }));
 };

 const setUnitSelection = (unitId: string) => {
  const next = catalog_units.find(u => String(u.id) === unitId);
  form.setData((data: Row) => ({
   ...data,
   product_unit_id: unitId,
   unit: next?.name ?? '',
  }));
 };

 const handleMrpChange = (val: string) => {
  form.setData('mrp', val);
  const m = parseFloat(val);
  const d = parseFloat(discountPct);
  if (!isNaN(m) && m > 0 && !isNaN(d) && d >= 0 && d <= 100) {
   form.setData('dealer_price', (m * (1 - (d / 100))).toFixed(2));
  }
  const sd = parseFloat(subDealerDiscountPct);
  if (!isNaN(m) && m > 0 && !isNaN(sd) && sd >= 0 && sd <= 100) {
   form.setData('sub_dealer_price', (m * (1 - (sd / 100))).toFixed(2));
  }
 };

 const handleDiscountChange = (val: string) => {
  setDiscountPct(val);
  form.setData('dealer_discount_pct', val);
  const m = parseFloat(form.data.mrp);
  const d = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(d) && d >= 0 && d <= 100) {
   form.setData('dealer_price', (m * (1 - (d / 100))).toFixed(2));
  }
 };

 const handleDealerPriceChange = (val: string) => {
  form.setData('dealer_price', val);
  const m = parseFloat(form.data.mrp);
  const dp = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(dp) && dp >= 0 && dp <= m) {
   const calculated = String(Math.round(((m - dp) / m) * 100));
   setDiscountPct(calculated);
   form.setData('dealer_discount_pct', calculated);
  }
 };

 const handleSubDealerDiscountChange = (val: string) => {
  setSubDealerDiscountPct(val);
  form.setData('sub_dealer_discount_pct', val);
  const m = parseFloat(form.data.mrp);
  const sd = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(sd) && sd >= 0 && sd <= 100) {
   form.setData('sub_dealer_price', (m * (1 - (sd / 100))).toFixed(2));
  }
 };

 const handleSubDealerPriceChange = (val: string) => {
  form.setData('sub_dealer_price', val);
  const m = parseFloat(form.data.mrp);
  const sdp = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(sdp) && sdp >= 0 && sdp <= m) {
   const calculated = String(Math.round(((m - sdp) / m) * 100));
   setSubDealerDiscountPct(calculated);
   form.setData('sub_dealer_discount_pct', calculated);
  }
 };

 const mrpNum = parseFloat(form.data.mrp) || 0;
 const dealerPriceNum = parseFloat(form.data.dealer_price) || 0;
 const savingsNum = mrpNum > dealerPriceNum && dealerPriceNum > 0 ? mrpNum - dealerPriceNum : 0;
 const computedPct = mrpNum > 0 && dealerPriceNum > 0 && dealerPriceNum <= mrpNum ? Math.round(((mrpNum - dealerPriceNum) / mrpNum) * 100) : (parseFloat(discountPct) || 0);

 const subDealerPriceNum = parseFloat(form.data.sub_dealer_price) || 0;
 const subSavingsNum = mrpNum > subDealerPriceNum && subDealerPriceNum > 0 ? mrpNum - subDealerPriceNum : 0;
 const subComputedPct = mrpNum > 0 && subDealerPriceNum > 0 && subDealerPriceNum <= mrpNum ? Math.round(((mrpNum - subDealerPriceNum) / mrpNum) * 100) : (parseFloat(subDealerDiscountPct) || 0);

 const generateSku = () => {
  const prefix = selectedCategoryName ? selectedCategoryName.replace(/[^A-Za-z0-9]/g, '').slice(0, 3).toUpperCase() : 'PRD';
  const namePart = form.data.name ? form.data.name.replace(/[^A-Za-z0-9]/g, '').slice(0, 4).toUpperCase() : 'ITEM';
  const rand = Math.floor(1000 + Math.random() * 9000);
  form.setData('sku', `${prefix || 'PRD'}-${namePart || 'ITEM'}-${rand}`);
 };

 function save(event:React.FormEvent){event.preventDefault();form.transform(data=>{const payload:Row={reason:data.reason,version:data.version,_method:p?'patch':'post'};if(catalog){for(const key of ['name','sku','category','sub_category','strength','description','unit','active','featured','image','remove_image','video_source','youtube_url','vimeo_url','external_video_url','video','product_category_id','product_subcategory_id','product_unit_id'])payload[key]=data[key];if(supports_product_expiry)payload.expires_on=data.expires_on||null;payload.category=selectedCategoryName||data.category;payload.sub_category=selectedSubcategoryName||data.sub_category;payload.unit=selectedUnitName||data.unit;}if(pricing)for(const key of ['mrp','dealer_price','dealer_discount_pct','sub_dealer_price','sub_dealer_discount_pct'])payload[key]=data[key];if(pricing&&cost)payload.cost_price=data.cost_price;if(inventory)payload.low_stock_at=data.low_stock_at;return payload;});form.post(p?`/commands/products/${p.id}`:'/commands/products',{forceFormData:true,onSuccess:()=>form.reset('reason','image','video','remove_image')});}
 const stockLabel = p ? (typeof p.stock === 'number' ? translate('portal','pe_units_in_stock',{count:String(p.stock)}) : (p.stock_status === 'out_of_stock' ? translate('portal','pe_awaiting_stock') : (p.stock_status === 'low_stock' ? translate('portal','pe_low_stock') : translate('portal','pe_in_stock')))) : '';
 const inStock = p ? (typeof p.stock === 'number' ? p.stock > 0 : p.stock_status !== 'out_of_stock') : false;
 return <Shell section="catalog"><Head title={p?translate('portal','pe_title_details'):translate('portal','pe_title_add')}/><Link href="/catalog" className="text-link">{translate('portal','pe_registry')}</Link><div className="registry-heading"><div><h2>{p?.name??translate('portal','pe_heading_add')}</h2><p>{p?`${p.sku} · ${stockLabel}`:translate('portal','pe_create_hint')}</p></div><div style={{display:'flex',gap:8,alignItems:'center'}}>{p&&inventory&&<button type="button" className="button small" style={{background:inStock?'#fef2f2':'#ecfdf5',color:inStock?'#b91c1c':'#047857',borderColor:inStock?'#fecaca':'#a7f3d0',fontWeight:600,display:'inline-flex',alignItems:'center',gap:5}} onClick={()=>router.post(`/commands/products/${p.id}/toggle-stock`,{},{preserveScroll:true})}>{inStock?translate('portal','pe_toggle_out'):translate('portal','pe_toggle_in')}</button>}{p?.active&&<a href={`${publicBase||''}/products/${p.id}`} className="button small" target="_blank" rel="noreferrer">{translate('portal','pe_view_public')}</a>}</div></div>
 <form onSubmit={save} className="registry-layout"><div><section className="panel"><h2>{translate('portal','pe_info_title')}</h2><div className="form-grid"><label>{translate('portal','pe_name')}<input required disabled={!catalog} value={form.data.name} maxLength={150} onChange={e=>form.setData('name',e.target.value)}/></label><label><div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}><span>{translate('portal','pe_sku')}</span>{!form.data.sku&&catalog&&<button type="button" onClick={generateSku} className="text-link" style={{fontSize:11,background:'none',border:'none',color:'#006837',fontWeight:600,cursor:'pointer'}}>⚡ Auto SKU</button>}</div><input required disabled={!catalog} value={form.data.sku} maxLength={60} onChange={e=>form.setData('sku',e.target.value)}/></label><label><div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}><span>{translate('portal','pe_category')}</span><a href="/website" target="_blank" rel="noreferrer" className="text-link" style={{fontSize:11,fontWeight:500}}>{translate('portal','pe_manage_categories')}</a></div><select required disabled={!catalog} value={form.data.product_category_id} onChange={e=>setCategorySelection(e.target.value)}><option value="">{translate('portal','pe_category_select')}</option>{catalog_categories.map(c=><option key={c.id} value={String(c.id)}>{c.name}</option>)}</select>{catalog_categories.length===0&&<small className="muted">{translate('portal','pe_cms_categories_empty')}</small>}</label><label><div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}><span>{translate('portal','pe_sub_category')}</span></div><select required disabled={!catalog||!form.data.product_category_id} value={form.data.product_subcategory_id} onChange={e=>setSubcategorySelection(e.target.value)}><option value="">{form.data.product_category_id?translate('portal','pe_sub_category_select'):translate('portal','b2b_select_category_first')}</option>{subcategoryOptions.map(sc=><option key={sc.id} value={String(sc.id)}>{sc.name}</option>)}</select></label><label>{translate('portal','pe_strength')}<input disabled={!catalog} value={form.data.strength} placeholder={translate('portal','pe_strength_ph')} maxLength={100} onChange={e=>form.setData('strength',e.target.value)}/><small className="muted">{translate('portal','pe_strength_hint')}</small></label><label>{translate('portal','pe_unit')}<select required disabled={!catalog} value={form.data.product_unit_id} onChange={e=>setUnitSelection(e.target.value)}><option value="">{translate('portal','pe_unit_select')}</option>{catalog_units.map(u=><option key={u.id} value={String(u.id)}>{u.name}</option>)}</select>{catalog_units.length===0&&<small className="muted">{translate('portal','pe_cms_units_empty')}</small>}</label>{supports_product_expiry&&<label>{translate('portal','pe_expires_on')}<input type="date" disabled={!catalog} value={form.data.expires_on||''} onChange={e=>form.setData('expires_on',e.target.value)}/><small className="muted">{translate('portal','pe_expires_on_hint')}</small></label>}</div><label>{translate('portal','pe_description')}<textarea rows={5} disabled={!catalog} maxLength={3000} value={form.data.description} onChange={e=>form.setData('description',e.target.value)}/></label></section>
 <section className="panel"><h2>{translate('portal','pe_photo_title')}</h2>{p?.image_url&&<img className="registry-photo" src={p.image_url} alt={p.name}/>}<label>{translate('portal','pe_upload_photo')}<input type="file" disabled={!catalog} accept="image/jpeg,image/png,image/webp" onChange={e=>form.setData('image',e.target.files?.[0]??null)}/></label><p className="muted">{translate('portal','pe_photo_hint')}</p>{p?.image_url&&catalog&&<label className="registry-checkbox"><input type="checkbox" checked={form.data.remove_image} onChange={e=>form.setData('remove_image',e.target.checked)}/>{translate('portal','pe_remove_photo')}</label>}</section>
 <section className="panel"><h2>{translate('portal','pe_video_title')}</h2><fieldset className="registry-video-options" disabled={!catalog}><legend>{translate('portal','pe_video_source')}</legend>{[['none','pe_video_none'],['youtube','pe_video_youtube'],['vimeo','pe_video_vimeo'],['url','pe_video_direct'],['upload','pe_video_upload']].map(([value,name])=><label key={value}><input type="radio" name="video_source" value={value} checked={form.data.video_source===value} onChange={()=>{form.setData('video_source',value);form.setData('video',null);}}/>{translate('portal',name)}</label>)}</fieldset>
 {form.data.video_source==='youtube'&&<label>{translate('portal','pe_youtube_url')}<input type="url" disabled={!catalog} required value={form.data.youtube_url} placeholder="https://www.youtube.com/watch?v=..." onChange={e=>form.setData('youtube_url',e.target.value)}/></label>}
 {form.data.video_source==='vimeo'&&<label>{translate('portal','pe_vimeo_url')}<input type="url" disabled={!catalog} required value={form.data.vimeo_url} placeholder="https://vimeo.com/123456789" onChange={e=>form.setData('vimeo_url',e.target.value)}/></label>}
 {form.data.video_source==='url'&&<label>{translate('portal','pe_direct_url')}<input type="url" disabled={!catalog} required value={form.data.external_video_url} placeholder="https://media.example.com/product.mp4" onChange={e=>form.setData('external_video_url',e.target.value)}/><small>{translate('portal','pe_direct_hint')}</small></label>}
 {form.data.video_source==='upload'&&<><label>{translate('portal','pe_upload_video')}<input type="file" disabled={!catalog} required={!p?.video_url} accept="video/mp4,video/webm" onChange={e=>form.setData('video',e.target.files?.[0]??null)}/></label><p className="muted">{translate('portal','pe_upload_video_hint')}</p></>}
 {p?.video_url&&<video className="registry-video" controls preload="metadata" src={p.video_url} aria-label={translate('portal','pe_current_video')}/>}{p?.video_embed_url&&<a className="text-link" href={p.video_embed_url} target="_blank" rel="noreferrer">{translate('portal','pe_view_youtube')}</a>}</section></div>
 <div><section className="panel"><h2>{translate('portal','pe_prices_title')}</h2>
  <label>{translate('portal','pe_mrp')}<input required type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.mrp} onChange={e=>handleMrpChange(e.target.value)}/></label>
  <div style={{marginTop:8,marginBottom:8}}>
   <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}>
    <label style={{margin:0,fontSize:13,fontWeight:600,color:'#374151'}}>{translate('portal','pe_dealer_discount')} <span style={{fontSize:11,color:'#059669',fontWeight:700}}>(সর্বোচ্চ ৫০%)</span></label>
    <div style={{display:'flex',gap:4}}>
     {[20,25,30,35,40,50].map(pct=>(
      <button key={pct} type="button" disabled={!pricing} onClick={()=>{handleDiscountChange(String(pct));}} style={{padding:'2px 7px',fontSize:11,fontWeight:600,borderRadius:4,border:discountPct===String(pct)?'1px solid #059669':'1px solid #d1d5db',background:discountPct===String(pct)?'#ecfdf5':'#f9fafb',color:discountPct===String(pct)?'#047857':'#4b5563',cursor:'pointer'}}>
       {pct}%
      </button>
     ))}
    </div>
   </div>
   <input type="number" min="0" max={isOwner ? 100 : 50} step="0.5" disabled={!pricing} placeholder={translate('portal','pe_discount_ph')} value={discountPct} onChange={e=>handleDiscountChange(e.target.value)}/>
   {!isOwner && parseFloat(discountPct) > 50 && (
    <div style={{color:'#b91c1c',fontSize:11,marginTop:4,fontWeight:600}}>
     ⚠️ ডিলারদের কমিশন দেওয়ার সর্বোচ্চ সীমা ৫০%। এর বেশি কমিশন শুধুমাত্র ওনার কর্তৃক অনুমোদিত।
    </div>
   )}
  </div>
   <label>{translate('portal','pe_dealer_price_cash')}<input type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.dealer_price} onChange={e=>handleDealerPriceChange(e.target.value)}/></label>
   <div style={{background:'linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%)',border:'1px solid #a7f3d0',borderRadius:8,padding:'12px',marginTop:12,marginBottom:12}}>
    <div style={{fontSize:11,fontWeight:700,color:'#047857',textTransform:'uppercase',letterSpacing:'0.04em',marginBottom:8,display:'flex',alignItems:'center',justifyContent:'space-between'}}>
     <span>{translate('portal','pe_cash_in_title')}</span>
     <span style={{background:'#dcfce7',color:'#15803d',padding:'1px 6px',borderRadius:4,fontSize:10,fontWeight:700}}>{translate('portal','pe_discount_badge',{pct:String(computedPct)})}</span>
    </div>
    <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:8}}>
     <div style={{background:'#ffffff',padding:'8px',borderRadius:6,border:'1px solid #d1fae5'}}>
      <div style={{fontSize:11,color:'#065f46'}}>{translate('portal','pe_dealer_price')}</div>
      <div style={{fontSize:15,fontWeight:800,color:'#065f46'}}>৳ {dealerPriceNum>0?dealerPriceNum.toFixed(2):'0.00'}</div>
      <div style={{fontSize:10,color:'#047857'}}>{translate('portal','pe_collected')}</div>
     </div>
     <div style={{background:'#ffffff',padding:'8px',borderRadius:6,border:'1px solid #fef3c7'}}>
      <div style={{fontSize:11,color:'#92400e'}}>{translate('portal','pe_deducted')}</div>
      <div style={{fontSize:15,fontWeight:800,color:'#b45309'}}>৳ {savingsNum>0?savingsNum.toFixed(2):'0.00'}</div>
      <div style={{fontSize:10,color:'#b45309'}}>{translate('portal','pe_margin')}</div>
     </div>
    </div>
   </div>
   <div style={{marginTop:16,marginBottom:8,paddingTop:12,borderTop:'1px dashed #cbd5e1'}}>
    <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}>
     <label style={{margin:0,fontSize:13,fontWeight:600,color:'#1e40af'}}>{translate('portal','pe_sub_dealer_discount')} <span style={{fontSize:11,color:'#2563eb',fontWeight:700}}>(সর্বোচ্চ ৪০%)</span></label>
     <div style={{display:'flex',gap:4}}>
      {[20,25,30,35,40].map(pct=>(
       <button key={pct} type="button" disabled={!pricing} onClick={()=>{handleSubDealerDiscountChange(String(pct));}} style={{padding:'2px 7px',fontSize:11,fontWeight:600,borderRadius:4,border:subDealerDiscountPct===String(pct)?'1px solid #2563eb':'1px solid #d1d5db',background:subDealerDiscountPct===String(pct)?'#eff6ff':'#f9fafb',color:subDealerDiscountPct===String(pct)?'#1d4ed8':'#4b5563',cursor:'pointer'}}>
        {pct}%
       </button>
      ))}
     </div>
    </div>
    <input type="number" min="0" max={isOwner ? 100 : 40} step="0.5" disabled={!pricing} placeholder={translate('portal','pe_discount_ph')} value={subDealerDiscountPct} onChange={e=>handleSubDealerDiscountChange(e.target.value)}/>
    {!isOwner && parseFloat(subDealerDiscountPct) > 40 && (
     <div style={{color:'#b91c1c',fontSize:11,marginTop:4,fontWeight:600}}>
      ⚠️ সাব-ডিলারদের কমিশন দেওয়ার সর্বোচ্চ সীমা ৪০%। এর বেশি কমিশন শুধুমাত্র ওনার কর্তৃক অনুমোদিত।
     </div>
    )}
   </div>
   <label>{translate('portal','pe_sub_dealer_price_cash')}<input type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.sub_dealer_price} onChange={e=>handleSubDealerPriceChange(e.target.value)}/><small>{translate('portal','pe_sub_dealer_price_hint')}</small></label>
   <div style={{background:'linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%)',border:'1px solid #bfdbfe',borderRadius:8,padding:'12px',marginTop:12,marginBottom:12}}>
    <div style={{fontSize:11,fontWeight:700,color:'#1d4ed8',textTransform:'uppercase',letterSpacing:'0.04em',marginBottom:8,display:'flex',alignItems:'center',justifyContent:'space-between'}}>
     <span>{translate('portal','pe_sub_dealer_box_title')}</span>
     <span style={{background:'#dbeafe',color:'#1e40af',padding:'1px 6px',borderRadius:4,fontSize:10,fontWeight:700}}>{translate('portal','pe_sub_dealer_discount_badge',{pct:String(subComputedPct)})}</span>
    </div>
    <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:8}}>
     <div style={{background:'#ffffff',padding:'8px',borderRadius:6,border:'1px solid #bfdbfe'}}>
      <div style={{fontSize:11,color:'#1e40af'}}>{translate('portal','pe_sub_dealer_price')}</div>
      <div style={{fontSize:15,fontWeight:800,color:'#1e40af'}}>৳ {subDealerPriceNum>0?subDealerPriceNum.toFixed(2):'0.00'}</div>
      <div style={{fontSize:10,color:'#2563eb'}}>{translate('portal','pe_collected')}</div>
     </div>
     <div style={{background:'#ffffff',padding:'8px',borderRadius:6,border:'1px solid #fed7aa'}}>
      <div style={{fontSize:11,color:'#9a3412'}}>{translate('portal','pe_deducted')}</div>
      <div style={{fontSize:15,fontWeight:800,color:'#c2410c'}}>৳ {subSavingsNum>0?subSavingsNum.toFixed(2):'0.00'}</div>
      <div style={{fontSize:10,color:'#c2410c'}}>{translate('portal','pe_sub_dealer_margin')}</div>
     </div>
    </div>
   </div>
  {cost&&<label>{translate('portal','pe_cost')}<input type="number" min="0" step="0.01" disabled={!pricing} value={form.data.cost_price} onChange={e=>form.setData('cost_price',e.target.value)}/></label>}<label>{translate('portal','pe_low_stock_at')}<input type="number" min="0" max="100000" step="1" disabled={!inventory} value={form.data.low_stock_at} onChange={e=>form.setData('low_stock_at',e.target.value)}/></label><label>{translate('portal','pe_publication')}<select aria-label={translate('portal','pe_publication')} disabled={!catalog} value={form.data.active} onChange={e=>form.setData('active',e.target.value)}><option value="0">{translate('portal','pe_draft_hidden')}</option><option value="1">{translate('portal','pe_published')}</option></select></label><label>{translate('portal','pe_homepage')}<select aria-label={translate('portal','pe_homepage')} disabled={!catalog} value={form.data.featured} onChange={e=>form.setData('featured',e.target.value)}><option value="0">{translate('portal','pe_standard')}</option><option value="1">{translate('portal','pe_featured')}</option></select></label><p className="muted">{translate('portal','pe_stock_note')}</p></section>
 {editable&&<section className="panel"><h2>{translate('portal','pe_save_title')}</h2><label>{translate('portal','pe_change_reason')}<textarea required minLength={5} maxLength={1000} rows={3} value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>{form.progress&&<progress max={100} value={form.progress.percentage} aria-label={translate('portal','pe_upload_progress')}/>}{Object.keys(form.errors).length>0&&<div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}<button type="submit" className="button dark" disabled={form.processing}>{form.processing?translate('portal','pe_saving'):p?translate('portal','pe_save_product'):translate('portal','pe_create_product')}</button></section>}
 {p && catalog && (
  <section className="panel" style={{ marginTop: 20, borderColor: '#fecaca', background: '#fff5f5' }}>
   <h2 style={{ color: '#991b1b', fontSize: 15, margin: '0 0 8px' }}>{translate('portal','pe_delete_title')}</h2>
   <p style={{ fontSize: 12, color: '#7f1d1d', margin: '0 0 14px' }}>
    {translate('portal','pe_delete_body')}
   </p>
   <button
    type="button"
    className="button small"
    style={{ background: '#ef4444', color: '#fff', border: 'none' }}
    onClick={() => {
     const reason = window.prompt(translate('portal','delete_product_prompt',{name:String(p.name)}));
     if (reason !== null && reason.trim().length >= 3) {
      router.delete(`/commands/products/${p.id}`, {
       data: { reason },
      });
     }
    }}
   >
    {translate('portal','pe_delete_title')}
   </button>
  </section>
 )}
 </div></form></Shell>;
}


