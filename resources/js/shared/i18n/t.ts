import { usePage } from '@inertiajs/react';

type Dict = Record<string, Record<string, string>>;

export function useTranslations(): Dict {
    return (usePage<any>().props.translations ?? {}) as Dict;
}

export function t(dict: Dict, group: string, key: string, replace: Record<string, string> = {}): string {
    let value = dict?.[group]?.[key] ?? key;
    for (const [name, content] of Object.entries(replace)) {
        value = value.replaceAll(`:${name}`, content);
    }
    return value;
}

export function useT() {
    const dict = useTranslations();
    return (group: string, key: string, replace: Record<string, string> = {}) => t(dict, group, key, replace);
}
