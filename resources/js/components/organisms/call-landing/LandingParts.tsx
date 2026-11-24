import type { CSSProperties, ReactNode } from 'react';

import { Icon } from '@/components/organisms/call-landing/Icon';
import { Rich } from '@/components/organisms/call-landing/Rich';
import type { LandingStatus } from '@/lib/call-landing-view';
import type { CallLandingContent, LandingAxis, LandingFaq, LandingPromo, LandingStep } from '@/types/call-landing';

/** Lo que se calcula con los datos reales de la convocatoria: estado, fechas y botón al formulario. */
export interface LandingView {
    status: LandingStatus;
    statusText: string;
    countdown: string;
    opensOn: string;
    closesOn: string;
    closesAt: string;
    /** Texto de la ficha de resultados; sin él, la ficha no aparece. */
    resultsOn?: string;
    submitUrl: string | null;
    ctaLabel: string;
}

/** Confeti de la portada: `c` color, `s` tamaño, `r` giro y la posición; `far` se oculta en pantallas angostas. */
const TRIANGLES: { style: Record<string, string>; isFar: boolean }[] = [
    { style: { '--c': 'var(--ev-acento)', '--r': '18deg', top: '5%', left: '2.5%' }, isFar: true },
    { style: { '--c': 'var(--ev-acento-2)', '--s': '20px', '--r': '-12deg', top: '9%', left: '5.5%' }, isFar: true },
    { style: { '--c': 'var(--ev-acento)', '--s': '30px', '--r': '200deg', top: '6%', right: '3%' }, isFar: false },
    { style: { '--c': 'var(--ev-acento-3)', '--s': '22px', '--r': '160deg', top: '58%', right: '1.5%' }, isFar: true },
    { style: { '--c': 'var(--ev-acento-2)', '--s': '18px', '--r': '40deg', bottom: '30%', left: '1.5%' }, isFar: true },
];

const NAV = [
    { href: '#fechas', label: 'Fechas' },
    { href: '#ejes', label: 'Ejes temáticos' },
    { href: '#requisitos', label: 'Requisitos' },
    { href: '#preguntas', label: 'Preguntas' },
];

interface CtaProps {
    view: LandingView;
    className: string;
    children?: ReactNode;
}

/** Botón al formulario: fuera de fecha pierde el enlace y queda en gris con su motivo. */
export function Cta({ view, className, children }: CtaProps) {
    if (view.submitUrl === null) {
        return (
            <span role="link" className={className} aria-disabled="true">
                {view.ctaLabel}
            </span>
        );
    }

    return (
        <a className={className} href={view.submitUrl}>
            {children ?? view.ctaLabel}
        </a>
    );
}

export function TopBar({ view }: { view: LandingView }) {
    return (
        <header className="cl-anpr-bar">
            <div className="cl-in">
                <a className="cl-anpr-marca" href="https://anpr.org.mx">
                    ANPR México<small>Asociación Nacional de Parques y Recreación</small>
                </a>
                <nav className="cl-anpr-nav" aria-label="Secciones de la convocatoria">
                    {NAV.map((item) => (
                        <a key={item.href} href={item.href}>
                            {item.label}
                        </a>
                    ))}
                </nav>
                <Cta view={view} className="cl-btn cl-btn--mini" />
            </div>
        </header>
    );
}

export function Hero({ content, view }: { content: CallLandingContent; view: LandingView }) {
    return (
        <section className={content.cover === undefined ? 'cl-hero cl-hero--sin-portada' : 'cl-hero'}>
            {TRIANGLES.map((triangle, index) => (
                <span key={index} className={triangle.isFar ? 'cl-tri cl-lejos' : 'cl-tri'} style={triangle.style as CSSProperties} />
            ))}
            <div className="cl-in cl-hero__in">
                <div className="cl-hero__head">
                    <p className="cl-estado" data-estado={view.status}>
                        <span className="cl-estado__punto" aria-hidden="true" />
                        <span>{view.statusText}</span>
                        <span className="cl-estado__cuenta">{view.countdown}</span>
                    </p>
                    <h1>{content.title}</h1>
                    <p className="cl-hero__evento">{content.eventLine}</p>
                </div>
                {content.cover !== undefined && (
                    <figure className={content.cover.isLogo === true ? 'cl-hero__pic cl-hero__pic--logo' : 'cl-hero__pic'}>
                        <img src={content.cover.url} alt={content.cover.alt} />
                    </figure>
                )}
                <div className="cl-hero__body">
                    <p className="cl-hero__lede">{content.lede}</p>
                    {content.notice !== undefined && (
                        <p className="cl-aviso">
                            <span className="cl-check">
                                <Icon name="check" />
                            </span>
                            {content.notice}
                        </p>
                    )}
                    <div className="cl-acciones">
                        <Cta view={view} className="cl-btn cl-btn--primario" />
                        <a className="cl-btn cl-btn--linea" href="#requisitos">
                            Ver requisitos
                        </a>
                    </div>
                </div>
            </div>
            <svg className="cl-hero__ola" viewBox="0 0 1440 120" preserveAspectRatio="none" aria-hidden="true">
                <path d="M0 72C240 18 470 20 720 62s500 58 720-8v74H0z" />
            </svg>
        </section>
    );
}

