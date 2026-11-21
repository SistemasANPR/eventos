import { useId } from 'react';

import { CallRegisterButton } from '@/components/molecules/CallRegisterButton';
import { CallStateBadge } from '@/components/molecules/CallStateBadge';
import { EventFact } from '@/components/molecules/EventFact';
import { HEADING_MD, LIST_CARD, SECTION_HEADING } from '@/lib/classes';
import { formatEventInstant } from '@/lib/date-format';
import type { Translate } from '@/lib/translate';
import type { PublicCallsBlock } from '@/types/landing';

interface LandingCallsBlockProps {
    block: PublicCallsBlock;
    locale: string;
    t: Translate;
}

/**
 * Convocatorias publicadas del evento con su estado, sus fechas y el botón de registro. Una
 * convocatoria que no está abierta muestra el botón bloqueado (DA-17). Usar en: `LandingRenderer`.
 */
export function LandingCallsBlock({ block, locale, t }: LandingCallsBlockProps) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="flex flex-col gap-4">
            <h2 id={headingId} className={SECTION_HEADING}>
                {block.title ?? t('landing.calls_title')}
            </h2>
            {block.calls.length === 0 ? (
                <p className="text-base text-text-muted">{t('landing.calls_empty')}</p>
            ) : (
                <ul className="grid gap-4 lg:grid-cols-2">
                    {block.calls.map((call, index) => (
                        <li key={`${index}-${call.title}`} className={LIST_CARD}>
                            <h3 className={HEADING_MD}>{call.title}</h3>
                            <div className="flex flex-col items-start gap-2">
                                <CallStateBadge state={call.state} t={t} />
                                <p className="text-base text-text-muted">{t(`call.state_hint.${call.state}`)}</p>
                            </div>
                            <dl className="grid gap-3 sm:grid-cols-3">
                                <EventFact label={t('call.type')} variant="plain">{call.submissionType}</EventFact>
                                <EventFact label={t('call.opens_at')} variant="plain">
                                    {formatEventInstant(call.opensAt, call.timezone, locale, call.timeFormat)}
                                </EventFact>
                                <EventFact label={t('call.closes_at')} variant="plain">
                                    {formatEventInstant(call.closesAt, call.timezone, locale, call.timeFormat)}
                                </EventFact>
                            </dl>
                            <div>
                                <CallRegisterButton registerUrl={call.registerUrl} state={call.state} t={t} />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
