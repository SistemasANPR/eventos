import { fireEvent, render, screen } from '@testing-library/react';

import { EventLogo } from '@/components/atoms/EventLogo';

describe('EventLogo', () => {
    it('CA-027-13: shows the logo with the event name as alternative text', () => {
        render(<EventLogo src="/parques/events/congreso/logo?v=ab12" name="Congreso Parques" />);

        expect(screen.getByRole('img', { name: 'Congreso Parques' })).toHaveAttribute('src', '/parques/events/congreso/logo?v=ab12');
    });

    it('CA-027-13: draws nothing without a logo, so there is no gap or broken image', () => {
        const { container, rerender } = render(<EventLogo src={null} name="Congreso Parques" />);

        expect(container).toBeEmptyDOMElement();

        rerender(<EventLogo src={undefined} name="Congreso Parques" />);
        expect(container).toBeEmptyDOMElement();

        rerender(<EventLogo src="" name="Congreso Parques" />);
        expect(container).toBeEmptyDOMElement();
    });

    it('CA-027-13: removes the image when it fails to load', () => {
        const { container } = render(<EventLogo src="/roto" name="Congreso Parques" />);

        fireEvent.error(screen.getByRole('img'));

        expect(container).toBeEmptyDOMElement();
    });
});
