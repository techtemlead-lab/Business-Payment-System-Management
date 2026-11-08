/**
 * Sign-in screens: decorative motion only.
 *
 * Nothing here reads, changes or blocks what the forms send. It only adds
 * classes and decorative elements (all aria-hidden) for the styles in
 * resources/css/auth-motion.css:
 * - a gentle pointer parallax on the leaf background (desktop, full motion only);
 * - a light sweep over the logo;
 * - a growing line under the field in focus;
 * - a spinner on the submit button after a form is sent. The button is never
 *   disabled, and the spinner clears itself, so sending again always works.
 */

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)');

function decorativeSpan(className: string): HTMLSpanElement {
    const span = document.createElement('span');
    span.className = className;
    span.setAttribute('aria-hidden', 'true');

    return span;
}

function startParallax(scene: HTMLElement): void {
    let targetX = 0;
    let targetY = 0;
    let currentX = 0;
    let currentY = 0;
    let frame = 0;

    const step = (): void => {
        currentX += (targetX - currentX) * 0.06;
        currentY += (targetY - currentY) * 0.06;
        scene.style.setProperty('--am-px', currentX.toFixed(3));
        scene.style.setProperty('--am-py', currentY.toFixed(3));
        frame = Math.abs(targetX - currentX) + Math.abs(targetY - currentY) > 0.002 ? requestAnimationFrame(step) : 0;
    };

    window.addEventListener('pointermove', (event: PointerEvent) => {
        if (reducedMotion.matches || !finePointer.matches || event.pointerType !== 'mouse') {
            return;
        }
        targetX = (event.clientX / window.innerWidth - 0.5) * 2;
        targetY = (event.clientY / window.innerHeight - 0.5) * 2;
        if (frame === 0) {
            frame = requestAnimationFrame(step);
        }
    }, { passive: true });

    document.documentElement.addEventListener('pointerleave', () => {
        targetX = 0;
        targetY = 0;
        if (frame === 0) {
            frame = requestAnimationFrame(step);
        }
    });
}

function decorateBrand(image: HTMLImageElement): void {
    const parent = image.parentElement;
    if (!parent || parent.classList.contains('am-brand-mark')) {
        return;
    }
    const mark = decorativeSpan('am-brand-mark');
    mark.removeAttribute('aria-hidden');
    parent.insertBefore(mark, image);
    mark.appendChild(image);

    const shine = decorativeSpan('am-shine');
    shine.appendChild(decorativeSpan('am-shine-band'));
    mark.appendChild(shine);

    const setMask = (): void => {
        const source = image.currentSrc || image.src;
        mark.style.setProperty('--am-logo', `url("${source.replace(/"/g, '%22')}")`);
    };
    setMask();
    image.addEventListener('load', setMask);
}

function decorateField(input: HTMLInputElement): void {
    const parent = input.parentElement;
    if (!parent || parent.classList.contains('am-field')) {
        return;
    }

    let field: HTMLElement;
    if (parent.classList.contains('ref-password-field')) {
        field = parent;
    } else {
        const hadFocus = document.activeElement === input;
        field = document.createElement('span');
        parent.insertBefore(field, input);
        field.appendChild(input);
        if (hadFocus) {
            input.focus({ preventScroll: true });
        }
    }

    field.classList.add('am-field');
    if (input.name === 'code') {
        field.classList.add('am-field-code');
    }
    if (input.getAttribute('aria-invalid') === 'true') {
        field.classList.add('am-field-invalid');
    }
    field.appendChild(decorativeSpan('am-field-line'));
}

function clearSpinners(): void {
    document.querySelectorAll('.am-loading').forEach((button) => button.classList.remove('am-loading'));
}

function watchSubmits(): void {
    document.addEventListener('submit', (event: SubmitEvent) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.closest('.ref-auth-card, .company-auth-card')) {
            return;
        }
        const submitter = event.submitter instanceof HTMLButtonElement
            ? event.submitter
            : form.querySelector<HTMLButtonElement>('button[type="submit"], button:not([type])');
        if (!submitter) {
            return;
        }

        // Read the outcome after every other handler has run: if the send was cancelled, show nothing.
        window.setTimeout(() => {
            if (event.defaultPrevented) {
                return;
            }
            clearSpinners();
            submitter.classList.add('am-loading');
            window.setTimeout(() => submitter.classList.remove('am-loading'), 12000);
        }, 0);
    });

    // A page shown again from the back / forward cache must not keep a spinner.
    window.addEventListener('pageshow', clearSpinners);
}

function watchCaptcha(): void {
    const image = document.querySelector<HTMLImageElement>('[data-login-captcha-image]');
    if (!image) {
        return;
    }
    image.addEventListener('load', () => {
        image.classList.remove('am-captcha-fresh');
        void image.offsetWidth;
        image.classList.add('am-captcha-fresh');
    });
}

function boot(): void {
    if (!document.body.classList.contains('am-auth')) {
        return;
    }

    const scene = document.querySelector<HTMLElement>('.am-scene');
    if (scene) {
        startParallax(scene);
    }

    document.querySelectorAll<HTMLImageElement>('.ref-auth-brand img, .company-auth-brand img').forEach(decorateBrand);

    document.querySelectorAll<HTMLInputElement>(
        ':is(.ref-auth-card, .company-auth-card) form input:is([type="text"], [type="email"], [type="password"])',
    ).forEach(decorateField);

    watchSubmits();
    watchCaptcha();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
