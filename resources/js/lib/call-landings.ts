import type { CallLandingOverride, CallRouteSlugs, LandingAxis } from '@/types/call-landing';

/*
 * TEXTOS Y PORTADAS PROPIOS DE UNA CONVOCATORIA
 *
 * La landing de TODA convocatoria se arma sola con los datos de la base (título, descripción, fechas, estado,
 * ejes, límite de personas, lo que pide el formulario y las demás convocatorias del evento): ver
 * `buildLandingContent`. Mientras no exista un editor de página, aquí se retoca a mano lo que haga falta:
 * cada entrada reemplaza el texto automático de esa convocatoria y solo de esa.
 *
 * Para cambiar un texto:   busca la convocatoria (más abajo, en LANDINGS) y edita el campo que quieras
 *                          (title, lede, axes, requirements, steps, faqs, promos...). Lo que no escribas
 *                          se sigue armando solo.
 * Para cambiar una imagen: copia el archivo a public/images/call-landings/<evento>/ y pon su ruta en
 *                          `cover.url` (portada de la página) o en `promos[<slug de la otra convocatoria>].coverUrl`
 *                          (miniatura de su anuncio). La portada se ve a 16:9; se recomienda 1672 × 941 px.
 * Para otra convocatoria:  agrega una entrada con la clave `organización/evento/convocatoria` (los slugs de su ruta).
 *                          Otra organización con los mismos nombres de evento y convocatoria no hereda estos textos.
 * Las imágenes y textos de las dos convocatorias de la ANPR siguen pendientes de confirmar con ellos
 * (descripciones de los ejes, fechas del congreso y especificaciones del resumen de los pósters).
 */

const COVERS = '/images/call-landings/congreso-parques-2027';

const ORGANIZATION = 'convocatorias';
const EVENT = 'congreso-parques-2027';
const SESSIONS_CALL = 'registro-de-sesiones-educativas';
const POSTERS_CALL = 'registro-de-poster-cientifico';

const AXES: LandingAxis[] = [
    {
        icon: 'hoja',
        name: 'Naturaleza y Sostenibilidad',
        description: 'Biodiversidad, arbolado, agua y adaptación al clima en los parques.',
    },
    {
        icon: 'personas',
        name: 'Comunidad y Participación Ciudadana',
        description: 'Programas, voluntariado y procesos que involucran a la gente en su espacio público.',
    },
    {
        icon: 'plano',
        name: 'Diseño, Operación y Gestión Eficiente',
        description: 'Planeación, mantenimiento y administración de parques con mejores resultados.',
    },
    {
        icon: 'chip',
        name: 'Tecnología e Innovación Urbana',
        description: 'Datos, herramientas digitales y soluciones nuevas para gestionar y disfrutar los parques.',
    },
    {
        icon: 'ciudad',
        name: 'Ciudad, Movilidad y Gobernanza',
        description: 'El parque en la ciudad: conectividad, políticas públicas y coordinación institucional.',
    },
    {
        icon: 'monedas',
        name: 'Finanzas, Patrocinios y Modelos de Ingreso',
        description: 'Financiamiento, alianzas y esquemas para sostener los espacios públicos.',
    },
];

const EVENT_LINE = 'Convocatoria del Congreso Parques Mazatlán 2027';
const VENUE = { name: 'Mazatlán, Sinaloa', note: 'Fechas: Próximamente' };
const MEMBER_ANSWER = 'No. Cualquier persona mayor de edad puede enviar su propuesta, sea o no miembro de la asociación.';
const RESULTS_ANSWER_END =
    'En marzo de 2027. Te avisaremos si tu propuesta fue seleccionada enviando el resultado al correo de la primera persona registrada en el formulario.';

