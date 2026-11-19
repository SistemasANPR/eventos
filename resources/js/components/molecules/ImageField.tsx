import { useId, useState } from 'react';

import { BUTTON_SECONDARY, FOCUS_RING } from '@/lib/classes';
import type { Translate } from '@/lib/translate';
import { cn } from '@/lib/utils';
import type { DraftImageRef, LandingUploadResult } from '@/types/landing';

interface ImageFieldProps {
    label: string;
    image: DraftImageRef | null;
    onChange: (image: DraftImageRef | null) => void;
    /** Sube el archivo; devuelve el identificador y la URL o los errores del servidor. */
    onUpload: (file: File) => Promise<LandingUploadResult>;
    t: Translate;
    /** Errores de validación del servidor para este campo. */
    errors?: string[];
}

const ACCEPTED_TYPES = 'image/jpeg,image/png,image/webp';

/**
 * Elige una imagen (JPEG, PNG o WebP) y la sube al elegirla. Muestra la miniatura, el progreso y
 * los errores del servidor, y permite quitarla. El servidor valida el contenido real.
 * Usar en: `BlockSettingsPanel`.
 */
export function ImageField({ label, image, onChange, onUpload, t, errors = [] }: ImageFieldProps) {
    const id = useId();
    const [isUploading, setIsUploading] = useState(false);
    const [uploadErrors, setUploadErrors] = useState<string[]>([]);

    const allErrors = [...errors, ...uploadErrors];

    const handleFile = async (file: File | undefined): Promise<void> => {
        if (file === undefined) {
            return;
        }

        setIsUploading(true);
        setUploadErrors([]);

        try {
            const result = await onUpload(file);

            if (result.ok) {
                onChange(result.image);
            } else {
                setUploadErrors(result.errors);
            }
        } catch {
            setUploadErrors([t('landing_editor.image.upload_failed')]);
        } finally {
            setIsUploading(false);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            {image !== null && (
                <img src={image.url} alt="" className="max-h-40 w-full rounded-md border border-border bg-bg object-contain" />
            )}
            <input
                id={id}
                type="file"
                accept={ACCEPTED_TYPES}
                disabled={isUploading}
                aria-describedby={`${id}-hint`}
                aria-invalid={allErrors.length > 0 ? true : undefined}
                onChange={(event) => {
                    void handleFile(event.target.files?.[0]);
                    event.target.value = '';
                }}
                className={cn('block w-full text-sm text-text', FOCUS_RING)}
            />
            <p id={`${id}-hint`} className="text-sm text-text-muted">
                {t('landing_editor.image.hint')}
            </p>
            <p role="status" className="text-sm text-text-muted">
                {isUploading ? t('landing_editor.image.uploading') : ''}
            </p>
            {image !== null && (
                <button type="button" onClick={() => onChange(null)} className={cn(BUTTON_SECONDARY, 'self-start')}>
                    {t('landing_editor.image.remove')}
                </button>
            )}
            {allErrors.length > 0 && (
                <p role="alert" className="text-sm font-medium text-error">
                    {allErrors.join(' ')}
                </p>
            )}
        </div>
    );
}
