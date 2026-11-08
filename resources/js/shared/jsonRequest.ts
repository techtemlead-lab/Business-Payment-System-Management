/** Same-origin JSON calls for office tools that load after the page, so they never add to a page's queries. */
export function csrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) {
        return meta;
    }
    const xsrf = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return xsrf ? decodeURIComponent(xsrf.split('=').slice(1).join('=')) : '';
}

export class JsonRequestError extends Error {
    constructor(public readonly status: number, message: string) {
        super(message);
    }
}

export async function jsonRequest<T>(url: string, options: { method?: string; body?: unknown; signal?: AbortSignal } = {}): Promise<T> {
    const method = options.method ?? 'GET';
    const token = csrfToken();
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        signal: options.signal,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(method === 'GET' ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-XSRF-TOKEN': token }),
        },
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    if (!response.ok) {
        const payload = await response.json().catch(() => null) as { message?: string } | null;
        throw new JsonRequestError(response.status, payload?.message ?? String(response.status));
    }

    return await response.json() as T;
}

/** Sends a normal form post so the browser saves the file or opens the print page in a new tab. */
export function submitFormPost(url: string, fields: Record<string, string | string[]>, target: '_blank' | '_self' = '_self'): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = url;
    form.target = target;
    const add = (name: string, value: string) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };
    add('_token', csrfToken());
    for (const [name, value] of Object.entries(fields)) {
        if (Array.isArray(value)) {
            value.forEach((item) => add(`${name}[]`, item));
        } else {
            add(name, value);
        }
    }
    document.body.appendChild(form);
    form.submit();
    form.remove();
}
