<?php

declare(strict_types=1);

namespace App\Support\Events;

use App\Enums\TimeFormat;
use App\Models\Event;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Único punto donde se da formato de cara al usuario a las horas de un evento (plan 023, DA-5).
 * Respeta la opción de 12 h o 24 h del evento y su zona horaria; lo guardado no cambia.
 * Los textos salen en el idioma de la interfaz (`app()->getLocale()`).
 */
final readonly class EventTime
{
    public function __construct(public TimeFormat $format, public string $timezone) {}

    public static function for(Event $event): self
    {
        return new self($event->time_format, $event->timezone);
    }

    /** Hora de un instante guardado en UTC, en la zona del evento: «2:30 p. m.» o «14:30 h». */
    public function time(DateTimeInterface $instant): string
    {
        return $this->render(CarbonImmutable::instance($instant)->setTimezone($this->timezone));
    }

    /** Hora local guardada como `H:i` o `H:i:s` (ya está en la zona del evento, no se convierte). */
    public function localTime(string $time): string
    {
        $parsed = CarbonImmutable::createFromFormat(strlen($time) === 5 ? 'H:i' : 'H:i:s', $time, $this->timezone);

        return $this->render($parsed ?: throw new \InvalidArgumentException("Hora no válida: {$time}"));
    }

    /**
     * Fecha y hora de un instante UTC en la zona del evento. `$dateFormat` es el formato de la fecha
     * (por ejemplo `d/m/Y` o `j M Y`); la hora la decide el formato del evento.
     */
    public function dateTime(DateTimeInterface $instant, string $dateFormat, string $separator = ', '): string
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($this->timezone)->locale(app()->getLocale());

        return $local->translatedFormat($dateFormat).$separator.$this->render($local);
    }

    /** Rango de horas o, con `$dateFormat`, de fechas y horas: cada extremo lleva su propia hora. */
    public function range(DateTimeInterface $from, DateTimeInterface $to, ?string $dateFormat = null, string $separator = ', '): string
    {
        return $dateFormat === null
            ? $this->time($from).' – '.$this->time($to)
            : $this->dateTime($from, $dateFormat, $separator).' – '.$this->dateTime($to, $dateFormat, $separator);
    }

    private function render(CarbonImmutable $local): string
    {
        $local = $local->locale(app()->getLocale());

        return match ($this->format) {
            TimeFormat::TwelveHour => $local->isoFormat('h:mm a'),
            TimeFormat::TwentyFourHour => $local->format('H:i').__('events.time_format.suffix_24h'),
        };
    }
}
