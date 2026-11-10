import { cn } from '@/lib/utils';

describe('cn', () => {
    it('joins the given classes', () => {
        expect(cn('a', 'b')).toBe('a b');
    });

    it('omits false, null, undefined and empty values', () => {
        expect(cn('a', false, null, undefined, '', 'b')).toBe('a b');
    });

    it('supports conditional classes', () => {
        const isActive = true;
        const isDisabled = false;

        expect(cn('base', isActive && 'active', isDisabled && 'disabled')).toBe('base active');
    });
});
