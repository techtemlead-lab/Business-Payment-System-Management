import { router } from '@inertiajs/react';

/** True when a background request was answered with the sign-in page (or a session-expired status) instead of page data. */
function isSignInAnswer(status: number, data: unknown): boolean {
    if (status === 401 || status === 419) {
        return true;
    }

    return typeof data === 'string' && /<input[^>]+name=["']password["']/i.test(data) && /<input[^>]+name=["']email["']/i.test(data);
}

/**
 * When the session ends (a deploy, a long break, a signed-out tab) a background refresh of the page gets the sign-in page back.
 * Inertia would show that page inside a pop-up on top of the dashboard. Instead the whole window goes to the sign-in page,
 * and it brings the partner back to where they were after signing in.
 */
export function installSessionGuard(): () => void {
    return router.on('httpException', (event) => {
        const { status, data } = event.detail.response;
        if (!isSignInAnswer(status, data)) {
            return undefined;
        }
        window.location.assign(window.location.href);

        return false;
    });
}