export function Dates({ content, view }: { content: CallLandingContent; view: LandingView }) {
    const cards = 2 + (view.resultsOn === undefined ? 0 : 1) + (content.venue === undefined ? 0 : 1);

    return (
        <section id="fechas" className="cl-fechas" aria-labelledby="t-fechas">
            <div className="cl-in">
                <h2 id="t-fechas" hidden>
                    Fechas clave
                </h2>
                <ol className="cl-fechas__lista" style={{ '--cl-cols': cards } as CSSProperties}>
                    <li className="cl-fecha">
                        <span className="cl-tile">
                            <Icon name="calendario" />
                        </span>
                        <span className="cl-fecha__et">Apertura</span>
                        <span className="cl-fecha__dato">{view.opensOn}</span>
                    </li>
                    <li className="cl-fecha cl-fecha--clave">
                        <span className="cl-tile">
                            <Icon name="reloj" />
                        </span>
                        <span className="cl-fecha__et">Cierre</span>
                        <span className="cl-fecha__dato">{view.closesOn}</span>
                        <span className="cl-fecha__nota">{view.closesAt}</span>
                        <span className="cl-fecha__cuenta">{view.countdown}</span>
                    </li>
                    {view.resultsOn !== undefined && (
                        <li className="cl-fecha">
                            <span className="cl-tile">
                                <Icon name="premio" />
                            </span>
                            <span className="cl-fecha__et">Resultados</span>
                            <span className="cl-fecha__dato">{view.resultsOn}</span>
                        </li>
                    )}
                    {content.venue !== undefined && (
                        <li className="cl-fecha">
                            <span className="cl-tile">
                                <Icon name="pin" />
                            </span>
                            <span className="cl-fecha__et">Congreso</span>
                            <span className="cl-fecha__dato">{content.venue.name}</span>
                            <span className="cl-fecha__nota">{content.venue.note}</span>
                        </li>
                    )}
                </ol>
            </div>
        </section>
    );
}

function AxisCard({ axis }: { axis: LandingAxis }) {
    return (
        <li className="cl-eje">
            <span className="cl-tile">
                <Icon name={axis.icon} />
            </span>
            <h3>{axis.name}</h3>
            <p>{axis.description}</p>
        </li>
    );
}

export function Axes({ content }: { content: CallLandingContent }) {
    if (content.axes.items.length === 0) {
        return null;
    }

    return (
        <section id="ejes" className="cl-band">
            <div className="cl-in">
                <div className="cl-sec-head">
                    <h2>{content.axes.heading}</h2>
                    <p>{content.axes.intro}</p>
                </div>
                <ul className="cl-ejes">
                    {content.axes.items.map((axis) => (
                        <AxisCard key={axis.name} axis={axis} />
                    ))}
                </ul>
            </div>
        </section>
    );
}

function CheckList({ items }: { items: string[] }) {
    return (
        <ul className="cl-lista">
            {items.map((item) => (
                <li key={item}>
                    <span className="cl-check">
                        <Icon name="check" />
                    </span>
                    <span>
                        <Rich text={item} />
                    </span>
                </li>
            ))}
        </ul>
    );
}

