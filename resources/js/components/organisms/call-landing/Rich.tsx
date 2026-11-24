import { Fragment } from 'react';

import type { RichText } from '@/types/call-landing';

/** Dibuja un texto con partes en negrita (`**así**`) sin usar HTML crudo. */
export function Rich({ text }: { text: RichText }) {
    return (
        <>
            {text
                .split('**')
                .map((part, index) => (index % 2 === 1 ? <strong key={index}>{part}</strong> : <Fragment key={index}>{part}</Fragment>))}
        </>
    );
}
