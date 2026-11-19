import { BLOCK_TYPES, canAddBlock, createBlock, duplicateBlock, LANDING_LIMITS, moveBlock, removeBlock } from '@/lib/landing-blocks';
import type { LandingBlockDraft } from '@/types/landing';

const locales = ['es', 'en'];

function three(): LandingBlockDraft[] {
    return [createBlock('hero', locales), createBlock('spacer', locales), createBlock('calls', locales)];
}

describe('landing block operations', () => {
    it('CA-022-37: creates a valid starting block of every type with a text per event language', () => {
        for (const type of BLOCK_TYPES) {
            const block = createBlock(type, locales);

            expect(block.type).toBe(type);
            expect(block.id).toMatch(/^new-\d+$/);
        }

        const hero = createBlock('hero', locales);
        expect(hero.type === 'hero' && Object.keys(hero.title)).toEqual(['es', 'en']);
    });

    it('CA-022-37: moves a block up and down and ignores the ends', () => {
        const blocks = three();
        const [first, second, third] = blocks;

        expect(moveBlock(blocks, 1, -1).map((block) => block.id)).toEqual([second?.id, first?.id, third?.id]);
        expect(moveBlock(blocks, 1, 1).map((block) => block.id)).toEqual([first?.id, third?.id, second?.id]);
        expect(moveBlock(blocks, 0, -1)).toBe(blocks);
        expect(moveBlock(blocks, 2, 1)).toBe(blocks);
    });

    it('CA-022-37: duplicates a block right below with new identifiers and without sharing nested data', () => {
        const blocks = three();
        const faq = createBlock('faq', locales);
        const result = duplicateBlock([...blocks, faq], 3);

        const [original, copy] = [result[3], result[4]];

        expect(result).toHaveLength(5);
        expect(copy?.id).not.toBe(original?.id);

        if (original?.type === 'faq' && copy?.type === 'faq') {
            expect(copy.items[0]?.key).not.toBe(original.items[0]?.key);
            expect(copy.items[0]?.question).not.toBe(original.items[0]?.question);
        }
    });

    it('CA-022-37: removes the block at a position', () => {
        const blocks = three();

        expect(removeBlock(blocks, 1).map((block) => block.type)).toEqual(['hero', 'calls']);
    });

    it('CA-022-10: stops offering new blocks at the limit of 30', () => {
        const full = Array.from({ length: LANDING_LIMITS.blocks }, () => createBlock('spacer', locales));

        expect(canAddBlock(full)).toBe(false);
        expect(canAddBlock(full.slice(1))).toBe(true);
    });
});
