import { Link, Navigate, useParams } from 'react-router-dom';
import { ArrowRight, Check, Sparkles } from 'lucide-react';
import { brand } from '../../lib/brand';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { track } from '../../lib/analytics';
import { serviceById } from './servicePages';
import { useDocumentMeta } from './useDocumentMeta';
import { breadcrumbJsonLd, faqJsonLd, serviceJsonLd, useHreflang, useJsonLd } from './seo';

const faqs = ['marketing.faq.1', 'marketing.faq.2', 'marketing.faq.3', 'marketing.faq.4', 'marketing.faq.5'];

export function MarketingServicePage() {
  const { serviceId } = useParams<{ serviceId: string }>();
  const { locale, t } = useI18n();
  const service = serviceId ? serviceById(serviceId) : undefined;

  useDocumentMeta(
    service ? `${service[locale === 'fr' ? 'fr' : 'en'].metaTitle}` : `OMNEX — ${brand.tagline}`,
    service ? service[locale === 'fr' ? 'fr' : 'en'].metaDescription : brand.tagline,
    service ? `/marketing/${service.id}` : undefined,
  );

  if (!service) return <Navigate to="/" replace />;

  useHreflang(`/marketing/${service.id}`, locale);

  const content = service[locale === 'fr' ? 'fr' : 'en'];
  const Icon = service.icon;
  const serviceName = t(`module.${service.id}.name`);
  useJsonLd('service', serviceJsonLd(service.id, serviceName, content.metaDescription));
  useJsonLd('breadcrumb', breadcrumbJsonLd(service.id, serviceName));
  useJsonLd(
    'service-faq',
    faqJsonLd(faqs.map((faq) => ({ question: t(`${faq}.q`), answer: t(`${faq}.a`) }))),
  );

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.06),transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 py-20 text-center sm:px-6 sm:py-24 lg:px-8">
          <Badge tone="brand" className="mb-6">
            OMNEX · {serviceName}
          </Badge>
          <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/5">
            <Icon className="h-7 w-7 text-white" />
          </div>
          <h1 className="mx-auto mt-6 max-w-3xl text-4xl font-bold tracking-tight text-white sm:text-5xl">
            {content.heroTitle}
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-400">{content.intro}</p>
          <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Link to="/login" onClick={() => track('cta_clicked', { cta: 'service_hero', service: service.id })}>
              <Button size="lg">
                {content.ctaLabel}
                <ArrowRight className="h-5 w-5" />
              </Button>
            </Link>
            <Link to="/contact" onClick={() => track('quote_requested', { service: service.id })}>
              <Button variant="outline" size="lg">
                {t('marketing.cta.quote')}
              </Button>
            </Link>
          </div>
        </div>
      </section>

      {/* Features */}
      <section className="border-y border-white/5 bg-[#0d0d10]">
        <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
          <div className="grid gap-6 lg:grid-cols-3">
            {content.features.map((feature, index) => (
              <div key={feature.title} className="rounded-2xl border border-white/5 bg-[#121214] p-6">
                <div className="flex items-center gap-2">
                  <Sparkles className="h-4 w-4 text-brand-200" />
                  <span className="text-xs font-semibold uppercase tracking-wider text-zinc-500">
                    0{index + 1}
                  </span>
                </div>
                <h2 className="mt-3 text-lg font-semibold text-white">{feature.title}</h2>
                <p className="mt-2 text-sm leading-relaxed text-zinc-400">{feature.desc}</p>
              </div>
            ))}
          </div>

          {/* Capabilities */}
          <div className="mt-14 rounded-2xl border border-white/5 bg-[#121214] p-8">
            <h2 className="text-center text-xl font-semibold text-white">{t('marketing.service.capsTitle', { name: serviceName })}</h2>
            <ul className="mx-auto mt-6 grid max-w-3xl gap-3 sm:grid-cols-2">
              {content.caps.map((cap) => (
                <li key={cap} className="flex items-center gap-2 text-sm text-zinc-300">
                  <Check className="h-4 w-4 shrink-0 text-emerald-400" />
                  {cap}
                </li>
              ))}
            </ul>
            <div className="mt-8 text-center">
              <Link to="/login">
                <Button variant="outline">
                  {content.ctaLabel}
                  <ArrowRight className="h-4 w-4" />
                </Button>
              </Link>
            </div>
          </div>
        </div>
      </section>

      {/* FAQ */}
      <section id="faq" className="mx-auto max-w-3xl px-4 py-20 sm:px-6 lg:px-8">
        <div className="text-center">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">{t('marketing.faq.title')}</h2>
          <p className="mt-4 text-lg text-zinc-400">{t('marketing.faq.subtitle')}</p>
        </div>
        <div className="mt-12 space-y-4">
          {faqs.map((faq) => (
            <details key={faq} className="group rounded-xl border border-white/5 bg-[#121214] open:border-white/15">
              <summary className="flex cursor-pointer items-center justify-between gap-4 px-5 py-4 text-sm font-medium text-white">
                {t(`${faq}.q`)}
                <span className="text-zinc-500 transition-transform group-open:rotate-45">+</span>
              </summary>
              <p className="px-5 pb-5 text-sm leading-relaxed text-zinc-400">{t(`${faq}.a`)}</p>
            </details>
          ))}
        </div>
      </section>

      {/* Final CTA */}
      <section className="border-t border-white/5 bg-gradient-to-b from-[#121214] to-[#0a0a0c]">
        <div className="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {t('marketing.ctaBand.title')}
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-lg text-zinc-400">{t('marketing.ctaBand.subtitle')}</p>
          <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Link to="/login" onClick={() => track('cta_clicked', { cta: 'service_final', service: service.id })}>
              <Button size="lg">
                {content.ctaLabel}
                <ArrowRight className="h-5 w-5" />
              </Button>
            </Link>
            <Link to="/contact" onClick={() => track('demo_requested', { service: service.id })}>
              <Button variant="outline" size="lg">
                {t('marketing.cta.demo')}
              </Button>
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
