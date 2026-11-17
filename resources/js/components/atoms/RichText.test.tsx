import { render } from '@testing-library/react';

import { RichText } from '@/components/atoms/RichText';

function renderHtml(html: string): HTMLElement {
    const { container } = render(<RichText html={html} />);
    const root = container.firstElementChild;

    if (!(root instanceof HTMLElement)) {
        throw new Error('RichText no renderizó su contenedor');
    }

    return root;
}

describe('RichText', () => {
    it('renders the allowed formatting', () => {
        const container = renderHtml('<p>Hola <strong>mundo</strong> <em>feliz</em><br>fin</p>');

        expect(container.querySelector('p strong')).toHaveTextContent('mundo');
        expect(container.querySelector('p em')).toHaveTextContent('feliz');
        expect(container.querySelector('p br')).not.toBeNull();
    });

    it('renders nested lists', () => {
        const container = renderHtml('<ul><li>uno<ol><li>uno-a</li><li>uno-b</li></ol></li><li>dos</li></ul>');

        expect(container.querySelectorAll('ul > li')).toHaveLength(2);
        expect(container.querySelectorAll('ul > li > ol > li')).toHaveLength(2);
    });

    it('drops script, style and iframe elements together with their content', () => {
        const container = renderHtml(
            '<p>antes</p><script>window.pwned = 1</script><style>p{display:none}</style><iframe src="https://evil.test">x</iframe><p>despues</p>',
        );

        expect(container.querySelector('script, style, iframe')).toBeNull();
        expect(container).not.toHaveTextContent('pwned');
        expect(container).not.toHaveTextContent('display');
        expect(container).toHaveTextContent('antesdespues');
    });

    it('drops images and event handler attributes', () => {
        const container = renderHtml('<p onclick="alert(1)" style="color:red" class="x" id="y">texto</p><img src="x" onerror="alert(1)">');

        const paragraph = container.querySelector('p');

        expect(container.querySelector('img')).toBeNull();
        expect(paragraph?.getAttributeNames()).toEqual([]);
        expect(paragraph).toHaveTextContent('texto');
    });

    it('keeps only the text of unknown tags', () => {
        const container = renderHtml('<div><h1 onclick="x()">Titulo</h1><span>tras</span></div>');

        expect(container.querySelector('div, h1, span')).toBeNull();
        expect(container).toHaveTextContent('Titulotras');
    });

    it.each(['javascript:alert(1)', ' JaVaScRiPt:alert(1)', 'data:text/html,<b>x</b>', 'vbscript:msgbox(1)', '/relativa', '//evil.test', 'mailto:a@b.test'])(
        'shows the text without a link when the href is %s',
        (href) => {
            const container = renderHtml(`<p><a href="${href}">enlace</a></p>`);

            expect(container.querySelector('a')).toBeNull();
            expect(container.querySelector('p')).toHaveTextContent('enlace');
        },
    );

    it('keeps http and https links with a safe rel and without target', () => {
        const container = renderHtml('<p><a href="https://example.test/a?b=1" target="_blank" onclick="x()">uno</a> <a href="http://example.test">dos</a></p>');

        const links = container.querySelectorAll('a');

        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', 'https://example.test/a?b=1');
        expect(links[1]).toHaveAttribute('href', 'http://example.test/');
        links.forEach((link) => {
            expect(link).toHaveAttribute('rel', 'noopener noreferrer nofollow');
            expect(link).not.toHaveAttribute('target');
            expect(link.getAttributeNames().sort()).toEqual(['class', 'href', 'rel']);
        });
    });

    it('escapes text that looks like markup', () => {
        const container = renderHtml('<p>1 &lt; 2 &amp; &lt;b&gt;no&lt;/b&gt;</p>');

        expect(container.querySelector('b')).toBeNull();
        expect(container.querySelector('p')?.textContent).toBe('1 < 2 & <b>no</b>');
    });

    it('renders nothing for empty html', () => {
        expect(renderHtml('').childNodes).toHaveLength(0);
        expect(renderHtml('   ').textContent?.trim()).toBe('');
        expect(renderHtml('<script>x</script>').childNodes).toHaveLength(0);
    });

    it('neutralizes mixed hostile input', () => {
        const container = renderHtml(
            '<p>ok<svg><script>alert(1)</script></svg><math><mi>x</mi></math></p>' +
                '<a href="javascript:alert(1)"><img src=x onerror=alert(1)>click</a>' +
                '<form action="https://evil.test"><input name="p"><button>Enviar</button></form>' +
                '<object data="x"></object><embed src="x"><template><b>t</b></template><noscript><p>ns</p></noscript>' +
                '<p><a href="https://ok.test" onmouseover="x()">bien</a></p>',
        );

        expect(container.querySelector('script, svg, math, img, form, input, button, object, embed, template, noscript, iframe')).toBeNull();
        expect(container.querySelectorAll('[onclick],[onerror],[onmouseover],[style]')).toHaveLength(0);
        expect(container.querySelectorAll('a')).toHaveLength(1);
        expect(container).not.toHaveTextContent('alert');
        expect(container).toHaveTextContent('bien');
    });
});
