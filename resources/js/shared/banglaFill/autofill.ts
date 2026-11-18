import { type BanglaKind, shouldFill } from './rules.ts';

/**
 * English first, Bangla filled in (CR-06). A Bangla box opts in with two attributes:
 *
 *     <input id="acf-name-en" … />
 *     <input data-bangla-from="acf-name-en" data-bangla-kind="name" … />
 *
 * When the person leaves the English box, an empty Bangla box is filled from it. A Bangla value that was typed
 * or corrected is never replaced, and nothing happens when the model is not installed on the server or the
 * computer is too small: the Bangla box is then filled by hand, as before.
 */

type Box = HTMLInputElement | HTMLTextAreaElement;

const isBox = (element: EventTarget | null): element is Box => element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement;

function banglaBoxesFor(english: Box): Box[] {
    if (!english.id) {
        return [];
    }

    return Array.from(document.querySelectorAll(`[data-bangla-from="${CSS.escape(english.id)}"]`)).filter(isBox);
}

/** Sets a value the way typing would, so React's form state sees it too. */
function typeInto(box: Box, value: string): void {
    const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(box), 'value')?.set;
    setter?.call(box, value);
    box.dispatchEvent(new Event('input', { bubbles: true }));
}

/** Fills one Bangla box from its English box. Resolves to true when it wrote something. */
export async function fillBanglaBox(bangla: Box): Promise<boolean> {
    const english = document.getElementById(bangla.dataset.banglaFrom ?? '');
    if (!isBox(english) || !shouldFill(english.value, bangla.value) || bangla.dataset.banglaState === 'working') {
        return false;
    }
    const { checkBanglaModel, englishToBangla } = await import('./banglaModel.ts');
    if (!(await checkBanglaModel()).ready) {
        return false;
    }
    const asked = english.value;
    const placeholder = bangla.placeholder;
    bangla.dataset.banglaState = 'working';
    bangla.setAttribute('aria-busy', 'true');
    bangla.placeholder = '…';
    try {
        const written = await englishToBangla(asked, (bangla.dataset.banglaKind === 'name' ? 'name' : 'text') as BanglaKind);
        // The person may have typed Bangla or changed the English while the model worked: theirs wins.
        if (written === '' || english.value !== asked || bangla.value.trim() !== '') {
            delete bangla.dataset.banglaState;

            return false;
        }
        typeInto(bangla, written);
        bangla.dataset.banglaState = 'filled';

        return true;
    } catch (error) {
        delete bangla.dataset.banglaState;
        console.warn('Automatic Bangla failed; the box is left for typing by hand.', error);

        return false;
    } finally {
        bangla.removeAttribute('aria-busy');
        bangla.placeholder = placeholder;
    }
}

let installed = false;

/** Called once from app.tsx; it watches the whole page, so a form only adds the two attributes. */
export function installBanglaAutofill(): void {
    if (installed || typeof document === 'undefined') {
        return;
    }
    installed = true;
    // A filled box is tinted until the person checks or changes it.
    const style = document.createElement('style');
    style.textContent = '[data-bangla-state="filled"]{background-color:#fff8db}[data-bangla-state="working"]{opacity:.7}@media (prefers-color-scheme:dark){[data-bangla-state="filled"]{background-color:#3a3420}}';
    document.head.append(style);

    document.addEventListener('focusin', (event) => {
        if (isBox(event.target) && banglaBoxesFor(event.target).length > 0) {
            void import('./banglaModel.ts').then(({ warmUpBanglaModel }) => warmUpBanglaModel());
        }
    });
    document.addEventListener('focusout', (event) => {
        if (isBox(event.target)) {
            for (const bangla of banglaBoxesFor(event.target)) {
                void fillBanglaBox(bangla);
            }
        }
    });
    document.addEventListener('input', (event) => {
        // A person's own change to a filled box ends the "please check" tint.
        if (isBox(event.target) && event.isTrusted && event.target.dataset.banglaState === 'filled') {
            delete event.target.dataset.banglaState;
        }
    });
}
