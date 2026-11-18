// Finds every <input>/<textarea> in the React code whose value is a Bangla twin of an English box, so a test can
// require each one to use the English-first helper (CR-06). Shared by tests/Unit/bangla-everywhere.test.mjs.
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

/** A Bangla value: name_bn, titleBn, setDraftBn(…), lang="bn", or a key/field that ends in _bn. */
const BanglaBinding = /(?:\w_bn\b|[a-z]Bn\b|lang=["']bn["']|\.bn\b|\[['"]bn['"]\])/;
/** An element already handled: the helper's attribute or call, or a box marked Bangla-only on purpose. */
const Handled = /data-bangla-from|bilingualBox(?:For)?\(|data-bangla-manual/;

function files(dir) {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        return statSync(path).isDirectory() ? files(path) : /\.tsx$/.test(name) ? [path] : [];
    });
}

/** The full opening tag starting at `start`, following { } nesting and quotes, so attributes over several lines count. */
function openingTag(source, start) {
    let depth = 0;
    let quote = null;
    for (let index = start; index < source.length; index++) {
        const character = source[index];
        if (quote) {
            if (character === quote && source[index - 1] !== '\\') {
                quote = null;
            }
            continue;
        }
        if (character === '"' || character === "'" || character === '`') {
            quote = character;
        } else if (character === '{') {
            depth++;
        } else if (character === '}') {
            depth--;
        } else if (character === '>' && depth === 0) {
            return source.slice(start, index + 1);
        }
    }

    return source.slice(start);
}

/**
 * @returns {{file: string, line: number, tag: string, handled: boolean}[]}
 */
export function banglaBoxes(root) {
    const found = [];
    for (const file of files(join(root, 'resources/js'))) {
        const source = readFileSync(file, 'utf8');
        for (const match of source.matchAll(/<(input|textarea)\b/g)) {
            const tag = openingTag(source, match.index);
            if (/type=["'](checkbox|radio|file|hidden|number|date|color|range)["']/.test(tag) || !BanglaBinding.test(tag)) {
                continue;
            }
            found.push({
                file: relative(root, file).replaceAll('\\', '/'),
                line: source.slice(0, match.index).split('\n').length,
                tag: tag.replace(/\s+/g, ' ').slice(0, 220),
                handled: Handled.test(tag),
            });
        }
    }

    return found;
}

if (process.argv[2] === '--list') {
    for (const box of banglaBoxes(process.cwd())) {
        console.log(`${box.handled ? 'OK  ' : 'TODO'} ${box.file}:${box.line}  ${box.tag}`);
    }
}
