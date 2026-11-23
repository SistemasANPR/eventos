import { fireEvent, render, screen } from '@testing-library/react';

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

    it('asks about other people only with the choices the limits of the call allow', () => {
        const { unmount } = renderBlock({ maxParticipants: 5, participantIds: [0] });
        expect(screen.getAllByRole('radio')).toHaveLength(3);
        unmount();

        const second = renderBlock({ maxParticipants: 2, participantIds: [0] });
        expect(screen.getAllByRole('radio')).toHaveLength(2);
        second.unmount();

        // Con un solo participante posible no hay nada que preguntar.
        renderBlock({ maxParticipants: 1, participantIds: [0] });
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
    });

    it('adds or removes participants when the answer changes', () => {
        const onAdd = vi.fn();
        const onRemove = vi.fn();
        render(
            <SubmissionBlock
                locale="es"
                defaultLocale="es"
                t={t}
                tracks={[]}
                minParticipants={1}
                maxParticipants={5}
                participantIds={[0, 1, 2]}
                onAddParticipant={onAdd}
                onRemoveParticipant={onRemove}
                errors={{}}
            />,
        );

        fireEvent.click(screen.getByRole('radio', { name: 'submit.participants_extra_0' }));

        expect(onRemove).toHaveBeenCalledTimes(2);
        expect(onRemove).toHaveBeenCalledWith(1);
        expect(onRemove).toHaveBeenCalledWith(2);
        expect(onAdd).not.toHaveBeenCalled();
    });
});
