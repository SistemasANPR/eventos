import { TextLink } from '@/components/atoms/TextLink';
import { useTranslation } from '@/hooks/useTranslation';
import type { Paginated } from '@/types/program';

interface PageNavProps {
    /** Página actual de la lista; solo se usan la posición y los enlaces. */
    page: Pick<Paginated<unknown>, 'currentPage' | 'lastPage' | 'prevUrl' | 'nextUrl'>;
    /** Nombre accesible de la navegación (p. ej. «Páginas del programa»). */
    label: string;
}

/**
 * Páginas anterior y siguiente de una lista paginada en el servidor, con la posición («Página 2 de 3»).
 * No se dibuja si todo cabe en una página. Usar bajo una lista con `Paginated<T>`; no para filtros del cliente.
 */
export function PageNav({ page, label }: PageNavProps) {
    const { t } = useTranslation();

    if (page.lastPage < 2) {
        return null;
    }

    return (
        <nav aria-label={label} className="flex flex-wrap items-center justify-between gap-2">
            {page.prevUrl !== null ? (
                <TextLink href={page.prevUrl} rel="prev">
                    {t('pagination.previous')}
                </TextLink>
            ) : (
                <span />
            )}
            <span className="text-base text-text-muted">{t('pagination.page_of', { current: String(page.currentPage), last: String(page.lastPage) })}</span>
            {page.nextUrl !== null ? (
                <TextLink href={page.nextUrl} rel="next">
                    {t('pagination.next')}
                </TextLink>
            ) : (
                <span />
            )}
        </nav>
    );
}