const SESSIONS: CallLandingOverride = {
    title: 'Imparte una sesión educativa',
    eventLine: EVENT_LINE,
    lede: '¿Tienes una idea, proyecto o experiencia que pueda inspirar a la comunidad de parques? Envía tu propuesta y forma parte del programa educativo del congreso, que se celebrará en Mazatlán, Sinaloa.',
    cover: {
        url: `${COVERS}/portada-sesiones-educativas.webp`,
        alt: 'Ilustración de una ponente presentando un parque frente a un público, con el malecón de Mazatlán al fondo y el rótulo Sesiones Educativas',
    },
    seo: {
        description:
            'Envía tu propuesta de sesión educativa para el Congreso Parques Mazatlán 2027 de la ANPR México. Convocatoria abierta hasta el 30 de enero de 2027.',
        ogTitle: 'Convocatoria de Sesiones Educativas · Congreso Parques Mazatlán 2027',
        ogDescription: '¿Tienes una idea, proyecto o experiencia que pueda inspirar a la comunidad de parques? Envía tu propuesta.',
    },
    timezoneLabel: 'CDMX',
    resultsLabel: 'Marzo de 2027',
    venue: VENUE,
    axes: {
        heading: 'Seis ejes temáticos',
        intro: 'Tu sesión debe enmarcarse en uno de los ejes de esta edición. En el formulario eliges el que mejor describa el tema central de tu propuesta.',
        items: AXES,
    },
    requirements: {
        heading: 'Prepara tu propuesta',
        intro: 'Ten listos estos textos y datos antes de abrir el formulario; así lo completas en una sola sesión.',
        items: [
            'Participa de forma **individual o en equipo**, con un máximo de **tres personas** en total.',
            'Cada propuesta se registra en **un solo eje temático**: elige el que corresponda a su tema central.',
        ],
        formButton: 'Abrir el formulario',
        aside: {
            kind: 'form',
            title: 'Lo que te pide el formulario',
            requiredNote: '* obligatorio',
            groups: [
                {
                    title: 'Información de la propuesta',
                    fields: [
                        { name: 'Selección de eje temático', note: 'eliges uno de los seis', isRequired: true },
                        { name: 'Título de la sesión', note: 'máximo 12 palabras', isRequired: true },
                        { name: 'Descripción', isRequired: true },
                        { name: 'Justificación' },
                        { name: 'Objetivos de aprendizaje', isRequired: true },
                    ],
                },
                {
                    title: 'Información de cada participante',
                    fields: [
                        { name: 'Nombre y apellido, celular, correo y país', isRequired: true },
                        { name: 'Cargo, institución y semblanza profesional breve' },
                        { name: 'Fotografía' },
                    ],
                },
            ],
        },
    },
    steps: [
        { icon: 'doc', title: 'Prepara', text: 'Redacta descripción y objetivos. Reúne los datos de tu equipo.' },
        { icon: 'enviar', title: 'Envía', text: 'Completa el formulario en línea antes de la fecha de cierre.' },
        { icon: 'lupa', title: 'Evaluación', text: 'Las propuestas recibidas serán curadas por el consejo evaluador.' },
        { icon: 'premio', title: 'Resultados', text: 'Conoce las propuestas seleccionadas en marzo de 2027.' },
        { icon: 'micro', title: 'Presenta', text: 'Las sesiones seleccionadas forman parte del programa en Mazatlán.' },
    ],
    faqs: [
        { question: '¿Necesito ser miembro de la ANPR México?', answer: [{ paragraph: MEMBER_ANSWER }] },
        {
            question: '¿Tengo que llenar todos los campos?',
            answer: [
                {
                    paragraph:
                        'Casi todos. Para evaluar adecuadamente tu propuesta y conocer a los participantes, la mayoría de los campos son obligatorios.',
                },
            ],
        },
        { question: '¿Cuándo sabré si mi sesión fue seleccionada?', answer: [{ paragraph: RESULTS_ANSWER_END }] },
        {
            question: '¿Qué incluye ser seleccionado como ponente?',
            answer: [
                {
                    paragraph:
                        'Incluye el acceso gratuito (100% de descuento en tu entrada) al Congreso. No cubre gastos de viaje, hospedaje, alimentación ni viáticos.',
                },
            ],
        },
    ],
    promos: {
        [POSTERS_CALL]: {
            title: '¿Tienes una investigación o caso de estudio?',
            text: 'Participa también en la convocatoria de Pósters Científicos.',
            button: 'Postular póster',
            coverUrl: `${COVERS}/portada-posters-cientificos.webp`,
        },
    },
};

