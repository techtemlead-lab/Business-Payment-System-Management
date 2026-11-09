type Translate = (group: string, key: string, replace?: Record<string, string | number>) => string;

/**
 * How long ago the courier last answered, in plain words. The minutes come from the server so a
 * wrong clock on the dealer's phone cannot change them. After a day the date and time read better.
 */
export function courierCheckedText(
    minutesAgo: number,
    answeredAt: string,
    translate: Translate,
    formatDateTime: (value: string) => string,
): string {
    if (minutesAgo < 1) {
        return translate('courier', 'checked_just_now');
    }
    if (minutesAgo === 1) {
        return translate('courier', 'checked_one_minute_ago');
    }
    if (minutesAgo < 60) {
        return translate('courier', 'checked_minutes_ago', { count: minutesAgo });
    }
    const hoursAgo = Math.floor(minutesAgo / 60);
    if (hoursAgo === 1) {
        return translate('courier', 'checked_one_hour_ago');
    }

    return hoursAgo < 24 ? translate('courier', 'checked_hours_ago', { count: hoursAgo }) : formatDateTime(answeredAt);
}
