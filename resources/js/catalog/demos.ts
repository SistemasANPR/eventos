import type { ComponentType } from 'react';

import { RichTextDemo, TextLinkDemo } from '@/catalog/demos-atoms';
import { SkipToContentDemo } from '@/catalog/demos-infrastructure';
import {
    CallRegisterButtonDemo,
    CallStateBadgeDemo,
    LandingCallsBlockDemo,
    LandingColumnsBlockDemo,
    LandingEventInfoBlockDemo,
    LandingFaqBlockDemo,
    LandingGalleryBlockDemo,
    LandingHeroBlockDemo,
    LandingRendererDemo,
    LandingRichTextBlockDemo,
    LandingSpacerBlockDemo,
    LandingTracksBlockDemo,
} from '@/catalog/demos-landing';
import { ActivityCardDemo, CategoryFilterDemo, EventFactDemo, LocaleSwitcherDemo, ProgramDayNavDemo } from '@/catalog/demos-molecules';
import { EventHeroDemo } from '@/catalog/demos-organisms';

/** Demo ejecutable de cada entrada del catálogo con `preview: 'demo'`, por el nombre de la entrada. */
export const demos: Record<string, ComponentType> = {
    RichText: RichTextDemo,
    TextLink: TextLinkDemo,
    EventFact: EventFactDemo,
    LocaleSwitcher: LocaleSwitcherDemo,
    ProgramDayNav: ProgramDayNavDemo,
    ActivityCard: ActivityCardDemo,
    CategoryFilter: CategoryFilterDemo,
    EventHero: EventHeroDemo,
    SkipToContent: SkipToContentDemo,
    CallStateBadge: CallStateBadgeDemo,
    CallRegisterButton: CallRegisterButtonDemo,
    LandingRenderer: LandingRendererDemo,
    LandingHeroBlock: LandingHeroBlockDemo,
    LandingRichTextBlock: LandingRichTextBlockDemo,
    LandingEventInfoBlock: LandingEventInfoBlockDemo,
    LandingCallsBlock: LandingCallsBlockDemo,
    LandingTracksBlock: LandingTracksBlockDemo,
    LandingFaqBlock: LandingFaqBlockDemo,
    LandingGalleryBlock: LandingGalleryBlockDemo,
    LandingSpacerBlock: LandingSpacerBlockDemo,
    LandingColumnsBlock: LandingColumnsBlockDemo,
};
