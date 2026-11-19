import { render, screen } from '@testing-library/react';

import { CallRegisterButton } from '@/components/molecules/CallRegisterButton';
import type { Translate } from '@/lib/translate';

const t: Translate = (key, replacements) => (replacements === undefined ? key : `${key} ${Object.values(replacements).join(' ')}`);

describe('CallRegisterButton', () => {
    it('CA-022-25: is a link to the form when the call is open', () => {
        render(<CallRegisterButton registerUrl="/enviar" state="open" t={t} />);

        expect(screen.getByRole('link', { name: 'submit.send_action' })).toHaveAttribute('href', '/enviar');
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('CA-022-26: is blocked when the call is closed even if a url slips through', () => {
        render(<CallRegisterButton registerUrl="/enviar" state="closed" t={t} />);

        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getByRole('button')).toBeDisabled();
    });

    it('CA-022-26: is blocked without url, with aria-disabled and a screen-reader explanation', () => {
        render(<CallRegisterButton registerUrl={null} state="paused" t={t} />);

        const button = screen.getByRole('button');

        expect(button).toHaveAttribute('aria-disabled', 'true');
        expect(button).toHaveTextContent('landing.register_unavailable call.state.paused');
    });
});
