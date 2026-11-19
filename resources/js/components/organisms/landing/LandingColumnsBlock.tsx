import { RichText } from '@/components/atoms/RichText';
import type { PublicColumn, PublicColumnsBlock } from '@/types/landing';

interface LandingColumnsBlockProps {
    block: PublicColumnsBlock;
}

/** Dos columnas (texto o imagen) que se apilan en móvil. Usar en: `LandingRenderer`. */
export function LandingColumnsBlock({ block }: LandingColumnsBlockProps) {
    return (
        <div className="grid gap-6 sm:grid-cols-2">
            {block.columns.map((column, index) => (
                <ColumnContent key={index} column={column} />
            ))}
        </div>
    );
}

function ColumnContent({ column }: { column: PublicColumn }) {
    if (column.kind === 'text') {
        return <RichText html={column.html} />;
    }

    return <img src={column.url} alt={column.alt} loading="lazy" className="w-full rounded-lg border border-border object-cover" />;
}
