import { bindLocaleSwitchForms } from './shared/localeSwitch';
import { bindThemeToggles } from './shared/theme';

bindThemeToggles();
bindLocaleSwitchForms();

let toastTimer:ReturnType<typeof setTimeout>;
type SiteUi = {
    in_stock: string;
    out_of_stock: string;
    low_stock: string;
    product_link_copied: string;
    product_link_copy_fallback: string;
    geolocation_unsupported: string;
    detecting_location: string;
    location_detected: string;
    location_denied: string;
    location_failed: string;
    product_image: string;
};
const siteUi: SiteUi = (() => {
    const node = document.getElementById('ref-site-ui');
    if (!node?.textContent) {
        return {
            in_stock: 'In Stock',
            out_of_stock: 'Awaiting Stock',
            low_stock: 'Low Stock',
            product_link_copied: 'Product link copied.',
            product_link_copy_fallback: 'Copy the product link from your browser address bar.',
            geolocation_unsupported: 'Geolocation is not supported by your browser. You can filter by Division and District.',
            detecting_location: 'Detecting your location...',
            location_detected: 'Location detected! Finding nearest dealers...',
            location_denied: 'Location permission was denied. You can filter by Division and District below.',
            location_failed: 'Could not detect location. You can filter by Division and District below.',
            product_image: 'Product Image',
        };
    }
    try {
        return JSON.parse(node.textContent) as SiteUi;
    } catch {
        return {
            in_stock: 'In Stock',
            out_of_stock: 'Awaiting Stock',
            low_stock: 'Low Stock',
            product_link_copied: 'Product link copied.',
            product_link_copy_fallback: 'Copy the product link from your browser address bar.',
            geolocation_unsupported: 'Geolocation is not supported by your browser. You can filter by Division and District.',
            detecting_location: 'Detecting your location...',
            location_detected: 'Location detected! Finding nearest dealers...',
            location_denied: 'Location permission was denied. You can filter by Division and District below.',
            location_failed: 'Could not detect location. You can filter by Division and District below.',
            product_image: 'Product Image',
        };
    }
})();
function toast(message:string){const node=document.querySelector<HTMLElement>('.ref-toast');if(!node)return;node.textContent=message;node.classList.add('visible');clearTimeout(toastTimer);toastTimer=setTimeout(()=>node.classList.remove('visible'),2500);}
function initCarousel(root:HTMLElement,intervalMs:number=6000){const slides=[...root.querySelectorAll<HTMLElement>('.ref-slides > .ref-slide')];const dots=[...root.querySelectorAll<HTMLButtonElement>('.ref-carousel-dots > [data-slide]')];if(!slides.length)return;let current=0;let timer:ReturnType<typeof setInterval>;function show(index:number){current=(index+slides.length)%slides.length;slides.forEach((slide,i)=>{slide.classList.toggle('is-active',i===current);slide.setAttribute('aria-hidden',String(i!==current));slide.tabIndex=i===current?0:-1;});dots.forEach((dot,i)=>{dot.classList.toggle('is-active',i===current);dot.setAttribute('aria-current',String(i===current));});}function start(){clearInterval(timer);if(slides.length>1&&!matchMedia('(prefers-reduced-motion: reduce)').matches)timer=setInterval(()=>show(current+1),intervalMs);}root.querySelector('.ref-carousel-arrow.prev')?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();show(current-1);start();});root.querySelector('.ref-carousel-arrow.next')?.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();show(current+1);start();});dots.forEach(dot=>dot.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();show(Number(dot.dataset.slide));start();}));root.addEventListener('mouseenter',()=>clearInterval(timer));root.addEventListener('mouseleave',start);root.addEventListener('focusin',()=>clearInterval(timer));root.addEventListener('focusout',start);let touchStartX=0;let touchStartY=0;root.addEventListener('touchstart',e=>{if(e.changedTouches?.[0]){touchStartX=e.changedTouches[0].clientX;touchStartY=e.changedTouches[0].clientY;}},{passive:true});root.addEventListener('touchend',e=>{if(e.changedTouches?.[0]){const diffX=e.changedTouches[0].clientX-touchStartX;const diffY=e.changedTouches[0].clientY-touchStartY;if(Math.abs(diffX)>35&&Math.abs(diffX)>Math.abs(diffY)){if(diffX<0)show(current+1);else show(current-1);start();}}},{passive:true});document.addEventListener('visibilitychange',()=>{if(document.hidden)clearInterval(timer);else start();});show(0);start();}
document.querySelectorAll<HTMLElement>('.ref-carousel-instance').forEach((el,idx)=>{initCarousel(el,idx===0?6000:(idx===1?4800:6400));});
const topButton=document.querySelector<HTMLElement>('.ref-back-top');window.addEventListener('scroll',()=>topButton?.classList.toggle('visible',window.scrollY>360),{passive:true});topButton?.addEventListener('click',()=>window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'}));
document.querySelectorAll<HTMLSelectElement>('[data-autosubmit]').forEach(select=>select.addEventListener('change',()=>select.form?.requestSubmit()));
const tabs=[...document.querySelectorAll<HTMLButtonElement>('[data-info-tab]')];
function activateTab(tab:HTMLButtonElement){tabs.forEach(other=>{const selected=other===tab;other.setAttribute('aria-selected',String(selected));other.tabIndex=selected?0:-1;const panel=document.getElementById(other.dataset.infoTab!);if(panel)panel.hidden=!selected;});}
tabs.forEach((tab,index)=>{tab.tabIndex=index===0?0:-1;tab.addEventListener('click',()=>activateTab(tab));tab.addEventListener('keydown',event=>{let target:number;if(event.key==='ArrowRight')target=(index+1)%tabs.length;else if(event.key==='ArrowLeft')target=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')target=0;else if(event.key==='End')target=tabs.length-1;else return;event.preventDefault();activateTab(tabs[target]);tabs[target].focus();});});
async function copyProductLink(){try{await navigator.clipboard.writeText(location.href);toast(siteUi.product_link_copied);}catch{toast(siteUi.product_link_copy_fallback);}}
document.querySelector('[data-copy-url]')?.addEventListener('click',copyProductLink);
document.querySelector('[data-share-url]')?.addEventListener('click',async()=>{if(navigator.share){try{await navigator.share({title:document.title,url:location.href});}catch{}}else await copyProductLink();});
document.querySelectorAll<HTMLButtonElement>('[data-toggle-password]').forEach(button=>button.addEventListener('click',()=>{const input=document.getElementById(button.dataset.togglePassword!) as HTMLInputElement|null;if(!input)return;const visible=input.type==='password';input.type=visible?'text':'password';button.setAttribute('aria-label',visible?'Hide password':'Show password');button.setAttribute('aria-pressed',String(visible));}));
const nearestBtn=document.getElementById('find-nearest-btn') as HTMLButtonElement|null;
const locStatus=document.getElementById('location-status');
if(nearestBtn&&locStatus){nearestBtn.addEventListener('click',()=>{if(!navigator.geolocation){locStatus.textContent=siteUi.geolocation_unsupported;return;}nearestBtn.disabled=true;locStatus.textContent=siteUi.detecting_location;navigator.geolocation.getCurrentPosition(pos=>{const lat=pos.coords.latitude.toFixed(6);const lng=pos.coords.longitude.toFixed(6);locStatus.textContent=siteUi.location_detected;const url=new URL(window.location.href);url.searchParams.set('lat',lat);url.searchParams.set('lng',lng);url.searchParams.delete('page');window.location.href=url.toString();},err=>{nearestBtn.disabled=false;if(err.code===err.PERMISSION_DENIED){locStatus.textContent=siteUi.location_denied;}else{locStatus.textContent=siteUi.location_failed;}},{enableHighAccuracy:true,timeout:8000});});}

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

// Public previews provide information and dealer contact routes.
const qvDialog = document.getElementById('refQuickViewDialog') as HTMLDialogElement | null;
document.querySelectorAll<HTMLButtonElement>('[data-quick-view]').forEach(button => {
    button.addEventListener('click', () => {
        const card = button.closest<HTMLElement>('.ref-product-card');
        if (!card || !qvDialog) return;
        const image = document.getElementById('qvImage') as HTMLImageElement | null;
        if (image) {
            image.src = card.dataset.image || '/madina-header-logo.png?v=8';
            image.alt = card.dataset.name || siteUi.product_image;
        }
        for (const [id, value] of Object.entries({qvTitle: card.dataset.name, qvCategory: card.dataset.categoryName, qvMrp: card.dataset.mrp, qvDescription: card.dataset.desc})) {
            const element = document.getElementById(id);
            if (element) element.textContent = value || '';
        }
        const availability = document.getElementById('qvStockRow');
        if (availability) {
            const status = card.dataset.stockStatus || '';
            availability.textContent = status === 'out_of_stock'
                ? siteUi.out_of_stock
                : (status === 'low_stock' ? siteUi.low_stock : siteUi.in_stock);
        }
        const details = document.getElementById('qvViewDetails') as HTMLAnchorElement | null;
        if (details) details.href = card.dataset.url || '/products';
        qvDialog.showModal();
    });
});
document.getElementById('refQvCloseBtn')?.addEventListener('click', () => qvDialog?.close());
qvDialog?.addEventListener('click', event => { if (event.target === qvDialog) qvDialog.close(); });

// Mobile Navigation Drawer & Search Overlay Handlers
const mobileMenuBtn = document.getElementById('refMobileMenuToggle');
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
const mobileSearchForm = document.getElementById('refMobileSearchForm') as HTMLFormElement | null;
const mobileSearchList = document.getElementById('refMobileSearchList');
const mobileSearchResults = document.getElementById('refMobileSearchResults');
const mobileSearchEmpty = document.getElementById('refMobileSearchEmpty');
const mobileSearchHint = document.getElementById('refMobileSearchHint');
const mobileSearchShortcuts = document.getElementById('refMobileSearchShortcuts');
const mobileSearchViewAll = document.getElementById('refMobileSearchViewAll') as HTMLAnchorElement | null;
let mobileSearchTimer: ReturnType<typeof setTimeout> | undefined;
let mobileSearchRequest = 0;

function openMobileSearch() {
    mobileSearchOverlay?.classList.add('is-open');
    mobileSearchOverlay?.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    setTimeout(() => {
        mobileSearchInput?.focus();
        if (mobileSearchInput?.value.trim()) {
            runMobileProductSearch(mobileSearchInput.value);
        }
    }, 80);
}

function closeMobileSearch() {
    mobileSearchOverlay?.classList.remove('is-open');
    mobileSearchOverlay?.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function setMobileSearchMode(mode: 'idle' | 'results' | 'empty') {
    if (mobileSearchHint) mobileSearchHint.hidden = mode !== 'idle';
    if (mobileSearchShortcuts) mobileSearchShortcuts.hidden = mode !== 'idle';
    if (mobileSearchResults) mobileSearchResults.hidden = mode !== 'results';
    if (mobileSearchEmpty) mobileSearchEmpty.hidden = mode !== 'empty';
}

function formatMobileSearchPrice(value: unknown): string {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '';
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'BDT', maximumFractionDigits: 0 }).format(amount);
    } catch {
        return `৳ ${amount}`;
    }
}

async function runMobileProductSearch(rawQuery: string) {
    const q = rawQuery.trim();
    if (!q) {
        if (mobileSearchList) mobileSearchList.innerHTML = '';
        setMobileSearchMode('idle');
        return;
    }

    const requestId = ++mobileSearchRequest;
    try {
        const response = await fetch(`/search/products?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('search_failed');
        const payload = await response.json() as {
            items?: Array<{ name: string; category?: string; mrp?: string | number; url: string }>;
            all_url?: string;
            count?: number;
        };
        if (requestId !== mobileSearchRequest) return;

        const items = Array.isArray(payload.items) ? payload.items : [];
        if (mobileSearchViewAll) {
            mobileSearchViewAll.href = payload.all_url || `/products?q=${encodeURIComponent(q)}`;
        }
        if (!mobileSearchList) return;

        mobileSearchList.replaceChildren();
        if (items.length === 0) {
            setMobileSearchMode('empty');
            return;
        }

        const fragment = document.createDocumentFragment();
        items.forEach((item) => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.className = 'ref-mobile-search-result';
            link.href = item.url || '/products';
            link.setAttribute('role', 'option');

            const name = document.createElement('span');
            name.className = 'ref-mobile-search-result-name';
            name.textContent = item.name || '';
            link.appendChild(name);

            const price = formatMobileSearchPrice(item.mrp);
            const metaText = [item.category, price].filter(Boolean).join(' · ');
            if (metaText) {
                const meta = document.createElement('span');
                meta.className = 'ref-mobile-search-result-meta';
                meta.textContent = metaText;
                link.appendChild(meta);
            }

            li.appendChild(link);
            fragment.appendChild(li);
        });
        mobileSearchList.appendChild(fragment);
        setMobileSearchMode('results');
    } catch {
        if (requestId !== mobileSearchRequest) return;
        // Fall back to full catalog search if live suggestions fail.
        if (mobileSearchForm) mobileSearchForm.requestSubmit();
    }
}

mobileSearchToggle?.addEventListener('click', (e) => {
    e.preventDefault();
    openMobileSearch();
});

mobileSearchClose?.addEventListener('click', (e) => {
    e.preventDefault();
    closeMobileSearch();
});

mobileSearchForm?.addEventListener('submit', (e) => {
    const q = mobileSearchInput?.value.trim() ?? '';
    if (!q) {
        e.preventDefault();
        mobileSearchInput?.focus();
        setMobileSearchMode('idle');
        return;
    }
    // Allow native navigation to /products?q=...
});

mobileSearchInput?.addEventListener('input', () => {
    clearTimeout(mobileSearchTimer);
    const value = mobileSearchInput.value;
    mobileSearchTimer = setTimeout(() => runMobileProductSearch(value), 220);
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        if (mobileDrawer?.classList.contains('is-active')) closeMobileDrawer();
        if (mobileSearchOverlay?.classList.contains('is-open')) closeMobileSearch();
    }
});

// Professional Scroll Reveal & Staggered Micro-Interactions Engine
function initScrollReveal() {
    if (typeof window === 'undefined') return;

    const prefersReducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isObserverSupported = 'IntersectionObserver' in window;

    // Assign staggered index variable to grid children
    const staggerContainers = [
        '.ref-shortcuts',
        '.ref-category-grid',
        '#refProductGrid',
        '.ref-directory-grid',
        '.ref-contact-grid',
        '.ref-about-sections-grid',
    ];

    staggerContainers.forEach((selector) => {
        const container = document.querySelector<HTMLElement>(selector);
        if (!container) return;
        const children = container.children;
        for (let i = 0; i < children.length; i++) {
            const child = children[i] as HTMLElement;
            child.style.setProperty('--reveal-index', String(i % 12));
            if (!child.classList.contains('ref-reveal-card')) {
                child.classList.add('ref-reveal-card');
            }
        }
    });

    // Main section reveal targets.
    // Keep the dealer banner out of transform-based reveal — that motion looked like a
    // glitch under the banner while hovering/scrolling neighboring sections.
    const sectionSelectors = [
        '.ref-company-intro',
        '.ref-promotions',
        '.ref-announcement',
        '.ref-shortcuts',
        '.ref-featured-categories',
        '.ref-featured-products',
        '.ref-home-video',
        '.ref-home-about',
        '.ref-content-card',
        '.ref-auth-card',
        '.ref-partner-login-card',
        '.ref-catalog-toolbar',
        '.ref-filter-sidebar',
        '.ref-detail-card',
        '.ref-product-description',
        '.ref-map-card',
        '.ref-partner-apply-card',
        '.ref-directory-heading',
        '[data-reveal]',
    ];

    const targets = document.querySelectorAll<HTMLElement>(sectionSelectors.join(', '));

    if (prefersReducedMotion || !isObserverSupported) {
        targets.forEach((el) => {
            el.classList.add('is-revealed');
        });
        document.querySelectorAll<HTMLElement>('.ref-reveal-card').forEach((el) => {
            el.classList.add('is-revealed');
        });
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    observer.unobserve(entry.target);
                }
            });
        },
        {
            rootMargin: '0px 0px -40px 0px',
            threshold: 0.05,
        }
    );

    targets.forEach((el) => {
        if (!el.classList.contains('ref-reveal-init')) {
            el.classList.add('ref-reveal-init');
        }
        observer.observe(el);
    });

    // Also observe individual product and directory cards if loaded dynamically
    const cardObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-revealed');
                    cardObserver.unobserve(entry.target);
                }
            });
        },
        {
            rootMargin: '0px 0px -25px 0px',
            threshold: 0.04,
        }
    );

    document.querySelectorAll<HTMLElement>('.ref-reveal-card').forEach((card) => {
        cardObserver.observe(card);
    });
}

// Interactive Smooth Accordion for FAQs
function initFaqAccordions() {
    document.querySelectorAll<HTMLDetailsElement>('details.ref-content-card, details.ref-faq-item').forEach((details) => {
        const summary = details.querySelector('summary');
        if (!summary) return;

        summary.addEventListener('click', (e) => {
            // Animate details opening/closing if browser supports WAAPI
            if (details.open) {
                // If closing, let CSS/transition handle smoothly
                summary.setAttribute('aria-expanded', 'false');
            } else {
                summary.setAttribute('aria-expanded', 'true');
            }
        });
    });
}

function initPageProgressNavigation() {
    const progress = document.getElementById('refPageProgress');
    const root = document.documentElement;
    if (!progress) {
        return;
    }

    const setProgress = (value: number) => {
        root.style.setProperty('--ref-boot-progress', `${Math.max(0, Math.min(100, value))}%`);
        progress.setAttribute('aria-valuenow', String(Math.round(value)));
    };

    const start = () => {
        root.classList.add('ref-booting');
        progress.classList.add('is-active');
        progress.setAttribute('aria-hidden', 'false');
        setProgress(18);
        window.setTimeout(() => setProgress(55), 120);
        window.setTimeout(() => setProgress(78), 320);
    };

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }
        const link = target.closest('a[href]');
        if (!(link instanceof HTMLAnchorElement)) {
            return;
        }
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (link.hasAttribute('download') || link.target === '_blank' || link.getAttribute('href')?.startsWith('#')) {
            return;
        }
        let url: URL;
        try {
            url = new URL(link.href, window.location.href);
        } catch {
            return;
        }
        if (url.origin !== window.location.origin) {
            return;
        }
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
            return;
        }
        start();
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            progress.classList.remove('is-active');
            progress.setAttribute('aria-hidden', 'true');
            root.classList.remove('ref-booting', 'ref-boot-visible');
            root.classList.add('ref-boot-done');
        }
    });
}

function prefetchLikelyNextPages() {
    const run = () => {
        const paths = ['/login', '/products', '/dealers'];
        paths.forEach((path) => {
            if (window.location.pathname === path) {
                return;
            }
            const link = document.createElement('link');
            link.rel = 'prefetch';
            link.href = path;
            link.as = 'document';
            document.head.appendChild(link);
        });
    };

    const idle = (window as Window & {
        requestIdleCallback?: (callback: () => void, options?: { timeout: number }) => number;
    }).requestIdleCallback;

    if (typeof idle === 'function') {
        idle(() => run(), { timeout: 2500 });
    } else {
        window.setTimeout(run, 1200);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initScrollReveal();
        initFaqAccordions();
        bindLocaleSwitchForms();
        initPageProgressNavigation();
        prefetchLikelyNextPages();
    });
} else {
    initScrollReveal();
    initFaqAccordions();
    bindLocaleSwitchForms();
    initPageProgressNavigation();
    prefetchLikelyNextPages();
}
