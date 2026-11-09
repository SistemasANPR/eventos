# ADR-0007: Pagos, facturación y comisiones

- **Fecha:** 2026-11-09
- **Estado:** Propuesta (abierta: depende de decisiones de negocio)
- **Alcance:** registro, boletos, patrocinios, suscripción del SaaS y facturación

## Contexto

Hoy los boletos se cobran en una plataforma externa (Podiumeeting) y el cpanel solo guarda el catálogo. La plataforma nueva puede mantener esa integración o cobrar por sí misma. Como SaaS hay **dos flujos de dinero distintos**: (a) cada organización cobra a sus asistentes y patrocinadores, y (b) la plataforma cobra a cada organización su suscripción o una comisión. Los pagos son una invariante de máxima prioridad (`AGENTS.md`) y exigen idempotencia y verificación de firmas.

## Decisión provisional

- **Flujo (a), boletos y patrocinios:** pasarela con modelo de plataforma o *marketplace*, de modo que cada organización reciba su dinero directamente y la plataforma cobre una comisión. Candidatas: Stripe Connect y Mercado Pago. Se decide tras validar disponibilidad y requisitos para organizaciones en México y otros países `[CONFIRMAR]`.
- **Flujo (b), suscripción del SaaS:** facturación por planes con un proveedor estándar (por ejemplo `laravel/cashier` con Stripe), independiente del flujo (a).
- **Facturación electrónica (CFDI):** a través de un proveedor con API, configurada **por organización** `[CONFIRMAR: proveedor]`.
- **Reglas técnicas** (no negociables, `docs/seguridad.md` §Pagos): importes en unidades menores con moneda; identificador del proveedor guardado de forma única; webhooks con firma verificada, idempotentes y procesados en cola; ningún dato de tarjeta toca nuestros servidores; hechos financieros (cargo, pago, devolución, comisión) separados de estados derivados.
- **Podiumeeting:** se mantiene mientras no esté listo el flujo propio, mediante importación o API de asistentes. Es un módulo reemplazable, no una dependencia del núcleo.

## Alternativas consideradas

| Alternativa | Por qué se descartó |
|---|---|
| Cobrar todo a una sola cuenta de la plataforma y repartir manualmente | Riesgo regulatorio y contable; la plataforma retendría el dinero de terceros |
| Solo integrar plataformas externas de boletos | Se pierde el control del flujo y de los datos de asistentes |
| Construir la pasarela propia | Responsabilidad PCI y costo desproporcionado |

## Consecuencias

**Positivas:** cada organización controla su dinero; la comisión se cobra sin intermediación manual; los datos de pago no se guardan.

**Negativas o riesgos:** dependencia de un proveedor; la disponibilidad de cuentas conectadas varía por país; hace falta soporte para reembolsos y disputas.

## Decisiones abiertas

- **DA-1:** ¿se cobra con pasarela propia desde la primera versión o se arranca integrando Podiumeeting? Se recomienda **arrancar integrando** y construir el cobro propio en la fase de registro y boletos.
- **DA-2:** pasarela principal (Stripe Connect, Mercado Pago u otra). Decidir con una prueba de concepto y la verificación de requisitos para las primeras organizaciones.
- **DA-3:** modelo de ingresos de la plataforma: suscripción, comisión por boleto o mixto.
- **DA-4:** proveedor de CFDI y qué organizaciones lo necesitan desde el inicio.
- **DA-5:** política de reembolsos y disputas, y quién la define (la organización).

## Referencias

- `docs/seguridad.md` §Pagos, `docs/privacidad.md`
