import { useTranslation } from '@/hooks/useTranslation';
import { FOCUS_RING } from '@/lib/classes';
import { cn } from '@/lib/utils';
import type { PublicCategory } from '@/types/program';

interface CategoryFilterProps {
    categories: PublicCategory[];
    /** Categoría activa, o `null` si se ven todas. */
    selectedId: string | null;
    /** Con la categoría pulsada, o `null` al soltar la que estaba activa. */
    onSelect: (categoryId: string | null) => void;
}

/**
 * Botones de alternancia (`aria-pressed`) para filtrar en el cliente las actividades ya recibidas por
 * categoría: pulsar activa, pulsar de nuevo muestra todas. Son botones nativos: Tab y Enter o Espacio.
 * Usar sobre una lista que ya está en pantalla; no para filtrar en el servidor ni para navegar.
 */
export function CategoryFilter({ categories, selectedId, onSelect }: CategoryFilterProps) {
    const { t } = useTranslation();

    if (categories.length === 0) {
        return null;
    }

    return (
        <div role="group" aria-label={t('program.filter_label')} className="flex flex-wrap gap-2">
            {categories.map((category) => {
                const isPressed = category.id === selectedId;

                return (
                    <button
                        key={category.id}
                        type="button"
                        aria-pressed={isPressed}
                        onClick={() => onSelect(isPressed ? null : category.id)}
                        className={cn(
                            'inline-flex min-h-11 items-center rounded-full border-2 px-4 text-base font-semibold',
                            FOCUS_RING,
                            isPressed ? 'border-primary bg-primary text-primary-contrast' : 'border-primary bg-surface text-primary hover:bg-bg',
                        )}
                    >
                        {category.name}
                    </button>
                );
            })}
        </div>
    );
}
