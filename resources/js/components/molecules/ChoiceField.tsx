import { FORM_CONTROL } from '@/lib/classes';

interface ChoiceFieldProps<Value extends string> {
    id: string;
    label: string;
    value: Value;
    options: ReadonlyArray<{ value: Value; label: string }>;
    onChange: (value: Value) => void;
}

/** Una opción entre pocas (aspecto, alineación, ancho, tamaño) con una lista nativa. Usar en: `BlockSettingsPanel`. */
export function ChoiceField<Value extends string>({ id, label, value, options, onChange }: ChoiceFieldProps<Value>) {
    return (
        <div className="flex flex-col gap-1">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            <select
                id={id}
                value={value}
                onChange={(event) => {
                    const chosen = options.find((option) => option.value === event.target.value);

                    if (chosen !== undefined) {
                        onChange(chosen.value);
                    }
                }}
                className={FORM_CONTROL}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </div>
    );
}
