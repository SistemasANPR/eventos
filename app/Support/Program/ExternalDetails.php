<?php

declare(strict_types=1);

namespace App\Support\Program;

use App\Enums\RoomKind;
use App\Models\ActivityContact;
use App\Models\EventActivity;
use App\Models\Room;
use Illuminate\Validation\ValidationException;

/**
 * Datos de una actividad en un lugar externo (plan 035, DA-4, DA-5 y DA-11): punto de reunión, transporte
 * (`transport_info` no nulo significa «incluye transporte») y hasta cinco responsables. Solo existen cuando el
 * lugar de la actividad es externo: con una sala o sin lugar se rechazan al guardar y, si la actividad deja de
 * estar en un lugar externo, se borran. Comparten el alta y la edición (`CreateActivity`, `UpdateActivity`).
 *
 * Los responsables son datos personales: la auditoría que arman las Actions solo lleva cuántos eran y cuántos
 * son, nunca nombre, teléfono ni correo.
 */
final class ExternalDetails
{
    public const MAX_CONTACTS = 5;

    public const TEXT_LIMIT = 500;

    /**
     * Reglas de entrada de los tres datos; se mezclan con las de `StoreActivityRequest`.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'meeting_point' => ['nullable', 'string', 'max:'.self::TEXT_LIMIT],
            'transport_info' => ['nullable', 'string', 'max:'.self::TEXT_LIMIT],
            'contacts' => ['nullable', 'array', 'max:'.self::MAX_CONTACTS],
            'contacts.*' => ['array'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9\s+\-().]+$/'],
            'contacts.*.email' => ['nullable', 'string', 'max:254', 'email'],
            'contacts.*.is_public' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Quita los espacios sobrantes y convierte en nulo el texto vacío; los responsables quedan como lista.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalized(array $data): array
    {
        foreach (['meeting_point', 'transport_info'] as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]) ?: null;
            }
        }

        if (is_array($data['contacts'] ?? null)) {
            $data['contacts'] = array_values(array_map(static function (mixed $contact): mixed {
                if (! is_array($contact)) {
                    return $contact;
                }

                foreach (['name', 'phone', 'email'] as $field) {
                    if (is_string($contact[$field] ?? null)) {
                        $contact[$field] = trim($contact[$field]);
                    }
                }

                foreach (['phone', 'email'] as $field) {
                    if (($contact[$field] ?? null) === '') {
                        $contact[$field] = null;
                    }
                }

                return $contact;
            }, $data['contacts']));
        }

        return $data;
    }

    /** Si el lugar es externo; sin lugar o con una sala, no. */
    public static function isExternal(?string $roomId): bool
    {
        if ($roomId === null) {
            return false;
        }

        return Room::query()->whereKey($roomId)->value('kind') === RoomKind::External;
    }

    /**
     * Rechaza los datos externos con valor cuando el lugar no es externo (CA-035-13). Los vacíos no molestan: el
     * panel los envía siempre. Se llama dentro de la transacción, con el evento bloqueado y antes de escribir.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public static function assertAllowed(array $validated, bool $external): void
    {
        if ($external) {
            return;
        }

        $errors = [];

        foreach (['meeting_point', 'transport_info'] as $field) {
            if (($validated[$field] ?? null) !== null) {
                $errors[$field] = [__('program.activities.external_only')];
            }
        }

        if (($validated['contacts'] ?? []) !== []) {
            $errors['contacts'] = [__('program.activities.external_only')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Deja exactamente estos responsables en la actividad, en el orden dado (posiciones 1 a n). Si ya son los
     * mismos no toca nada. Devuelve cuántos había y cuántos hay, para la auditoría; ninguno de sus datos.
     *
     * @param  list<array<string, mixed>>  $contacts  ya validados
     * @return array{before: int, after: int, changed: bool}
     */
    public static function syncContacts(EventActivity $activity, array $contacts): array
    {
        $wanted = array_map(static fn (array $contact): array => [
            'name' => (string) $contact['name'],
            'phone' => $contact['phone'] ?? null,
            'email' => $contact['email'] ?? null,
            'is_public' => filter_var($contact['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], array_values($contacts));

        $current = ActivityContact::query()->where('activity_id', $activity->id)->orderBy('position')->get();
        $existing = $current->map(static fn (ActivityContact $contact): array => [
            'name' => $contact->name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_public' => $contact->is_public,
        ])->all();

        if ($existing === $wanted) {
            return ['before' => count($existing), 'after' => count($wanted), 'changed' => false];
        }

        // Se borra y se vuelve a crear: la posición es única por actividad y el orden manda.
        ActivityContact::query()->where('activity_id', $activity->id)->delete();

        foreach ($wanted as $index => $contact) {
            $row = new ActivityContact;
            $row->event_id = $activity->event_id;
            $row->activity_id = $activity->id;
            $row->forceFill([...$contact, 'position' => $index + 1])->save();
        }

        return ['before' => count($existing), 'after' => count($wanted), 'changed' => true];
    }
}
