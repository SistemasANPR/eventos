import { useEffect, useMemo, useRef, type KeyboardEvent, type ReactNode } from 'react';

import { ViewportFrame, type Viewport } from '@/components/molecules/ViewportFrame';
import { LandingRenderer } from '@/components/organisms/LandingRenderer';
import { BUTTON_SECONDARY, FOCUS_RING } from '@/lib/classes';
import { blockLabelKey, resolveDraftBlock } from '@/lib/landing-draft';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { LandingBlockDraft, LandingPreviewData, PublicLandingBlock } from '@/types/landing';

/** Pide llevar el foco a un bloque; `nonce` distingue dos peticiones seguidas al mismo bloque. */
export interface FocusRequest {
    blockId: string;
    nonce: number;
}

interface EditorCanvasProps {
    blocks: LandingBlockDraft[];
    selectedId: string | null;
    /** Idioma con que se dibuja el lienzo (el de la pestaña activa), con respaldo al base. */
    locale: string;
    defaultLocale: string;
    preview: LandingPreviewData;
    viewport: Viewport;
    focusRequest: FocusRequest | null;
    canDuplicate: boolean;
    onSelect: (blockId: string) => void;
    onMove: (blockId: string, direction: -1 | 1) => void;
    onDuplicate: (blockId: string) => void;
    onRemove: (blockId: string) => void;
    t: Translate;
}

const ACTION_BUTTON = 'min-h-11 px-3 text-sm sm:min-h-9';

/**
 * Lienzo del editor: la landing tal como la verá el visitante (el mismo `LandingRenderer`) con cada
 * bloque envuelto para seleccionarlo con clic o Enter, con contorno visible. El bloque
 * seleccionado muestra su barra de acciones (subir, bajar, duplicar, quitar). Con el bloque
 * enfocado: Alt + flecha arriba o abajo lo mueve, D lo duplica y Suprimir lo quita.
 * Usar en: `LandingEditor`.
 */
export function EditorCanvas({
    blocks,
    selectedId,
    locale,
    defaultLocale,
    preview,
    viewport,
    focusRequest,
    canDuplicate,
    onSelect,
    onMove,
    onDuplicate,
    onRemove,
    t,
}: EditorCanvasProps) {
    const elements = useRef(new Map<string, HTMLElement>());

    const resolved = useMemo(
        () => blocks.map((block) => resolveDraftBlock(block, locale, defaultLocale, preview)),
        [blocks, locale, defaultLocale, preview],
    );

    useEffect(() => {
        if (focusRequest !== null) {
            elements.current.get(focusRequest.blockId)?.focus();
        }
    }, [focusRequest]);

    const handleKeyDown = (event: KeyboardEvent<HTMLElement>, blockId: string): void => {
        // Solo las teclas pulsadas sobre el propio bloque: dentro de la barra de acciones mandan sus botones.
        if (event.target !== event.currentTarget) {
            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onSelect(blockId);
        } else if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
            event.preventDefault();
            onMove(blockId, event.key === 'ArrowUp' ? -1 : 1);
        } else if (event.key === 'd' || event.key === 'D') {
            event.preventDefault();
            if (canDuplicate) {
                onDuplicate(blockId);
            }
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            onRemove(blockId);
        }
    };

    const wrapBlock = (block: PublicLandingBlock, index: number, content: ReactNode): ReactNode => {
        const draft = blocks[index];

        if (draft === undefined) {
            return content;
        }

        const isSelected = block.id === selectedId;
        const name = t(blockLabelKey(draft.type));
        const label = t('landing_editor.canvas.block_label', { block: name, position: String(index + 1), total: String(blocks.length) });

        return (
            <div className="flex flex-col gap-2">
                {isSelected && (
                    <div className="flex flex-wrap items-center gap-2 rounded-md bg-primary p-2 text-primary-contrast">
                        <span className="px-2 text-sm font-semibold">{name}</span>
                        <div
                            role="toolbar"
                            aria-label={t('landing_editor.canvas.actions', { block: name })}
                            className="flex flex-wrap gap-2"
                        >
                            <ActionButton
                                label={t('landing_editor.action.move_up')}
                                isDisabled={index === 0}
                                onClick={() => onMove(block.id, -1)}
                            />
                            <ActionButton
                                label={t('landing_editor.action.move_down')}
                                isDisabled={index === blocks.length - 1}
                                onClick={() => onMove(block.id, 1)}
                            />
                            <ActionButton
                                label={t('landing_editor.action.duplicate')}
                                isDisabled={!canDuplicate}
                                onClick={() => onDuplicate(block.id)}
                            />
                            <ActionButton label={t('landing_editor.action.remove')} onClick={() => onRemove(block.id)} />
                        </div>
                    </div>
                )}
                {/* `inert`: los enlaces y botones del bloque no se activan en el editor; el clic llega al contorno. */}
                <div
                    ref={(element) => {
                        if (element === null) {
                            elements.current.delete(block.id);
                        } else {
                            elements.current.set(block.id, element);
                        }
                    }}
                    role="button"
                    tabIndex={0}
                    aria-label={label}
                    aria-current={isSelected ? 'true' : undefined}
                    aria-keyshortcuts="Alt+ArrowUp Alt+ArrowDown D Delete"
                    data-testid={`canvas-block-${index}`}
                    onClick={() => onSelect(block.id)}
                    onKeyDown={(event) => handleKeyDown(event, block.id)}
                    className={cn(
                        'cursor-pointer rounded-lg outline-2 outline-offset-4',
                        FOCUS_RING,
                        isSelected ? 'outline-primary' : 'outline-transparent hover:outline-border-input',
                    )}
                >
                    <div inert>{content}</div>
                </div>
            </div>
        );
    };

    return (
        <ViewportFrame viewport={viewport} title={t(`landing_editor.viewport.frame_${viewport}`)}>
            <LandingRenderer blocks={resolved} locale={locale} t={t} wrapBlock={wrapBlock} />
        </ViewportFrame>
    );
}

interface ActionButtonProps {
    label: string;
    onClick: () => void;
    isDisabled?: boolean;
}

function ActionButton({ label, onClick, isDisabled = false }: ActionButtonProps) {
    return (
        <button
            type="button"
            disabled={isDisabled}
            onClick={(event) => {
                event.stopPropagation();
                onClick();
            }}
            className={cn(BUTTON_SECONDARY, ACTION_BUTTON)}
        >
            {label}
        </button>
    );
}
