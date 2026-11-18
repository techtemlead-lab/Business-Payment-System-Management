/**
 * Where a searchable list's pop-up must be placed.
 *
 * A modal dialog makes everything outside it unusable: a list on the page body still shows, but a click
 * on it counts as a click behind the dialog, which closes the dialog and loses what was typed. Inside a
 * dialog the list therefore has to be a child of that dialog; anywhere else the page body is used.
 */
export function menuHome<T extends { closest: (selector: string) => T | null }>(trigger: T | null | undefined, body: T): T {
    return trigger?.closest('dialog') ?? body;
}
