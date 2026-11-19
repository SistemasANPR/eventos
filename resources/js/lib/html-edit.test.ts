import { applyFormat, isHttpsUrl } from '@/lib/html-edit';

describe('html-edit', () => {
    it('CA-022-36: wraps the selection in strong or em and keeps it selected', () => {
        const bold = applyFormat('Hola mundo', 5, 10, 'bold');

        expect(bold.value).toBe('Hola <strong>mundo</strong>');
        expect(bold.value.slice(bold.selectionStart, bold.selectionEnd)).toBe('mundo');
        expect(applyFormat('Hola', 0, 4, 'italic').value).toBe('<em>Hola</em>');
    });

    it('CA-022-36: turns the selected lines into a list', () => {
        expect(applyFormat('uno\ndos', 0, 7, 'list').value).toBe('<ul><li>uno</li><li>dos</li></ul>');
        expect(applyFormat('', 0, 0, 'list').value).toBe('<ul><li></li></ul>');
    });

    it('CA-022-12: builds a link and escapes quotes in the address', () => {
        expect(applyFormat('sitio', 0, 5, 'link', 'https://example.test/?a="b"').value).toBe(
            '<a href="https://example.test/?a=&quot;b&quot;">sitio</a>',
        );
    });

    it('CA-022-12: only an absolute https address is accepted', () => {
        expect(isHttpsUrl('https://example.test')).toBe(true);
        expect(isHttpsUrl('http://example.test')).toBe(false);
        expect(isHttpsUrl('javascript:alert(1)')).toBe(false);
        expect(isHttpsUrl('data:text/html,hi')).toBe(false);
        expect(isHttpsUrl('example.test')).toBe(false);
    });
});
