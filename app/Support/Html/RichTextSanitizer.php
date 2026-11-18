<?php

declare(strict_types=1);

namespace App\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanea el texto enriquecido del programa (biografía del ponente, descripción de una actividad) con
 * una lista blanca (plan 021, DA-16, CA-021-2). Se aplica al GUARDAR y puede aplicarse otra vez al
 * mostrar (es idempotente). Conserva `p, br, strong, b, em, i, ul, ol, li, a, h2, h3, blockquote`;
 * los enlaces solo pueden ser `https`, sin `target`, con `rel="noopener noreferrer nofollow"`. Se
 * eliminan `script`, `style`, `iframe`, `svg`, atributos `on*` y `style`, `javascript:`, `data:`,
 * comentarios y las imágenes embebidas; las etiquetas inofensivas fuera de la lista pierden la
 * etiqueta pero conservan su texto.
 *
 * Usa `symfony/html-sanitizer` (el mismo que el aviso de privacidad, `NoticeSanitizer`): sanear HTML
 * a mano es una fuente clásica de XSS. API estable: `RichTextSanitizer::clean(string): string`.
 */
final class RichTextSanitizer
{
    /** Tope de entrada: una biografía razonable cabe de sobra; evita abusos de memoria. */
    private const MAX_INPUT_LENGTH = 100_000;

    /** Etiquetas fuera de la lista blanca cuyo texto se conserva. */
    private const HARMLESS_WRAPPERS = [
        'div', 'span', 'section', 'article', 'header', 'footer', 'main', 'aside', 'figure', 'figcaption',
        'h1', 'h4', 'h5', 'h6', 'pre', 'code', 'u', 's', 'small', 'sub', 'sup', 'mark', 'del', 'ins', 'center', 'font', 'label',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
    ];

    private static ?HtmlSanitizer $sanitizer = null;

    /** Devuelve el HTML saneado, o cadena vacía si no queda texto visible. */
    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $clean = trim(self::sanitizer()->sanitize($html));

        // `<p></p>` (lo que emite un editor vacío) no es contenido.
        return trim(strip_tags($clean)) === '' ? '' : $clean;
    }

    private static function sanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer !== null) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('blockquote')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        // Los elementos peligrosos (script, style, iframe, object...) no están aquí: el
        // sanitizador los descarta con todo su contenido.
        foreach (self::HARMLESS_WRAPPERS as $element) {
            $config = $config->blockElement($element);
        }

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}
