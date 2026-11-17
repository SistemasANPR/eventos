import type { ComponentType } from 'react';

import { RichTextDemo, TextLinkDemo } from '@/catalog/demos-atoms';
import { SkipToContentDemo } from '@/catalog/demos-infrastructure';
import { EventFactDemo, LocaleSwitcherDemo } from '@/catalog/demos-molecules';
import { EventHeroDemo } from '@/catalog/demos-organisms';

/** Demo ejecutable de cada entrada del catálogo con `preview: 'demo'`, por el nombre de la entrada. */
export const demos: Record<string, ComponentType> = {
    RichText: RichTextDemo,
    TextLink: TextLinkDemo,
    EventFact: EventFactDemo,
    LocaleSwitcher: LocaleSwitcherDemo,
    EventHero: EventHeroDemo,
    SkipToContent: SkipToContentDemo,
};
