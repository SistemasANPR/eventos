import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { COUNTRY_CODES } from '@/lib/countries';

describe('COUNTRY_CODES', () => {
    it('CA-005-30: is the same list the server validates against', () => {
        const php = readFileSync(join(process.cwd(), 'app/Support/Forms/Countries.php'), 'utf8');
        const serverCodes = [...php.matchAll(/'([A-Z]{2})'/g)].map((match) => match[1]);

        expect([...COUNTRY_CODES]).toEqual(serverCodes);
    });

    it('has no duplicates and only two-letter uppercase codes', () => {
        expect(new Set(COUNTRY_CODES).size).toBe(COUNTRY_CODES.length);
        expect(COUNTRY_CODES.every((code) => /^[A-Z]{2}$/.test(code))).toBe(true);
    });
});