const POSTERS: CallLandingOverride = {
    title: 'Exhibe tu póster científico',
    eventLine: EVENT_LINE,
    lede: 'Si tienes un proyecto académico, estudio técnico o investigación aplicada que contribuya al desarrollo de parques y espacios públicos, compártelo en la exposición de pósters científicos. Los trabajos seleccionados se exhiben durante el congreso y forman parte de su memoria académica.',
    cover: {
        url: `${COVERS}/portada-posters-cientificos.webp`,
        alt: 'Ilustración de una investigadora junto a un póster con gráficas sobre un parque, con el malecón de Mazatlán al fondo y el rótulo Pósters Científicos',
    },
    notice: 'No necesitas ser miembro de la ANPR México',
    seo: {
        description:
            'Presenta tu póster científico en el Congreso Parques Mazatlán 2027 de la ANPR México. Convocatoria abierta hasta el 30 de enero de 2027; no necesitas ser miembro.',
        ogTitle: 'Convocatoria de Pósters Científicos · Congreso Parques Mazatlán 2027',
        ogDescription: 'Comparte tu investigación sobre parques y espacios públicos en la exposición de pósters científicos.',
    },
    timezoneLabel: 'CDMX',
    resultsLabel: 'Marzo de 2027',
    venue: VENUE,
    axes: {
        heading: 'Seis ejes temáticos',
        intro: 'Buscamos trabajos que aporten innovación, conocimiento y experiencias en torno a los ejes de esta edición. En el formulario eliges el que mejor describa tu investigación.',
        items: AXES,
    },
    requirements: {
        heading: 'Requisitos para participar',
        items: [
            'Participa de forma **individual o en equipo**, con un máximo de **tres personas** en total.',
            'Todas las personas participantes deben ser **mayores de edad**.',
            'Cada participante o equipo puede registrar **un solo póster**.',
            'El formulario debe completarse **en su totalidad** antes del cierre. La primera persona registrada es la principal y recibe la confirmación.',
        ],
        formButton: 'Abrir el formulario',
        aside: {
            kind: 'files',
            files: [
                { extension: '.doc', title: 'Resumen', notes: ['Máximo una página', 'Conforme a las especificaciones de la convocatoria'] },
                { extension: '.pdf', title: 'Póster digital', notes: ['Tamaño máximo de 10 MB', 'Resolución de 72 ppp'] },
            ],
            reminder:
                'Ten listos también el **título, el eje temático, la descripción y los objetivos** de tu trabajo, y los datos de contacto de cada participante.',
        },
    },
    steps: [
        { icon: 'doc', title: 'Prepara', text: 'Redacta tu resumen y diseña la versión digital de tu póster.' },
        { icon: 'enviar', title: 'Envía', text: 'Completa el formulario en línea antes de la fecha de cierre.' },
        { icon: 'premio', title: 'Resultados', text: 'Conoce las propuestas seleccionadas en marzo de 2027.' },
        {
            icon: 'impresora',
            title: 'Impresión',
            text: 'Si tu trabajo es seleccionado, nosotros nos encargamos de imprimir tu póster; no es necesario que envíes ni lleves nada más.',
        },
        { icon: 'pin', title: 'Exhibición', text: 'Tu póster se exhibe en Mazatlán y entra en la memoria académica del congreso.' },
    ],
    faqs: [
        { question: '¿Necesito ser miembro de la ANPR México?', answer: [{ paragraph: MEMBER_ANSWER }] },
        {
            question: '¿Tengo que entregar el póster impreso?',
            answer: [
                {
                    paragraph:
                        'No. Si tu trabajo es seleccionado, no tendrás que enviar ni entregar nada adicional; nosotros nos encargaremos de imprimir tu póster directamente para su exhibición.',
                },
            ],
        },
        { question: '¿Cuándo sabré si mi póster fue seleccionado?', answer: [{ paragraph: RESULTS_ANSWER_END }] },
        {
            question: '¿Qué incluye que mi póster sea seleccionado?',
            answer: [
                {
                    paragraph:
                        'Los trabajos seleccionados se clasifican en tres lugares, otorgando los siguientes descuentos en el boleto de entrada al Congreso:',
                },
                { list: ['**1.er lugar:** 100% de descuento.', '**2.º lugar:** 75% de descuento.', '**3.er lugar:** 50% de descuento.'] },
                {
                    paragraph:
                        'Toma en cuenta que estos beneficios aplican únicamente para el acceso al evento; no cubren gastos de viaje, hospedaje, alimentación ni viáticos.',
                },
            ],
        },
    ],
    promos: {
        [SESSIONS_CALL]: {
            title: '¿Te gustaría ser ponente en el Congreso?',
            text: 'Envía tu propuesta para nuestras Sesiones Educativas.',
            button: 'Postular sesión',
            coverUrl: `${COVERS}/portada-sesiones-educativas.webp`,
        },
    },
};

const LANDINGS: Record<string, CallLandingOverride> = {
    [`${ORGANIZATION}/${EVENT}/${SESSIONS_CALL}`]: SESSIONS,
    [`${ORGANIZATION}/${EVENT}/${POSTERS_CALL}`]: POSTERS,
};

/** El retoque de texto o portada de esta convocatoria, o `undefined` si usa solo lo que arma la landing. */
export function findCallLandingOverride(slugs: CallRouteSlugs | undefined): CallLandingOverride | undefined {
    return slugs === undefined ? undefined : LANDINGS[`${slugs.organization}/${slugs.event}/${slugs.call}`];
}
