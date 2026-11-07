/*
 * Madina portal push notifications.
 * Shows a phone/browser notification when the server sends one, and opens the
 * portal's notifications page when it is tapped. It does not cache pages.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { title: event.data ? event.data.text() : '' };
    }

    const title = data.title || 'Madina Natural Health Care';
    const options = {
        body: data.body || '',
        icon: data.icon || '/reference/favicon.png?v=8',
        badge: data.badge || '/favicon.ico',
        tag: data.tag || undefined,
        lang: data.lang || undefined,
        data: { url: (data.data && data.data.url) || data.url || '/notifications' },
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL((event.notification.data && event.notification.data.url) || '/notifications', self.location.origin);
    if (target.origin !== self.location.origin) {
        return;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            // This worker does not control portal pages, so it cannot steer an open tab;
            // focus one already on the notifications page, otherwise open it.
            for (const client of windows) {
                if (client.url.split('#')[0] === target.href && 'focus' in client) {
                    return client.focus();
                }
            }

            return self.clients.openWindow(target.href);
        }),
    );
});
