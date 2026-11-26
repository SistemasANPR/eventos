import { useCallback, useEffect, useMemo, useState } from 'react';

import { ChoiceField } from '@/components/molecules/ChoiceField';
import { ConfirmBar } from '@/components/molecules/ConfirmBar';
import type { Viewport } from '@/components/molecules/ViewportFrame';
import { BlockLibrary } from '@/components/organisms/landing-editor/BlockLibrary';
import { BlockSettingsPanel } from '@/components/organisms/landing-editor/BlockSettingsPanel';
import { EditorCanvas, type FocusRequest } from '@/components/organisms/landing-editor/EditorCanvas';
import { BUTTON_PRIMARY, BUTTON_SECONDARY, FOCUS_RING, HEADING_LG } from '@/lib/classes';
import { canAddBlock, createBlock, duplicateBlock, moveBlock, newClientId, removeBlock } from '@/lib/landing-blocks';
import { blockLabelKey } from '@/lib/landing-draft';
import { createTranslate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type {
    LandingBlockDraft,
    LandingBlockType,
    LandingEditorApi,
    LandingEditorProps,
    LandingFieldErrors,
    LandingSaveResult,
} from '@/types/landing';

export interface LandingEditorComponentProps extends LandingEditorProps {
    /** Persistencia: la conecta quien monta el editor (en el panel, con Livewire). */
    api: LandingEditorApi;
}

type Operation = 'save' | 'publish' | 'discard';

type Status =
    | { kind: 'idle' }
    | { kind: 'busy'; operation: Operation }
    | { kind: 'success'; operation: Operation }
    | { kind: 'error' }
    | { kind: 'invalid' }
    | { kind: 'conflict'; blocks: LandingBlockDraft[]; version: number };

type Confirmation = { kind: 'remove'; blockId: string } | { kind: 'discard' } | { kind: 'template' };

type SidePanel = 'settings' | 'library';

const VIEWPORTS: readonly Viewport[] = ['mobile', 'tablet', 'desktop'];

/**
 * Editor visual de la landing de un evento (plan 022), con dos zonas: el lienzo (la página tal como
 * la verá el visitante) y el panel de ajustes del bloque seleccionado. Es el único dueño del estado
 * del borrador: para persistir llama al `api` inyectado (guardar borrador, actualizar, descartar,
 * subir imagen) y muestra la carga, el error, el éxito y el conflicto de versión.
 * Usar en: la pantalla del editor del panel; no en el sitio público.
 */
export function LandingEditor({
    blocks: initialBlocks,
    version: initialVersion,
    locales,
    defaultLocale,
    translations,
    preview,
    publicUrl,
    hasUnpublishedChanges,
    kind = 'event',
    template = null,
    api,
}: LandingEditorComponentProps) {
    const t = useMemo(() => createTranslate(translations), [translations]);

    const [blocks, setBlocks] = useState(initialBlocks);
    const [version, setVersion] = useState(initialVersion);
    const [savedSnapshot, setSavedSnapshot] = useState(() => JSON.stringify(initialBlocks));
    const [hasUnpublished, setHasUnpublished] = useState(hasUnpublishedChanges);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [locale, setLocale] = useState(defaultLocale);
    const [viewport, setViewport] = useState<Viewport>('desktop');
    const [sidePanel, setSidePanel] = useState<SidePanel>('settings');
    const [status, setStatus] = useState<Status>({ kind: 'idle' });
    const [errors, setErrors] = useState<LandingFieldErrors>({});
    const [confirmation, setConfirmation] = useState<Confirmation | null>(null);
    const [announcement, setAnnouncement] = useState('');
    const [focusRequest, setFocusRequest] = useState<FocusRequest | null>(null);

    const isDirty = useMemo(() => JSON.stringify(blocks) !== savedSnapshot, [blocks, savedSnapshot]);
    const isBusy = status.kind === 'busy';
    const canAdd = canAddBlock(blocks);
    const selectedIndex = blocks.findIndex((block) => block.id === selectedId);
    const selectedBlock = blocks[selectedIndex] ?? null;

    useEffect(() => {
        if (!isDirty) {
            return;
        }

        const warn = (event: BeforeUnloadEvent): void => event.preventDefault();
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [isDirty]);

    const focusBlock = useCallback((blockId: string): void => {
        setFocusRequest((previous) => ({ blockId, nonce: (previous?.nonce ?? 0) + 1 }));
    }, []);

    const blockName = (block: LandingBlockDraft): string => t(blockLabelKey(block.type));

    const announcePosition = (key: string, block: LandingBlockDraft, position: number, total: number): void =>
        setAnnouncement(t(key, { block: blockName(block), position: String(position + 1), total: String(total) }));

    /* ---- Edición de la lista ---- */

    const handleSelect = (blockId: string): void => {
        setSelectedId(blockId);
        setSidePanel('settings');
    };

    const handleMove = (blockId: string, direction: -1 | 1): void => {
        const index = blocks.findIndex((block) => block.id === blockId);
        const block = blocks[index];
        const target = index + direction;

        if (block === undefined || target < 0 || target >= blocks.length) {
            return;
        }

        setBlocks(moveBlock(blocks, index, direction));
        setSelectedId(blockId);
        focusBlock(blockId);
        announcePosition('landing_editor.announce.moved', block, target, blocks.length);
    };

    const handleDuplicate = (blockId: string): void => {
        const index = blocks.findIndex((block) => block.id === blockId);
        const source = blocks[index];

        if (source === undefined || !canAdd) {
            return;
        }

        const next = duplicateBlock(blocks, index);
        const copy = next[index + 1];

        setBlocks(next);

        if (copy !== undefined) {
            setSelectedId(copy.id);
            focusBlock(copy.id);
            announcePosition('landing_editor.announce.duplicated', copy, index + 1, next.length);
        }
    };

    const handleConfirmRemove = (blockId: string): void => {
        const index = blocks.findIndex((block) => block.id === blockId);
        const removed = blocks[index];
        const next = removeBlock(blocks, index);
        const neighbour = next[Math.min(index, next.length - 1)];

        setConfirmation(null);
        setBlocks(next);
        setSelectedId(neighbour?.id ?? null);

        if (neighbour !== undefined) {
            focusBlock(neighbour.id);
        }

        if (removed !== undefined) {
            setAnnouncement(t('landing_editor.announce.removed', { block: blockName(removed), total: String(next.length) }));
        }
    };

    const handleCancelConfirmation = (): void => {
        const pending = confirmation;
        setConfirmation(null);

        if (pending?.kind === 'remove') {
            focusBlock(pending.blockId);
        }
    };

    const handleAdd = (type: LandingBlockType): void => {
        if (!canAdd) {
            return;
        }

        const created = createBlock(type, locales);
        const at = selectedIndex === -1 ? blocks.length : selectedIndex + 1;

        setBlocks([...blocks.slice(0, at), created, ...blocks.slice(at)]);
        setSelectedId(created.id);
        setSidePanel('settings');
        focusBlock(created.id);
        announcePosition('landing_editor.announce.added', created, at, blocks.length + 1);
    };

    /** Reemplaza los bloques por la plantilla de convocatoria; queda como cambio sin guardar y «Descartar» lo revierte. */
    const handleConfirmTemplate = (): void => {
        if (template === null) {
            return;
        }

        // Ids propios del cliente: el servidor fija los definitivos al guardar (CA-022-14).
        const fresh = template.map((block) => ({ ...block, id: newClientId() }));

        setConfirmation(null);
        setBlocks(fresh);
        setSelectedId(fresh[0]?.id ?? null);
        setAnnouncement(t('landing_editor.announce.template_applied', { total: String(fresh.length) }));
    };

    const handleChangeBlock = (changed: LandingBlockDraft): void =>
        setBlocks((current) => current.map((block) => (block.id === changed.id ? changed : block)));

    /* ---- Persistencia ---- */

    /** Adopta lo que devuelve el servidor (ids definitivos y versión) conservando la selección por posición. */
    const adoptServerState = (serverBlocks: LandingBlockDraft[], serverVersion: number): void => {
        setSelectedId(serverBlocks[selectedIndex]?.id ?? null);
        setBlocks(serverBlocks);
        setVersion(serverVersion);
        setSavedSnapshot(JSON.stringify(serverBlocks));
        setErrors({});
    };

    const runSave = async (operation: 'save' | 'publish'): Promise<void> => {
        setStatus({ kind: 'busy', operation });
        setErrors({});

        try {
            const result: LandingSaveResult =
                operation === 'publish' ? await api.publish(blocks, version) : await api.saveDraft(blocks, version);

            if (result.ok) {
                adoptServerState(result.blocks, result.version);
                setHasUnpublished(operation === 'save');
                setStatus({ kind: 'success', operation });
            } else if (result.reason === 'conflict') {
                setStatus({ kind: 'conflict', blocks: result.blocks, version: result.version });
            } else {
                setErrors(result.errors);
                setStatus({ kind: 'invalid' });
            }
        } catch {
            setStatus({ kind: 'error' });
        }
    };

    const runDiscard = async (): Promise<void> => {
        setConfirmation(null);
        setStatus({ kind: 'busy', operation: 'discard' });

        try {
            const result = await api.discard();

            adoptServerState(result.blocks, result.version);
            setHasUnpublished(false);
            setStatus({ kind: 'success', operation: 'discard' });
        } catch {
            setStatus({ kind: 'error' });
        }
    };

    const handleReloadFromServer = (): void => {
        if (status.kind === 'conflict') {
            adoptServerState(status.blocks, status.version);
            setStatus({ kind: 'idle' });
        }
    };

    const handleKeepMine = (): void => {
        if (status.kind === 'conflict') {
            setVersion(status.version);
            setStatus({ kind: 'idle' });
        }
    };

    const hasPendingWork = isDirty || hasUnpublished;

    return (
        <div className="landing-editor-root flex flex-col gap-4 text-text">
            <header className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <h1 className={HEADING_LG}>{t('landing_editor.title')}</h1>
                <div role="toolbar" aria-label={t('landing_editor.toolbar')} className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <button
                        type="button"
                        disabled={isBusy || !isDirty}
                        onClick={() => void runSave('save')}
                        className={cn(BUTTON_SECONDARY, FOCUS_RING)}
                    >
                        {t('landing_editor.action.save_draft')}
                    </button>
                    <button
                        type="button"
                        disabled={isBusy || !hasPendingWork}
                        onClick={() => void runSave('publish')}
                        className={cn(BUTTON_PRIMARY, FOCUS_RING)}
                    >
                        {t('landing_editor.action.publish')}
                    </button>
                    <button
                        type="button"
                        disabled={isBusy || !hasPendingWork}
                        onClick={() => setConfirmation({ kind: 'discard' })}
                        className={cn(BUTTON_SECONDARY, FOCUS_RING)}
                    >
                        {t('landing_editor.action.discard')}
                    </button>
                    {publicUrl !== null && (
                        <a href={publicUrl} target="_blank" rel="noopener noreferrer" className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                            {t('landing_editor.action.view')}
                            <span className="sr-only"> {t('landing_editor.action.opens_new_tab')}</span>
                        </a>
                    )}
                </div>
            </header>

            <StatusLine
                status={status.kind === 'success' && isDirty ? { kind: 'idle' } : status}
                isDirty={isDirty}
                hasUnpublished={hasUnpublished}
                t={t}
            />

            {status.kind === 'conflict' && (
                <div role="alert" className="flex flex-col gap-3 rounded-lg border border-warning bg-warning-bg p-4 text-warning">
                    <p className="text-base font-semibold">{t('landing_editor.conflict.title')}</p>
                    <p className="text-base">{t('landing_editor.conflict.body')}</p>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <button type="button" onClick={handleReloadFromServer} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                            {t('landing_editor.conflict.reload')}
                        </button>
                        <button type="button" onClick={handleKeepMine} className={cn(BUTTON_SECONDARY, FOCUS_RING)}>
                            {t('landing_editor.conflict.keep_mine')}
                        </button>
                    </div>
                </div>
            )}

            {confirmation?.kind === 'discard' && (
                <ConfirmBar
                    title={t('landing_editor.confirm.discard_title')}
                    description={t('landing_editor.confirm.discard_body')}
                    confirmLabel={t('landing_editor.confirm.discard_confirm')}
                    cancelLabel={t('landing_editor.confirm.cancel')}
                    onConfirm={() => void runDiscard()}
                    onCancel={handleCancelConfirmation}
                />
            )}

            {confirmation?.kind === 'template' && (
                <ConfirmBar
                    title={t('landing_editor.confirm.template_title')}
                    description={t('landing_editor.confirm.template_body')}
                    confirmLabel={t('landing_editor.confirm.template_confirm')}
                    cancelLabel={t('landing_editor.confirm.cancel')}
                    onConfirm={handleConfirmTemplate}
                    onCancel={handleCancelConfirmation}
                />
            )}

            {confirmation?.kind === 'remove' && (
                <ConfirmBar
                    title={t('landing_editor.confirm.remove_title')}
                    description={t('landing_editor.confirm.remove_body')}
                    confirmLabel={t('landing_editor.confirm.remove_confirm')}
                    cancelLabel={t('landing_editor.confirm.cancel')}
                    onConfirm={() => handleConfirmRemove(confirmation.blockId)}
                    onCancel={handleCancelConfirmation}
                />
            )}

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <section aria-labelledby="landing-canvas-title" className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
                        <h2 id="landing-canvas-title" className={HEADING_LG}>
                            {t('landing_editor.canvas.title')}
                        </h2>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            {locales.length > 1 && (
                                <ChoiceField
                                    id="landing-view-locale"
                                    label={t('landing_editor.canvas.language')}
                                    value={locale}
                                    options={locales.map((code) => ({ value: code, label: t(`languages.${code}`) }))}
                                    onChange={setLocale}
                                />
                            )}
                            <div role="group" aria-label={t('landing_editor.viewport.label')} className="flex gap-2">
                                {VIEWPORTS.map((option) => (
                                    <button
                                        key={option}
                                        type="button"
                                        aria-pressed={viewport === option}
                                        onClick={() => setViewport(option)}
                                        className={cn(
                                            viewport === option ? BUTTON_PRIMARY : BUTTON_SECONDARY,
                                            FOCUS_RING,
                                            'px-3 text-sm sm:min-h-9',
                                        )}
                                    >
                                        {t(`landing_editor.viewport.${option}`)}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <p className="text-sm text-text-muted">{t('landing_editor.canvas.help')}</p>
                    <div className="min-w-0 rounded-lg border border-border bg-bg p-3 sm:p-6">
                        {blocks.length === 0 ? (
                            <p className="text-base text-text-muted">{t('landing_editor.canvas.empty')}</p>
                        ) : (
                            <EditorCanvas
                                blocks={blocks}
                                selectedId={selectedId}
                                locale={locale}
                                defaultLocale={defaultLocale}
                                preview={preview}
                                viewport={viewport}
                                focusRequest={focusRequest}
                                canDuplicate={canAdd}
                                onSelect={handleSelect}
                                onMove={handleMove}
                                onDuplicate={handleDuplicate}
                                onRemove={(blockId) => setConfirmation({ kind: 'remove', blockId })}
                                t={t}
                            />
                        )}
                    </div>
                </section>

                <aside
                    aria-label={t('landing_editor.side.label')}
                    className="flex min-w-0 flex-col gap-4 rounded-lg border border-border bg-surface p-4"
                >
                    <div role="group" aria-label={t('landing_editor.side.switch')} className="flex gap-2">
                        {(['settings', 'library'] as const).map((option) => (
                            <button
                                key={option}
                                type="button"
                                aria-pressed={sidePanel === option}
                                onClick={() => setSidePanel(option)}
                                className={cn(sidePanel === option ? BUTTON_PRIMARY : BUTTON_SECONDARY, FOCUS_RING, 'flex-1 px-3 text-sm')}
                            >
                                {t(`landing_editor.side.${option}`)}
                            </button>
                        ))}
                    </div>
                    {sidePanel === 'library' ? (
                        <BlockLibrary
                            kind={kind}
                            canAdd={canAdd}
                            onAdd={handleAdd}
                            onApplyTemplate={template === null ? undefined : () => setConfirmation({ kind: 'template' })}
                            t={t}
                        />
                    ) : (
                        <BlockSettingsPanel
                            block={selectedBlock}
                            index={selectedIndex}
                            locales={locales}
                            defaultLocale={defaultLocale}
                            activeLocale={locale}
                            onLocaleChange={setLocale}
                            onChange={handleChangeBlock}
                            onUpload={(file) => api.uploadImage(file)}
                            errors={errors}
                            t={t}
                        />
                    )}
                </aside>
            </div>

            <p role="status" aria-live="polite" className="sr-only" data-testid="announcer">
                {announcement}
            </p>
        </div>
    );
}

interface StatusLineProps {
    status: Status;
    isDirty: boolean;
    hasUnpublished: boolean;
    t: ReturnType<typeof createTranslate>;
}

/** Estado de la última operación y de los cambios pendientes, anunciado a los lectores de pantalla. */
function StatusLine({ status, isDirty, hasUnpublished, t }: StatusLineProps) {
    const message = statusMessage(status, isDirty, hasUnpublished, t);
    const isProblem = status.kind === 'error' || status.kind === 'invalid';

    return (
        <p
            role={isProblem ? 'alert' : 'status'}
            data-testid="editor-status"
            className={cn('text-base', isProblem ? 'font-semibold text-error' : 'text-text-muted')}
        >
            {message}
        </p>
    );
}

function statusMessage(status: Status, isDirty: boolean, hasUnpublished: boolean, t: StatusLineProps['t']): string {
    switch (status.kind) {
        case 'busy':
            return t(`landing_editor.status.busy_${status.operation}`);
        case 'success':
            return t(`landing_editor.status.success_${status.operation}`);
        case 'error':
            return t('landing_editor.status.error');
        case 'invalid':
            return t('landing_editor.status.invalid');
        case 'conflict':
            return t('landing_editor.status.conflict');
        case 'idle':
            if (isDirty) {
                return t('landing_editor.status.dirty');
            }

            return hasUnpublished ? t('landing_editor.status.unpublished') : t('landing_editor.status.clean');
    }
}