function RequirementsAside({ aside }: { aside: NonNullable<CallLandingContent['requirements']['aside']> }) {
    if (aside.kind === 'form') {
        return (
            <div className="cl-form-prev" aria-label="Campos del formulario">
                <div className="cl-form-prev__top">
                    {aside.title} <span>{aside.requiredNote}</span>
                </div>
                {aside.groups.map((group) => (
                    <div key={group.title} className="cl-grupo">
                        <h3>{group.title}</h3>
                        {group.fields.map((field) => (
                            <div key={field.name} className="cl-campo">
                                <b>{field.name}</b>
                                {field.note !== undefined && <small>{field.note}</small>}
                                {field.isRequired === true && <span className="cl-req">Obligatorio</span>}
                            </div>
                        ))}
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div>
            <div className="cl-archivos">
                {aside.files.map((file) => (
                    <div key={file.extension} className="cl-archivo">
                        <span className="cl-archivo__ext">{file.extension}</span>
                        <h3>{file.title}</h3>
                        <ul>
                            {file.notes.map((note) => (
                                <li key={note}>{note}</li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
            <CheckList items={[aside.reminder]} />
        </div>
    );
}

export function Requirements({ content, view }: { content: CallLandingContent; view: LandingView }) {
    const { requirements } = content;

    return (
        <section id="requisitos" className="cl-band cl-band--blanca">
            <div className={requirements.aside === undefined ? 'cl-in cl-dos cl-dos--sola' : 'cl-in cl-dos'}>
                <div>
                    <div className="cl-sec-head">
                        <h2>{requirements.heading}</h2>
                        {requirements.intro !== undefined && <p>{requirements.intro}</p>}
                    </div>
                    <CheckList items={requirements.items} />
                    <Cta view={view} className="cl-btn cl-btn--primario">
                        {requirements.formButton}
                    </Cta>
                </div>
                {requirements.aside !== undefined && <RequirementsAside aside={requirements.aside} />}
            </div>
        </section>
    );
}

function StepItem({ step }: { step: LandingStep }) {
    return (
        <li className="cl-paso">
            <span className="cl-tile">
                <Icon name={step.icon} />
            </span>
            <h3>{step.title}</h3>
            <p>{step.text}</p>
        </li>
    );
}

export function Steps({ steps }: { steps: LandingStep[] }) {
    return (
        <section className="cl-band cl-band--blanca" aria-labelledby="t-pasos" style={{ paddingTop: 0 }}>
            <div className="cl-in">
                <div className="cl-sec-head">
                    <h2 id="t-pasos">Cómo funciona</h2>
                </div>
                <ol className="cl-pasos">
                    {steps.map((step) => (
                        <StepItem key={step.title} step={step} />
                    ))}
                </ol>
            </div>
        </section>
    );
}

function FaqItem({ faq, isOpen }: { faq: LandingFaq; isOpen: boolean }) {
    return (
        <details open={isOpen}>
            <summary>{faq.question}</summary>
            <div className="cl-faq__resp">
                {faq.answer.map((block, index) =>
                    'paragraph' in block ? (
                        <p key={index}>
                            <Rich text={block.paragraph} />
                        </p>
                    ) : (
                        <ul key={index}>
                            {block.list.map((item) => (
                                <li key={item}>
                                    <Rich text={item} />
                                </li>
                            ))}
                        </ul>
                    ),
                )}
            </div>
        </details>
    );
}

export function Faqs({ faqs }: { faqs: LandingFaq[] }) {
    return (
        <section id="preguntas" className="cl-band">
            <div className="cl-in cl-faq-wrap">
                <div className="cl-sec-head">
                    <h2>Preguntas frecuentes</h2>
                    <p>Si tu duda no está aquí, revisa la página del congreso.</p>
                </div>
                <div className="cl-faq">
                    {faqs.map((faq, index) => (
                        <FaqItem key={faq.question} faq={faq} isOpen={index === 0} />
                    ))}
                </div>
            </div>
        </section>
    );
}

export function Promo({ promo, headingId }: { promo: LandingPromo; headingId: string }) {
    return (
        <section className="cl-band cl-band--blanca cl-band--promo" aria-labelledby={headingId}>
            <div className="cl-in">
                <a className={promo.coverUrl === undefined ? 'cl-promo cl-promo--sola' : 'cl-promo'} href={promo.url}>
                    {promo.coverUrl !== undefined && (
                        <div className="cl-promo__img">
                            <img src={promo.coverUrl} alt="" />
                        </div>
                    )}
                    <div className="cl-promo__txt">
                        <h2 id={headingId}>{promo.title}</h2>
                        <p>{promo.text}</p>
                    </div>
                    <span className="cl-btn cl-btn--primario">
                        {promo.button}
                        <Icon name="flecha" />
                    </span>
                </a>
            </div>
        </section>
    );
}

interface FooterLink {
    label: string;
    href: string;
    lang?: string;
    rel?: string;
}

export function Footer({ links }: { links: FooterLink[] }) {
    return (
        <footer className="cl-anpr-pie">
            <div className="cl-in">
                <div>
                    <strong>Asociación Nacional de Parques y Recreación</strong>
                    <small>Plataforma de gestión de eventos</small>
                </div>
                <nav aria-label="Enlaces del evento">
                    {links.map((link) => (
                        <a key={`${link.href}-${link.label}`} href={link.href} lang={link.lang} hrefLang={link.lang} rel={link.rel}>
                            {link.label}
                        </a>
                    ))}
                </nav>
            </div>
        </footer>
    );
}

/** Barra fija del teléfono: se oculta cuando el anuncio de la otra convocatoria entra en pantalla. */
export function StickyCta({ view, isHidden }: { view: LandingView; isHidden: boolean }) {
    return (
        <div className={isHidden ? 'cl-cta-movil cl-cta-movil--oculta' : 'cl-cta-movil'} data-estado={view.status}>
            <span>{view.countdown}</span>
            <Cta view={view} className="cl-btn cl-btn--primario" />
        </div>
    );
}
