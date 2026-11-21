/*
 * The hostnames or URLs in pasted import text (SPEC §10.6): one or more per
 * line, separated by spaces. An nginx `server_name a b c;` line counts as its
 * names. Comments, `_`, wildcards, regexes and variables are left out.
 * Mirrors `App\Support\ImportLines`.
 */
function isPattern(token: string): boolean {
    return (
        token === '_' ||
        token.startsWith('~') ||
        token.startsWith('*') ||
        token.startsWith('.') ||
        token.startsWith('$') ||
        token.endsWith('.*')
    );
}

export function parseImportLines(text: string): string[] {
    const entries: string[] = [];

    for (const raw of text.split(/\r?\n/)) {
        const line = raw.replace(/(^|\s)#.*$/, '').trim();
        const tokens = line
            .replace(/[;\s]+$/, '')
            .split(/\s+/)
            .filter((token) => token !== '');

        if (tokens[0] === 'server_name') {
            tokens.shift();
        }

        for (const token of tokens.map((token) => token.replace(/;+$/, ''))) {
            if (token !== '' && !isPattern(token)) {
                entries.push(token);
            }
        }
    }

    return [...new Set(entries)];
}
