import {useState, useEffect} from 'react';
import {Head,Link,router,useForm,usePage} from '@inertiajs/react';
import Shell from '../components/Shell';
import {money,type Row} from '../components/UI';
export default function ProductEditor({product:p,categories}:{product:Row|null;categories:string[]}){
 const {auth,staffActions}=usePage<any>().props;const caps:string[]=auth.capabilities;const catalog=caps.includes('catalog')&&(staffActions?.catalog?.[p?'edit':'create']??true),pricing=caps.includes('pricing')&&(staffActions?.pricing?.[p?'edit':'create']??true),inventory=caps.includes('inventory')&&(staffActions?.inventory?.[p?'edit':'create']??true),cost=caps.includes('cost_read');const editable=catalog||inventory||pricing;
 const [customCategoryMode, setCustomCategoryMode] = useState(false);
 const initialMrp = parseFloat(p?.mrp ?? '0') || 0;
 const initialDealer = parseFloat(p?.dealer_price ?? '0') || 0;
 const initialPct = initialMrp > 0 && initialDealer > 0 && initialDealer <= initialMrp ? String(Math.round(((initialMrp - initialDealer) / initialMrp) * 100)) : '';
 const [discountPct, setDiscountPct] = useState<string>(initialPct);
 const form=useForm<Row>({name:p?.name??'',sku:p?.sku??'',category:p?.category??'',description:p?.description??'',unit:p?.unit??'pack',mrp:p?.mrp??'',dealer_price:p?.dealer_price??'',sub_dealer_price:p?.sub_dealer_price??'',cost_price:p?.cost_price??'',low_stock_at:p?.low_stock_at??10,active:p?.active?'1':'0',featured:p?.featured?'1':'0',image:null as File|null,remove_image:false,video_source:p?.video_source??'none',youtube_url:p?.youtube_url??'',vimeo_url:p?.vimeo_url??'',external_video_url:p?.external_video_url??'',video:null as File|null,reason:'',version:p?.version??'',_method:p?'patch':'post'});
 useEffect(()=>{form.setData('version',p?.version??'');},[p?.version]);

 const handleMrpChange = (val: string) => {
  form.setData('mrp', val);
  const m = parseFloat(val);
  const d = parseFloat(discountPct);
  if (!isNaN(m) && m > 0 && !isNaN(d) && d >= 0 && d <= 100) {
   form.setData('dealer_price', (m * (1 - (d / 100))).toFixed(2));
  }
 };

 const handleDiscountChange = (val: string) => {
  setDiscountPct(val);
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
   setDiscountPct(String(Math.round(((m - dp) / m) * 100)));
  }
 };

 const mrpNum = parseFloat(form.data.mrp) || 0;
 const dealerPriceNum = parseFloat(form.data.dealer_price) || 0;
 const savingsNum = mrpNum > dealerPriceNum && dealerPriceNum > 0 ? mrpNum - dealerPriceNum : 0;
 const computedPct = mrpNum > 0 && dealerPriceNum > 0 && dealerPriceNum <= mrpNum ? Math.round(((mrpNum - dealerPriceNum) / mrpNum) * 100) : (parseFloat(discountPct) || 0);

 function save(event:React.FormEvent){event.preventDefault();form.transform(data=>{const payload:Row={reason:data.reason,version:data.version,_method:p?'patch':'post'};if(catalog)for(const key of ['name','sku','category','description','unit','active','featured','image','remove_image','video_source','youtube_url','vimeo_url','external_video_url','video'])payload[key]=data[key];if(pricing)for(const key of ['mrp','dealer_price','sub_dealer_price'])payload[key]=data[key];if(pricing&&cost)payload.cost_price=data.cost_price;if(inventory)payload.low_stock_at=data.low_stock_at;return payload;});form.post(p?`/commands/products/${p.id}`:'/commands/products',{forceFormData:true,onSuccess:()=>form.reset('reason','image','video','remove_image')});}
 const stockLabel = p ? (typeof p.stock === 'number' ? `${p.stock} units in stock` : (p.stock_status === 'out_of_stock' ? 'Awaiting stock' : (p.stock_status === 'low_stock' ? 'Low stock' : 'In stock'))) : '';
 const inStock = p ? (typeof p.stock === 'number' ? p.stock > 0 : p.stock_status !== 'out_of_stock') : false;
 return <Shell section="catalog"><Head title={p?'Product details':'Add product'}/><Link href="/catalog" className="text-link">← Product registry</Link><div className="registry-heading"><div><h2>{p?.name??'Add a product'}</h2><p>{p?`${p.sku} · ${stockLabel}`:'Create the product details, then receive stock through Inventory.'}</p></div><div style={{display:'flex',gap:8,alignItems:'center'}}>{p&&inventory&&<button type="button" className="button small" style={{background:inStock?'#fef2f2':'#ecfdf5',color:inStock?'#b91c1c':'#047857',borderColor:inStock?'#fecaca':'#a7f3d0',fontWeight:600,display:'inline-flex',alignItems:'center',gap:5}} onClick={()=>router.post(`/commands/products/${p.id}/toggle-stock`,{},{preserveScroll:true})}>{inStock?'⚡ Quick Toggle: Set Out of Stock':'⚡ Quick Toggle: Set In Stock'}</button>}{p?.active&&<a href={`/products/${p.id}`} className="button small" target="_blank" rel="noreferrer">View Public Product</a>}</div></div>
 <form onSubmit={save} className="registry-layout"><div><section className="panel"><h2>Product Information</h2><div className="form-grid"><label>Product name<input required disabled={!catalog} value={form.data.name} maxLength={150} onChange={e=>form.setData('name',e.target.value)}/></label><label>SKU<input required disabled={!catalog} value={form.data.sku} maxLength={60} onChange={e=>form.setData('sku',e.target.value)}/></label><label><div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}><span>Category *</span><a href="/customization" target="_blank" rel="noreferrer" className="text-link" style={{fontSize:11,fontWeight:500}}>+ Manage categories ↗</a></div>{customCategoryMode?<div style={{display:'flex',gap:6}}><input type="text" required disabled={!catalog} value={form.data.category} placeholder="Type custom category name..." maxLength={100} onChange={e=>form.setData('category',e.target.value)} style={{flex:1}}/><button type="button" className="button small" onClick={()=>setCustomCategoryMode(false)} title="Choose from configured categories">Choose from list</button></div>:<select required disabled={!catalog} value={form.data.category} onChange={e=>{if(e.target.value==='__new__'){setCustomCategoryMode(true);form.setData('category','');}else{form.setData('category',e.target.value);}}}><option value="">-- Choose from Created Category List --</option>{categories.map(c=><option key={c} value={c}>{c}</option>)}{form.data.category&&!categories.includes(form.data.category)&&<option value={form.data.category}>{form.data.category} (Current)</option>}<option value="__new__">+ Enter New / Custom Category...</option></select>}</label><label>Unit<input required disabled={!catalog} value={form.data.unit} maxLength={30} onChange={e=>form.setData('unit',e.target.value)}/></label></div><label>Description<textarea rows={5} disabled={!catalog} maxLength={3000} value={form.data.description} onChange={e=>form.setData('description',e.target.value)}/></label></section>
 <section className="panel"><h2>Product Photo</h2>{p?.image_url&&<img className="registry-photo" src={p.image_url} alt={p.name}/>}<label>Upload photo<input type="file" disabled={!catalog} accept="image/jpeg,image/png,image/webp" onChange={e=>form.setData('image',e.target.files?.[0]??null)}/></label><p className="muted">JPG, PNG or WebP · up to 5 MB · maximum 4096 × 4096 pixels.</p>{p?.image_url&&catalog&&<label className="registry-checkbox"><input type="checkbox" checked={form.data.remove_image} onChange={e=>form.setData('remove_image',e.target.checked)}/>Remove current photo</label>}</section>
 <section className="panel"><h2>Product Video</h2><fieldset className="registry-video-options" disabled={!catalog}><legend>Video source</legend>{[['none','No video'],['youtube','YouTube Link'],['vimeo','Vimeo Link'],['url','Direct Video Link'],['upload','Upload Video']].map(([value,name])=><label key={value}><input type="radio" name="video_source" value={value} checked={form.data.video_source===value} onChange={()=>{form.setData('video_source',value);form.setData('video',null);}}/>{name}</label>)}</fieldset>
 {form.data.video_source==='youtube'&&<label>YouTube video URL<input type="url" disabled={!catalog} required value={form.data.youtube_url} placeholder="https://www.youtube.com/watch?v=..." onChange={e=>form.setData('youtube_url',e.target.value)}/></label>}
 {form.data.video_source==='vimeo'&&<label>Vimeo video URL<input type="url" disabled={!catalog} required value={form.data.vimeo_url} placeholder="https://vimeo.com/123456789" onChange={e=>form.setData('vimeo_url',e.target.value)}/></label>}
 {form.data.video_source==='url'&&<label>Direct MP4 / WebM URL<input type="url" disabled={!catalog} required value={form.data.external_video_url} placeholder="https://media.example.com/product.mp4" onChange={e=>form.setData('external_video_url',e.target.value)}/><small>Use a public HTTPS video link approved for your website.</small></label>}
 {form.data.video_source==='upload'&&<><label>Upload product video<input type="file" disabled={!catalog} required={!p?.video_url} accept="video/mp4,video/webm" onChange={e=>form.setData('video',e.target.files?.[0]??null)}/></label><p className="muted">MP4 or WebM · up to 100 MB. A replacement becomes active after saving.</p></>}
 {p?.video_url&&<video className="registry-video" controls preload="metadata" src={p.video_url} aria-label="Current uploaded product video"/>}{p?.video_embed_url&&<a className="text-link" href={p.video_embed_url} target="_blank" rel="noreferrer">View current YouTube video ↗</a>}</section></div>
 <div><section className="panel"><h2>Prices &amp; Availability</h2>
  <label>MRP (BDT)<input required type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.mrp} onChange={e=>handleMrpChange(e.target.value)}/></label>
  <div style={{marginTop:8,marginBottom:8}}>
   <div style={{display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:4}}>
    <label style={{margin:0,fontSize:13,fontWeight:600,color:'#374151'}}>Dealer Discount (%)</label>
    <div style={{display:'flex',gap:4}}>
     {[20,30,40,50].map(pct=>(
      <button key={pct} type="button" disabled={!pricing} onClick={()=>{handleDiscountChange(String(pct));}} style={{padding:'2px 7px',fontSize:11,fontWeight:600,borderRadius:4,border:discountPct===String(pct)?'1px solid #059669':'1px solid #d1d5db',background:discountPct===String(pct)?'#ecfdf5':'#f9fafb',color:discountPct===String(pct)?'#047857':'#4b5563',cursor:'pointer'}}>
       {pct}%
      </button>
     ))}
    </div>
   </div>
   <input type="number" min="0" max="100" step="0.5" disabled={!pricing} placeholder="e.g. 50" value={discountPct} onChange={e=>handleDiscountChange(e.target.value)}/>
  </div>
  <label>Sub-dealer purchase price (BDT)<input type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.sub_dealer_price} onChange={e=>form.setData('sub_dealer_price',e.target.value)}/><small>Leave blank for the default 40% discount. Product-specific discounts must stay within 20–50% and fund the commission policy.</small></label>
  <label>Approved Dealer Price (BDT - Cash In)<input type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.dealer_price} onChange={e=>handleDealerPriceChange(e.target.value)}/></label>
  <div style={{background:'linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%)',border:'1px solid #a7f3d0',borderRadius:8,padding:'12px',marginTop:12,marginBottom:12}}>
   <div style={{fontSize:11,fontWeight:700,color:'#047857',textTransform:'uppercase',letterSpacing:'0.04em',marginBottom:8,display:'flex',alignItems:'center',justifyContent:'space-between'}}>
    <span>💵 Financial Cash-In Breakdown</span>
    <span style={{background:'#dcfce7',color:'#15803d',padding:'1px 6px',borderRadius:4,fontSize:10,fontWeight:700}}>{computedPct}% Discount</span>
   </div>
   <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:8}}>
    <div style={{background:'#ffffff',padding:'8px',borderRadius:6,border:'1px solid #d1fae5'}}>
     <div style={{fontSize:11,color:'#065f46'}}>Dealer Price (Cash In)</div>
     <div style={{fontSize:15,fontWeight:800,color:'#065f46'}}>৳ {dealerPriceNum>0?dealerPriceNum.toFixed(2):'0.00'}</div>
     <div style={{fontSize:10,color:'#047857'}}>Collected into system</div>
    </div>
    <div style={{background:'#ffffff',padding:'8px',borderRadius:6,border:'1px solid #fef3c7'}}>
     <div style={{fontSize:11,color:'#92400e'}}>Deducted from MRP</div>
     <div style={{fontSize:15,fontWeight:800,color:'#b45309'}}>৳ {savingsNum>0?savingsNum.toFixed(2):'0.00'}</div>
     <div style={{fontSize:10,color:'#b45309'}}>Dealer margin / savings</div>
    </div>
   </div>
  </div>
  {cost&&<label>Purchase cost (BDT)<input type="number" min="0" step="0.01" disabled={!pricing} value={form.data.cost_price} onChange={e=>form.setData('cost_price',e.target.value)}/></label>}<label>Low-stock alert threshold<input type="number" min="0" max="100000" step="1" disabled={!inventory} value={form.data.low_stock_at} onChange={e=>form.setData('low_stock_at',e.target.value)}/></label><label>Publication<select aria-label="Publication" disabled={!catalog} value={form.data.active} onChange={e=>form.setData('active',e.target.value)}><option value="0">Draft / Hidden</option><option value="1">Published</option></select></label><label>Homepage placement<select aria-label="Homepage placement" disabled={!catalog} value={form.data.featured} onChange={e=>form.setData('featured',e.target.value)}><option value="0">Standard product</option><option value="1">Featured product</option></select></label><p className="muted">Stock changes are recorded through Inventory. Existing order prices remain unchanged.</p></section>
 {editable&&<section className="panel"><h2>Save Changes</h2><label>Change reason<textarea required minLength={5} maxLength={1000} rows={3} value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>{form.progress&&<progress max={100} value={form.progress.percentage} aria-label="Product media upload progress"/>}{Object.keys(form.errors).length>0&&<div className="error" role="alert">{Object.values(form.errors).join(' ')}</div>}<button type="submit" className="button dark" disabled={form.processing}>{form.processing?'Saving…':p?'Save Product':'Create Product'}</button></section>}
 {p && catalog && (
  <section className="panel" style={{ marginTop: 20, borderColor: '#fecaca', background: '#fff5f5' }}>
   <h2 style={{ color: '#991b1b', fontSize: 15, margin: '0 0 8px' }}>Delete / Archive Product</h2>
   <p style={{ fontSize: 12, color: '#7f1d1d', margin: '0 0 14px' }}>
    Permanently delete this product if it has never been ordered, or safely deactivate and archive it if historical order records exist.
   </p>
   <button
    type="button"
    className="button small"
    style={{ background: '#ef4444', color: '#fff', border: 'none' }}
    onClick={() => {
     const reason = window.prompt(`Are you sure you want to delete or archive "${p.name}"? Please provide a reason:`);
     if (reason !== null && reason.trim().length >= 3) {
      router.delete(`/commands/products/${p.id}`, {
       data: { reason },
      });
     }
    }}
   >
    🗑 Delete / Archive Product
   </button>
  </section>
 )}
 </div></form></Shell>;
}


