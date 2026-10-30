/**
 * Immediate full-page feedback when switching EN ↔ BN.
 * Avoids the frozen "nothing happens then sudden change" feel from async fetch+reload.
 */

const OVERLAY_ID = 'madina-locale-switching';

function overlayCopy(targetLocale: string): { title: string; subtitle: string } {
    if (targetLocale === 'bn') {
        return {
            title: 'Switching to বাংলা…',
            subtitle: 'ভাষা পরিবর্তন হচ্ছে — পুরো পেজ রিলোড হচ্ছে',
        };
    }

    return {
        title: 'Switching to English…',
        subtitle: 'Changing language — reloading the full page',
    };
}

export function showLocaleSwitchOverlay(targetLocale: string): void {
    if (typeof document === 'undefined') {
        return;
    }

    if (document.getElementById(OVERLAY_ID)) {
        return;
    }

    const { title, subtitle } = overlayCopy(targetLocale);
    const overlay = document.createElement('div');
    overlay.id = OVERLAY_ID;
    overlay.className = 'madina-locale-switching';
    overlay.setAttribute('role', 'alert');
    overlay.setAttribute('aria-live', 'assertive');
    overlay.setAttribute('aria-busy', 'true');
    overlay.innerHTML = `
        <div class="madina-locale-switching-card">
            <span class="madina-locale-switching-spinner" aria-hidden="true"></span>
            <p class="madina-locale-switching-title">${title}</p>
            <p class="madina-locale-switching-subtitle">${subtitle}</p>
        </div>
    `;

    document.documentElement.classList.add('madina-locale-switching-active');
    document.body.appendChild(overlay);
    // Force paint before navigation so the user sees feedback immediately.
    void overlay.offsetHeight;
}

export function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) {
        return meta;
    }

    const input = document.querySelector<HTMLInputElement>('input[name="_token"]');

    return input?.value || '';
}

/**
 * Full document POST to /locale — one navigation, same as a normal page reload.
 */
export function submitLocaleChange(targetLocale: string, returnUrl?: string): void {
    showLocaleSwitchOverlay(targetLocale);

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/locale';
    form.style.display = 'none';
    form.setAttribute('aria-hidden', 'true');

    const token = csrfToken();
    if (token) {
        const tokenInput = document.createElement('input');
        tokenInput.type = 'hidden';
        tokenInput.name = '_token';
        tokenInput.value = token;
        form.appendChild(tokenInput);
    }

    const localeInput = document.createElement('input');
    localeInput.type = 'hidden';
    localeInput.name = 'locale';
    localeInput.value = targetLocale;
    form.appendChild(localeInput);

    const returnInput = document.createElement('input');
    returnInput.type = 'hidden';
    returnInput.name = 'return_url';
    returnInput.value = returnUrl || window.location.href;
    form.appendChild(returnInput);

    document.body.appendChild(form);
    form.submit();
}

/**
 * Bind public / auth locale forms for instant overlay + native full-page submit.
 */
export function bindLocaleSwitchForms(root: ParentNode = document): void {
    root.querySelectorAll<HTMLFormElement>('form.ref-locale-switch, form.language-switcher').forEach((form) => {
        if (form.dataset.localeBound === '1') {
            return;
        }
        form.dataset.localeBound = '1';

        form.addEventListener('submit', (event) => {
            if (form.dataset.localeSwitching === '1') {
                event.preventDefault();

                return;
            }

            const submitter = (event as SubmitEvent).submitter as HTMLButtonElement | null;
            const hiddenLocale = form.querySelector<HTMLInputElement>('input[name="locale"]')?.value || '';
            const targetLocale = (submitter?.name === 'locale' ? submitter.value : '') || hiddenLocale;

            if (!targetLocale) {
                return;
            }

            // Public EN/BN pills: ignore click on the already-active language.
            if (form.classList.contains('ref-locale-switch') && submitter?.classList.contains('is-active')) {
                event.preventDefault();

                return;
            }

            // Stop the browser for one paint so the overlay is visible, then full-page POST.
            event.preventDefault();
            form.dataset.localeSwitching = '1';
            showLocaleSwitchOverlay(targetLocale);

            const returnInput = form.querySelector<HTMLInputElement>('input[name="return_url"]');
            if (returnInput) {
                returnInput.value = window.location.href;
            }

            // Programmatic form.submit() omits the clicked submit button, so set locale explicitly.
            let localeInput = form.querySelector<HTMLInputElement>('input[name="locale"]');
            if (!localeInput) {
                localeInput = document.createElement('input');
                localeInput.type = 'hidden';
                localeInput.name = 'locale';
                form.appendChild(localeInput);
            }
            localeInput.value = targetLocale;

            form.querySelectorAll<HTMLButtonElement>('button').forEach((btn) => {
                btn.disabled = true;
                if (btn.name === 'locale') {
                    const on = btn.value === targetLocale;
                    btn.classList.toggle('is-active', on);
                    btn.setAttribute('aria-pressed', String(on));
                }
            });

            const navigate = () => {
                // HTMLFormElement.submit() does not re-dispatch submit — avoids a loop.
                HTMLFormElement.prototype.submit.call(form);
            };

            requestAnimationFrame(() => {
                requestAnimationFrame(navigate);
            });
        });
    });
}
