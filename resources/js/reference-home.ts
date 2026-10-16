type CartItem={id:string;name:string;price:string;image:string;quantity:number};
const key='madina.reference.cart';
let cart:CartItem[]=[];let favorites:string[]=[];
try{const saved=JSON.parse(localStorage.getItem(key)||'[]');if(Array.isArray(saved))cart=saved.filter(x=>typeof x.id==='string'&&typeof x.name==='string'&&typeof x.price==='string'&&typeof x.image==='string'&&(x.image.startsWith('/reference/')||x.image.startsWith('/branding/')||/^\/products\/\d+\/image(?:\?v=\d+)?$/.test(x.image))&&Number.isInteger(x.quantity)&&x.quantity>0&&x.quantity<1000);const savedFavorites=JSON.parse(localStorage.getItem('madina.reference.favorites')||'[]');if(Array.isArray(savedFavorites))favorites=savedFavorites.filter(x=>typeof x==='string');}catch{}
const dialog=document.querySelector<HTMLDialogElement>('.ref-cart-dialog');
let toastTimer:ReturnType<typeof setTimeout>;
function toast(message:string){const node=document.querySelector<HTMLElement>('.ref-toast');if(!node)return;node.textContent=message;node.classList.add('visible');clearTimeout(toastTimer);toastTimer=setTimeout(()=>node.classList.remove('visible'),2500);}
function refreshCart(){document.querySelectorAll('[data-cart-count]').forEach(node=>node.textContent=String(cart.reduce((n,x)=>n+x.quantity,0)));localStorage.setItem(key,JSON.stringify(cart));const list=document.querySelector('.ref-cart-items');if(!list)return;list.replaceChildren();if(!cart.length){const p=document.createElement('p');p.textContent='Your cart is empty.';list.append(p);}for(const item of cart){const row=document.createElement('div');const image=document.createElement('img');image.src=item.image;image.alt='';const body=document.createElement('div');const name=document.createElement('b');name.textContent=item.name;const price=document.createElement('small');price.textContent=`${item.price} × ${item.quantity}`;body.append(name,price);const remove=document.createElement('button');remove.textContent='Remove';remove.addEventListener('click',()=>{cart=cart.filter(x=>x.id!==item.id);refreshCart();});row.append(image,body,remove);list.append(row);}}
function quantity(value:string){return Math.min(999,Math.max(1,Math.trunc(Number(value))||1));}
document.querySelectorAll<HTMLButtonElement>('[data-cart]').forEach(button=>button.addEventListener('click',()=>{const input=button.closest('.ref-detail-info')?.querySelector<HTMLInputElement>('.ref-quantity-picker input');const amount=input?quantity(input.value):1;const item=cart.find(x=>x.id===button.dataset.cart);if(item)item.quantity=Math.min(999,item.quantity+amount);else cart.push({id:button.dataset.cart!,name:button.dataset.name!,price:button.dataset.price!,image:button.dataset.image!,quantity:amount});refreshCart();toast('Product added to your cart.');}));
document.querySelectorAll<HTMLButtonElement>('[data-favorite]').forEach(button=>{const update=()=>{const active=favorites.includes(button.dataset.favorite!);button.setAttribute('aria-pressed',String(active));button.querySelector('i')?.setAttribute('class',active?'fa-solid fa-heart':'fa-regular fa-heart');};update();button.addEventListener('click',()=>{const id=button.dataset.favorite!;favorites=favorites.includes(id)?favorites.filter(x=>x!==id):[...favorites,id];localStorage.setItem('madina.reference.favorites',JSON.stringify(favorites));update();toast(favorites.includes(id)?'Product saved.':'Product removed from saved items.');});});
document.querySelectorAll('[data-open-cart]').forEach(button=>button.addEventListener('click',()=>{refreshCart();dialog?.showModal();}));document.querySelector('[data-close-cart]')?.addEventListener('click',()=>dialog?.close());dialog?.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
const comparison=document.querySelector<HTMLDialogElement>('.ref-compare-dialog');document.querySelector('[data-open-compare]')?.addEventListener('click',()=>comparison?.showModal());document.querySelector('[data-close-compare]')?.addEventListener('click',()=>comparison?.close());comparison?.addEventListener('click',e=>{if(e.target===comparison)comparison.close();});
document.querySelectorAll<HTMLSelectElement>('[data-compare-slot]').forEach(select=>select.addEventListener('change',()=>{const slots=[...document.querySelectorAll<HTMLSelectElement>('[data-compare-slot]')].filter(x=>x.value);document.querySelectorAll('[data-compare-count]').forEach(x=>x.textContent=String(slots.length));const target=document.querySelector('.ref-compare-results');target?.replaceChildren();for(const slot of slots){const option=slot.selectedOptions[0];const card=document.createElement('article');const picture=document.createElement('img');picture.src=option.dataset.image!;picture.alt='';const name=document.createElement('b');name.textContent=option.dataset.name!;const price=document.createElement('strong');price.textContent=option.dataset.price!;const stock=document.createElement('small');stock.textContent=option.dataset.stock!;card.append(picture,name,price,stock);target?.append(card);}}));
const promotion=document.querySelector<HTMLDialogElement>('.ref-promotion-dialog');const promotionKey='madina.reference.promotion.dismissed';function dismissPromotion(){promotion?.close();localStorage.setItem(promotionKey,String(Date.now()));}document.querySelector('[data-close-promotion]')?.addEventListener('click',dismissPromotion);promotion?.addEventListener('cancel',()=>localStorage.setItem(promotionKey,String(Date.now())));promotion?.addEventListener('click',e=>{if(e.target===promotion)dismissPromotion();});try{if(Date.now()-Number(localStorage.getItem(promotionKey)||'0')>24*60*60*1000)setTimeout(()=>{if(!document.querySelector('dialog[open]'))promotion?.showModal();},3000);}catch{}
function initCarousel(root:HTMLElement,intervalMs:number=6000){const slides=[...root.querySelectorAll<HTMLElement>('.ref-slides > .ref-slide')];const dots=[...root.querySelectorAll<HTMLButtonElement>('.ref-carousel-dots > [data-slide]')];if(!slides.length)return;let current=0;let timer:ReturnType<typeof setInterval>;function show(index:number){current=(index+slides.length)%slides.length;slides.forEach((slide,i)=>{slide.classList.toggle('is-active',i===current);slide.setAttribute('aria-hidden',String(i!==current));slide.tabIndex=i===current?0:-1;});dots.forEach((dot,i)=>{dot.classList.toggle('is-active',i===current);dot.setAttribute('aria-current',String(i===current));});}function start(){clearInterval(timer);if(slides.length>1&&!matchMedia('(prefers-reduced-motion: reduce)').matches)timer=setInterval(()=>show(current+1),intervalMs);}root.querySelector('.ref-carousel-arrow.prev')?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();show(current-1);start();});root.querySelector('.ref-carousel-arrow.next')?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();show(current+1);start();});dots.forEach(dot=>dot.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();show(Number(dot.dataset.slide));start();}));root.addEventListener('mouseenter',()=>clearInterval(timer));root.addEventListener('mouseleave',start);root.addEventListener('focusin',()=>clearInterval(timer));root.addEventListener('focusout',start);let touchStartX=0;let touchStartY=0;root.addEventListener('touchstart',e=>{if(e.changedTouches?.[0]){touchStartX=e.changedTouches[0].clientX;touchStartY=e.changedTouches[0].clientY;}},{passive:true});root.addEventListener('touchend',e=>{if(e.changedTouches?.[0]){const diffX=e.changedTouches[0].clientX-touchStartX;const diffY=e.changedTouches[0].clientY-touchStartY;if(Math.abs(diffX)>35&&Math.abs(diffX)>Math.abs(diffY)){if(diffX<0)show(current+1);else show(current-1);start();}}},{passive:true});document.addEventListener('visibilitychange',()=>{if(document.hidden)clearInterval(timer);else start();});show(0);start();}
document.querySelectorAll<HTMLElement>('.ref-carousel-instance').forEach((el,idx)=>{initCarousel(el,idx===0?6000:(idx===1?4800:6400));});
const topButton=document.querySelector<HTMLElement>('.ref-back-top');window.addEventListener('scroll',()=>topButton?.classList.toggle('visible',window.scrollY>360),{passive:true});topButton?.addEventListener('click',()=>window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'}));
refreshCart();
document.querySelectorAll<HTMLSelectElement>('[data-autosubmit]').forEach(select=>select.addEventListener('change',()=>select.form?.requestSubmit()));
document.querySelectorAll<HTMLInputElement>('.ref-quantity-picker input').forEach(input=>input.addEventListener('change',()=>input.value=String(quantity(input.value))));
document.querySelectorAll<HTMLButtonElement>('[data-quantity-step]').forEach(button=>button.addEventListener('click',()=>{const input=button.parentElement?.querySelector<HTMLInputElement>('input');if(input)input.value=String(quantity(String(quantity(input.value)+Number(button.dataset.quantityStep))));}));
const tabs=[...document.querySelectorAll<HTMLButtonElement>('[data-info-tab]')];
function activateTab(tab:HTMLButtonElement){tabs.forEach(other=>{const selected=other===tab;other.setAttribute('aria-selected',String(selected));other.tabIndex=selected?0:-1;const panel=document.getElementById(other.dataset.infoTab!);if(panel)panel.hidden=!selected;});}
tabs.forEach((tab,index)=>{tab.tabIndex=index===0?0:-1;tab.addEventListener('click',()=>activateTab(tab));tab.addEventListener('keydown',event=>{let target:number;if(event.key==='ArrowRight')target=(index+1)%tabs.length;else if(event.key==='ArrowLeft')target=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')target=0;else if(event.key==='End')target=tabs.length-1;else return;event.preventDefault();activateTab(tabs[target]);tabs[target].focus();});});
async function copyProductLink(){try{await navigator.clipboard.writeText(location.href);toast('Product link copied.');}catch{toast('Copy the product link from your browser address bar.');}}
document.querySelector('[data-copy-url]')?.addEventListener('click',copyProductLink);
document.querySelector('[data-share-url]')?.addEventListener('click',async()=>{if(navigator.share){try{await navigator.share({title:document.title,url:location.href});}catch{}}else await copyProductLink();});
document.querySelector<HTMLButtonElement>('[data-compare-product]')?.addEventListener('click',event=>{const button=event.currentTarget as HTMLButtonElement;const selects=[...document.querySelectorAll<HTMLSelectElement>('[data-compare-slot]')];const duplicate=selects.find(s=>s.value===button.dataset.compareProduct);const select=duplicate||selects.find(s=>!s.value);if(!select){toast('Remove a comparison selection to add another product.');comparison?.showModal();return;}if(![...select.options].some(o=>o.value===button.dataset.compareProduct)){const cartButton=document.querySelector<HTMLButtonElement>('.ref-detail-cart');const option=new Option(cartButton?.dataset.name||document.title,button.dataset.compareProduct);option.dataset.name=cartButton?.dataset.name||document.title;option.dataset.price=cartButton?.dataset.price||'';option.dataset.image=cartButton?.dataset.image||'/reference/logo.png';option.dataset.stock=document.querySelector('.ref-metadata')?.textContent?.includes('In Stock')?'In Stock':'Awaiting Stock';select.add(option);}select.value=button.dataset.compareProduct!;select.dispatchEvent(new Event('change'));comparison?.showModal();});
document.querySelectorAll<HTMLButtonElement>('[data-toggle-password]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.togglePassword!) as HTMLInputElement|null;if(!input)return;const visible=input.type==='password';input.type=visible?'text':'password';button.setAttribute('aria-label',visible?'Hide password':'Show password');button.setAttribute('aria-pressed',String(visible));}));
const nearestBtn=document.getElementById('find-nearest-btn') as HTMLButtonElement|null;
const locStatus=document.getElementById('location-status');
if(nearestBtn&&locStatus){nearestBtn.addEventListener('click',()=>{if(!navigator.geolocation){locStatus.textContent='Geolocation is not supported by your browser. You can filter by Division and District.';return;}nearestBtn.disabled=true;locStatus.textContent='Detecting your location...';navigator.geolocation.getCurrentPosition(pos=>{const lat=pos.coords.latitude.toFixed(6);const lng=pos.coords.longitude.toFixed(6);locStatus.textContent='Location detected! Finding nearest dealers...';const url=new URL(window.location.href);url.searchParams.set('lat',lat);url.searchParams.set('lng',lng);url.searchParams.delete('page');window.location.href=url.toString();},err=>{nearestBtn.disabled=false;if(err.code===err.PERMISSION_DENIED){locStatus.textContent='Location permission was denied. You can filter by Division and District below.';}else{locStatus.textContent='Could not detect location. You can filter by Division and District below.';}},{enableHighAccuracy:true,timeout:8000});});}

// Interactive Category Filter Tabs
const categoryTabBtns = document.querySelectorAll<HTMLButtonElement>('.ref-tab-btn[data-category-filter]');
const productCards = document.querySelectorAll<HTMLElement>('#refProductGrid .ref-product-card');
const tabEmptyNotice = document.getElementById('refTabEmpty');

categoryTabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        categoryTabBtns.forEach(b => {
            b.classList.remove('is-active');
            b.setAttribute('aria-selected', 'false');
        });
        btn.classList.add('is-active');
        btn.setAttribute('aria-selected', 'true');

        const filter = btn.dataset.categoryFilter;
        let visibleCount = 0;

        productCards.forEach(card => {
            const cardCat = card.dataset.category;
            if (filter === 'all' || cardCat === filter) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        if (tabEmptyNotice) {
            tabEmptyNotice.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    });
});

// Interactive Quick View Modal Dialog
const qvDialog = document.getElementById('refQuickViewDialog') as HTMLDialogElement | null;
const qvCloseBtn = document.getElementById('refQvCloseBtn');
const qvImage = document.getElementById('qvImage') as HTMLImageElement | null;
const qvBadge = document.getElementById('qvBadge');
const qvCategory = document.getElementById('qvCategory');
const qvTitle = document.getElementById('qvTitle');
const qvStockRow = document.getElementById('qvStockRow');
const qvPrice = document.getElementById('qvPrice');
const qvMrp = document.getElementById('qvMrp');
const qvSave = document.getElementById('qvSave');
const qvDescription = document.getElementById('qvDescription');
const qvAddCart = document.getElementById('qvAddCart') as HTMLButtonElement | null;
const qvViewDetails = document.getElementById('qvViewDetails') as HTMLAnchorElement | null;

document.querySelectorAll<HTMLButtonElement>('[data-quick-view]').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const card = btn.closest<HTMLElement>('.ref-product-card');
        if (!card || !qvDialog) return;

        const id = card.dataset.id || '';
        const name = card.dataset.name || '';
        const price = card.dataset.price || '';
        const mrp = card.dataset.mrp || '';
        const image = card.dataset.image || '/madina-header-logo.png?v=6';
        const category = card.dataset.categoryName || 'General';
        const stockStatus = card.dataset.stockStatus || 'in_stock';
        const stockCount = Number(card.dataset.stockCount || 0);
        const marketingBadge = card.dataset.marketingBadge || '';
        const discountPct = Number(card.dataset.discountPct || 0);
        const savingsText = card.dataset.savingsText || '';
        const desc = card.dataset.desc || '';
        const url = card.dataset.url || `/products/${id}`;

        if (qvImage) {
            qvImage.src = image;
            qvImage.alt = name;
        }

        if (qvBadge) {
            if (discountPct > 0) {
                qvBadge.textContent = `-${discountPct}% OFF`;
                qvBadge.style.display = 'inline-block';
            } else if (marketingBadge) {
                qvBadge.textContent = marketingBadge;
                qvBadge.style.display = 'inline-block';
            } else {
                qvBadge.style.display = 'none';
            }
        }

        if (qvCategory) qvCategory.textContent = category;
        if (qvTitle) qvTitle.textContent = name;

        if (qvStockRow) {
            qvStockRow.innerHTML = '';
            const badge = document.createElement('span');
            badge.className = `ref-stock-badge ${stockStatus}`;
            if (stockCount <= 0) {
                badge.textContent = 'Awaiting Stock';
            } else if (stockCount <= 5) {
                badge.textContent = `⚡ Only ${stockCount} Left!`;
            } else {
                badge.textContent = 'In Stock';
            }
            qvStockRow.appendChild(badge);
        }

        if (qvPrice) qvPrice.textContent = price;
        if (qvMrp) {
            if (mrp && mrp !== price) {
                qvMrp.textContent = mrp;
                qvMrp.style.display = 'inline';
            } else {
                qvMrp.style.display = 'none';
            }
        }
        if (qvSave) {
            if (savingsText) {
                qvSave.textContent = savingsText;
                qvSave.style.display = 'inline-block';
            } else {
                qvSave.style.display = 'none';
            }
        }

        if (qvDescription) {
            qvDescription.textContent = desc || 'High-grade wellness and natural health care product formulated for purity and everyday vitality.';
        }

        if (qvViewDetails) {
            qvViewDetails.href = url;
        }

        if (qvAddCart) {
            qvAddCart.onclick = () => {
                const item = cart.find(x => x.id === id);
                if (item) item.quantity = Math.min(999, item.quantity + 1);
                else cart.push({ id, name, price, image, quantity: 1 });
                refreshCart();
                toast('Product added to your cart.');
                qvDialog.close();
            };
        }

        qvDialog.showModal();
    });
});

qvCloseBtn?.addEventListener('click', () => qvDialog?.close());
qvDialog?.addEventListener('click', (e) => {
    if (e.target === qvDialog) qvDialog.close();
});

document.querySelectorAll<HTMLElement>('[data-buy-now]').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.buyNow;
        if (!id) return;
        const name = btn.dataset.name || '';
        const price = btn.dataset.price || '';
        const image = btn.dataset.image || '';
        const item = cart.find(x => x.id === id);
        if (item) {
            item.quantity = Math.min(999, item.quantity + 1);
        } else {
            cart.push({ id, name, price, image, quantity: 1 });
        }
        refreshCart();
    });
});

