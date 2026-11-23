import { render, screen } from '@testing-library/react';

import { SubmissionBlock } from '@/components/organisms/SubmissionBlock';
import type { Translate } from '@/lib/translate';

/** Traductor de prueba: devuelve la clave (y los valores de sustitución). */
const t: Translate = (key, replacements) => (replacements === undefined ? key : `${key} ${Object.values(replacements).join(' ')}`);

function renderBlock(props: { showDetails?: boolean; maxParticipants?: number; participantIds?: number[] } = {}) {
    return render(
        <SubmissionBlock
            locale="es"
            defaultLocale="es"
            t={t}
            tracks={[]}
            minParticipants={1}
            maxParticipants={props.maxParticipants ?? 5}
            participantIds={props.participantIds ?? [0]}
            onAddParticipant={() => undefined}
            onRemoveParticipant={() => undefined}
            errors={{}}
            showDetails={props.showDetails}
        />,
    );
}

describe('SubmissionBlock', () => {
    it('shows the title card and the participants card by default', () => {
        renderBlock();

        expect(screen.getByRole('heading', { level: 2, name: 'submit.section_details' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'submit.section_participants' })).toBeInTheDocument();
    });

    it('shows only the participants card when the details are hidden (preview of the form editor)', () => {
        renderBlock({ showDetails: false });

        expect(screen.queryByRole('heading', { level: 2, name: 'submit.section_details' })).not.toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 2, name: 'submit.section_participants' })).toBeInTheDocument();
    });

    it('offers to add a participant only while the limit is not reached', () => {
        const { unmount } = renderBlock({ maxParticipants: 2, participantIds: [0] });
        expect(screen.getByRole('button', { name: 'submit.participant_add' })).toBeInTheDocument();
        unmount();

        renderBlock({ maxParticipants: 2, participantIds: [0, 1] });
        expect(screen.queryByRole('button', { name: 'submit.participant_add' })).not.toBeInTheDocument();
    });
});
