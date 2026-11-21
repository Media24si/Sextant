import { describe, expect, it } from 'vite-plus/test';
import { parseImportLines } from '@/lib/importLines';

/* Mirrors tests/Unit/ImportLinesTest.php: both sides must parse alike. */
describe('parseImportLines', () => {
    it.each([
        [
            'one URL per line',
            'svet24.si\n https://podjetje.svet24.si \n\noglasi24.si/novice',
            ['svet24.si', 'https://podjetje.svet24.si', 'oglasi24.si/novice'],
        ],
        [
            'nginx server_name line',
            '    server_name backend.svet24.si backend.reporter.si   backend.bestfm.si;',
            ['backend.svet24.si', 'backend.reporter.si', 'backend.bestfm.si'],
        ],
        [
            'several per line',
            'svet24.si reporter.si',
            ['svet24.si', 'reporter.si'],
        ],
        [
            'comments',
            '# old sites\nsvet24.si # main\nserver_name a.si; # backend',
            ['svet24.si', 'a.si'],
        ],
        [
            'catch-all, wildcards, regexes and variables',
            'server_name _ *.svet24.si .reporter.si www.bestfm.* ~^(.+)\\.si$ $host api.svet24.si;',
            ['api.svet24.si'],
        ],
        [
            'duplicates',
            'svet24.si\nserver_name svet24.si reporter.si;',
            ['svet24.si', 'reporter.si'],
        ],
    ])('%s', (_name, text, entries) => {
        expect(parseImportLines(text)).toEqual(entries);
    });
});