// Mobile Navigation Drawer & Search Overlay Handlers
const mobileMenuBtn = document.getElementById('refMobileMenuToggle');
const mobileBottomCatBtn = document.getElementById('refMobileBottomCategories');
const mobileDrawer = document.getElementById('refMobileDrawer');
const mobileDrawerClose = document.getElementById('refMobileDrawerClose');
const mobileMenuOverlay = document.getElementById('refMobileMenuOverlay');

function openMobileDrawer() {
    mobileMenuOverlay?.classList.add('is-active');
    mobileDrawer?.classList.add('is-active');
    mobileDrawer?.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeMobileDrawer() {
    mobileMenuOverlay?.classList.remove('is-active');
    mobileDrawer?.classList.remove('is-active');
    mobileDrawer?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

mobileMenuBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    openMobileDrawer();
});

mobileBottomCatBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    openMobileDrawer();
});

mobileDrawerClose?.addEventListener('click', (e) => {
    e.preventDefault();
    closeMobileDrawer();
});

mobileMenuOverlay?.addEventListener('click', () => {
    closeMobileDrawer();
});

const mobileSearchToggle = document.getElementById('refMobileSearchToggle');
const mobileSearchOverlay = document.getElementById('refMobileSearchOverlay');
const mobileSearchClose = document.getElementById('refMobileSearchClose');
const mobileSearchInput = document.getElementById('refMobileSearchInput') as HTMLInputElement | null;

function openMobileSearch() {
    mobileSearchOverlay?.classList.add('is-open');
    mobileSearchOverlay?.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(() => mobileSearchInput?.focus(), 80);
}

function closeMobileSearch() {
    mobileSearchOverlay?.classList.remove('is-open');
    mobileSearchOverlay?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

mobileSearchToggle?.addEventListener('click', (e) => {
    e.preventDefault();
    openMobileSearch();
});

mobileSearchClose?.addEventListener('click', (e) => {
    e.preventDefault();
    closeMobileSearch();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (mobileDrawer?.classList.contains('is-active')) closeMobileDrawer();
        if (mobileSearchOverlay?.classList.contains('is-open')) closeMobileSearch();
    }
});
