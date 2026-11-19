import { useId } from 'react';

import { RichText } from '@/components/atoms/RichText';
import { HEADING_MD, LIST_CARD, SECTION_HEADING } from '@/lib/classes';
import type { Translate } from '@/lib/translate';
import type { PublicFaqBlock } from '@/types/landing';

interface LandingFaqBlockProps {
    block: PublicFaqBlock;
    t: Translate;
}

/** Preguntas frecuentes: cada pregunta es un h3 y su respuesta, texto con formato. Usar en: `LandingRenderer`. */
export function LandingFaqBlock({ block, t }: LandingFaqBlockProps) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4">
            <h2 id={headingId} className={SECTION_HEADING}>
                {block.title ?? t('landing.faq_title')}
            </h2>
            <ul className="flex flex-col gap-4">
                {block.items.map((item, index) => (
                    <li key={`${index}-${item.question}`} className={LIST_CARD}>
                        <h3 className={HEADING_MD}>{item.question}</h3>
                        <RichText html={item.answerHtml} />
                    </li>
                ))}
            </ul>
        </section>
    );
}
