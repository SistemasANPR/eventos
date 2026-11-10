<?php

declare(strict_types=1);

namespace App\Support\Privacy;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitiza el texto del aviso de privacidad ANTES de guardarlo (plan 003, DA-9,
 * CA-003-15): lista blanca de etiquetas de formato (párrafos, saltos, negrita,
 * énfasis, listas y enlaces `http`/`https`). Todo lo demás se elimina en el
 * servidor; no se rechaza el texto ni se guarda el original. Los scripts, los
 * estilos y sus contenidos se descartan por completo.
 *
 * Usa `symfony/html-sanitizer` (vetado y mantenido) en lugar de un filtro propio:
 * sanear HTML a mano es una fuente clásica de XSS.
 */
final class NoticeSanitizer
{
    /** Tope de entrada: un aviso razonable cabe de sobra; evita abusos de memoria. */
    private const MAX_INPUT_LENGTH = 100_000;

    /** Etiquetas fuera de la lista blanca cuyo texto se conserva. */
    private const HARMLESS_WRAPPERS = [
        'b', 'i', 'div', 'span', 'section', 'article', 'header', 'footer', 'main', 'aside', 'figure', 'figcaption',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'code', 'u', 's', 'small', 'sub', 'sup',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'center', 'font', 'label', 'form',
    ];

    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['http', 'https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        // Envoltorios inofensivos (títulos, citas, tablas...): se quita la etiqueta pero se
        // CONSERVA su texto, para no borrar en silencio lo que escribió la persona. Los
        // elementos peligrosos (script, style, iframe, object...) no están aquí: el
        // sanitizador los descarta con todo su contenido.
        foreach (self::HARMLESS_WRAPPERS as $element) {
            $config = $config->blockElement($element);
        }

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        return trim($this->sanitizer->sanitize($html));
    }
}
