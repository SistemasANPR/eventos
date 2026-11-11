import { contrastRatio } from '@/lib/contrast';

/** Los pares de tokens y su contraste contra el CSS real los vigila `tokens.test.ts`. */
describe('contrastRatio', () => {
    it('is 21 between black and white', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5);
    });

    it('is 1 between a color and itself', () => {
        expect(contrastRatio('#1d4ed8', '#1d4ed8')).toBeCloseTo(1, 5);
    });

    it('is symmetric', () => {
        expect(contrastRatio('#1c1917', '#ffffff')).toBeCloseTo(contrastRatio('#ffffff', '#1c1917'), 10);
    });

    it('rejects a color that is not #rrggbb', () => {
        expect(() => contrastRatio('red', '#ffffff')).toThrow();
    });
});
