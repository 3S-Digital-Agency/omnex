import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Check,
  CreditCard,
  Globe,
  HardDrive,
  LayoutTemplate,
  ShieldCheck,
  Server,
  Sparkles,
  Cloud,
  Shield,
  Workflow,
} from 'lucide-react';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { faqJsonLd, organizationJsonLd, useJsonLd, webSiteJsonLd } from './seo';
import { useDocumentMeta } from './useDocumentMeta';

const services = [
  { id: 'domains', icon: Globe, path: '/marketing/domains' },
  { id: 'sites', icon: LayoutTemplate, path: '/marketing/sites' },
  { id: 'cloud', icon: Server, path: '/marketing/cloud' },
  { id: 'storage', icon: HardDrive, path: '/marketing/storage' },
  { id: 'security', icon: ShieldCheck, path: '/marketing/security' },
  { id: 'billing', icon: CreditCard, path: '/marketing/billing' },
];

const stats = [
  { key: 'marketing.stat.servers', value: '10k+' },
  { key: 'marketing.stat.domains', value: '25k+' },
  { key: 'marketing.stat.deploys', value: '1M+' },
  { key: 'marketing.stat.uptime', value: '99.9%' },
];

const features = [
  { icon: Cloud, titleKey: 'marketing.feat.sovereign.title', descKey: 'marketing.feat.sovereign.desc' },
  { icon: Workflow, titleKey: 'marketing.feat.provider.title', descKey: 'marketing.feat.provider.desc' },
  { icon: Shield, titleKey: 'marketing.feat.security.title', descKey: 'marketing.feat.security.desc' },
  { icon: Sparkles, titleKey: 'marketing.feat.automation.title', descKey: 'marketing.feat.automation.desc' },
];

const testimonials = [
  { quoteKey: 'marketing.testimonial.1.quote', nameKey: 'marketing.testimonial.1.name', roleKey: 'marketing.testimonial.1.role' },
  { quoteKey: 'marketing.testimonial.2.quote', nameKey: 'marketing.testimonial.2.name', roleKey: 'marketing.testimonial.2.role' },
  { quoteKey: 'marketing.testimonial.3.quote', nameKey: 'marketing.testimonial.3.name', roleKey: 'marketing.testimonial.3.role' },
];

const faqs = ['marketing.faq.1', 'marketing.faq.2', 'marketing.faq.3', 'marketing.faq.4', 'marketing.faq.5'];

const compareRows = [
  { feature: 'marketing.compare.orgs', free: '1', pro: '1', business: 'marketing.compare.value.unlimited' },
  { feature: 'marketing.compare.members', free: '1', pro: '5', business: 'marketing.compare.value.unlimited' },
  { feature: 'marketing.compare.domains', free: '3', pro: 'marketing.compare.value.unlimited', business: 'marketing.compare.value.unlimited' },
  { feature: 'marketing.compare.dns', free: 'check', pro: 'check', business: 'check' },
  { feature: 'marketing.compare.sites', free: '1', pro: '10', business: 'marketing.compare.value.unlimited' },
  { feature: 'marketing.compare.servers', free: '1', pro: '5', business: 'marketing.compare.value.unlimited' },
  { feature: 'marketing.compare.storage', free: '5 GB', pro: '100 GB', business: 'marketing.compare.value.custom' },
  { feature: 'marketing.compare.ssh', free: 'check', pro: 'check', business: 'check' },
  { feature: 'marketing.compare.security', free: 'check', pro: 'check', business: 'check' },
  { feature: 'marketing.compare.audit', free: 'check', pro: 'check', business: 'check' },
  { feature: 'marketing.compare.api', free: 'check', pro: 'check', business: 'check' },
  { feature: 'marketing.compare.support', free: 'marketing.compare.value.none', pro: 'marketing.compare.value.email', business: 'marketing.compare.value.priority' },
];

function PlanCell({ value, t }: { value: string; t: (key: string, params?: Record<string, string | number>) => string }) {
  if (value === 'check') return <Check className="mx-auto h-4 w-4 text-emerald-400" />;
  if (value.startsWith('marketing.')) return <span className="text-zinc-300">{t(value)}</span>;
  return <span className="text-zinc-300">{value}</span>;
}

export function MarketingHomePage() {
  const { t } = useI18n();

  useDocumentMeta(
    `OMNEX — ${t('marketing.hero.title')}`,
    t('marketing.hero.subtitle'),
    '/',
  );
  useJsonLd('organization', organizationJsonLd());
  useJsonLd('website', webSiteJsonLd());
  useJsonLd(
    'faq',
    faqJsonLd(faqs.map((faq) => ({ question: t(`${faq}.q`), answer: t(`${faq}.a`) }))),
  );

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.06),transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 py-24 text-center sm:px-6 sm:py-32 lg:px-8">
          <Badge tone="brand" className="mb-6">
            {t('marketing.badge')}
          </Badge>
          <h1 className="mx-auto max-w-4xl text-4xl font-bold tracking-tight text-white sm:text-6xl">
            {t('marketing.hero.title')}
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-400">
            {t('marketing.hero.subtitle')}
          </p>
          <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Link to="/login">
              <Button size="lg" className="w-full sm:w-auto">
                {t('marketing.hero.cta')}
                <ArrowRight className="h-5 w-5" />
              </Button>
            </Link>
            <a href="#services">
              <Button variant="outline" size="lg" className="w-full sm:w-auto">
                {t('marketing.hero.secondary')}
              </Button>
            </a>
          </div>

          {/* Product preview card */}
          <div className="mx-auto mt-16 max-w-4xl">
            <div className="rounded-2xl border border-white/10 bg-[#121214] p-2 shadow-2xl">
              <div className="flex items-center gap-1.5 border-b border-white/5 px-4 py-3">
                <span className="h-3 w-3 rounded-full bg-red-500/70" />
                <span className="h-3 w-3 rounded-full bg-amber-500/70" />
                <span className="h-3 w-3 rounded-full bg-emerald-500/70" />
                <span className="ml-3 text-xs text-zinc-500">console.omnex.cloud — Command Center</span>
              </div>
              <div className="grid grid-cols-3 gap-3 p-4 sm:grid-cols-6">
                {services.map((service) => {
                  const Icon = service.icon;
                  return (
                    <div
                      key={service.id}
                      className="flex flex-col items-center gap-2 rounded-lg border border-white/5 bg-[#16161a] p-4"
                    >
                      <Icon className="h-6 w-6 text-zinc-300" />
                      <span className="text-xs text-zinc-400">{t(`module.${service.id}.name`)}</span>
                    </div>
                  );
                })}
              </div>
              <div className="flex items-center justify-between border-t border-white/5 px-4 py-3">
                <span className="flex items-center gap-2 text-xs text-emerald-400">
                  <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />
                  {t('common.live')}
                </span>
                <span className="text-xs text-zinc-500">{t('dashboard.liveActivity')}</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Social proof stats */}
      <section className="border-y border-white/5 bg-[#0d0d10]">
        <div className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
          <p className="text-center text-xs font-semibold uppercase tracking-widest text-zinc-500">
            {t('marketing.hero.trustedBy')}
          </p>
          <dl className="mt-8 grid grid-cols-2 gap-8 sm:grid-cols-4">
            {stats.map((stat) => (
              <div key={stat.key} className="text-center">
                <dt className="order-last mt-1 text-sm text-zinc-500">{t(stat.key)}</dt>
                <dd className="text-3xl font-bold tracking-tight text-white">{stat.value}</dd>
              </div>
            ))}
          </dl>
        </div>
      </section>

      {/* Services */}
      <section id="services" className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-2xl text-center">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {t('marketing.platform.title')}
          </h2>
          <p className="mt-4 text-lg text-zinc-400">{t('marketing.platform.subtitle')}</p>
        </div>
        <div className="mt-14 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {services.map((service) => {
            const Icon = service.icon;
            return (
              <Link
                key={service.id}
                to={service.path}
                className="group rounded-2xl border border-white/5 bg-[#121214] p-6 transition-colors hover:border-white/15 hover:bg-[#16161a]"
              >
                <div className="flex h-11 w-11 items-center justify-center rounded-lg bg-white/5">
                  <Icon className="h-5 w-5 text-white" />
                </div>
                <h3 className="mt-4 text-lg font-semibold text-white">{t(`module.${service.id}.name`)}</h3>
                <p className="mt-1 text-sm text-zinc-500">{t(`module.${service.id}.tagline`)}</p>
                <p className="mt-3 text-sm leading-relaxed text-zinc-400">
                  {t(`module.${service.id}.description`)}
                </p>
                <span className="mt-4 inline-flex items-center gap-1 text-sm font-medium text-brand-200 opacity-0 transition-opacity group-hover:opacity-100">
                  {t('marketing.cta.trial')}
                  <ArrowRight className="h-4 w-4" />
                </span>
              </Link>
            );
          })}
        </div>
      </section>

      {/* Features */}
      <section className="border-y border-white/5 bg-[#0d0d10]">
        <div className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
              {t('marketing.features.title')}
            </h2>
            <p className="mt-4 text-lg text-zinc-400">{t('marketing.features.subtitle')}</p>
          </div>
          <div className="mt-14 grid gap-6 sm:grid-cols-2">
            {features.map((feature) => {
              const Icon = feature.icon;
              return (
                <div key={feature.titleKey} className="rounded-2xl border border-white/5 bg-[#121214] p-6">
                  <Icon className="h-6 w-6 text-brand-200" />
                  <h3 className="mt-4 text-lg font-semibold text-white">{t(feature.titleKey)}</h3>
                  <p className="mt-2 text-sm leading-relaxed text-zinc-400">{t(feature.descKey)}</p>
                </div>
              );
            })}
          </div>
        </div>
      </section>

      {/* Pricing teaser */}
      <section id="pricing" className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-2xl text-center">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {t('marketing.pricing.title')}
          </h2>
          <p className="mt-4 text-lg text-zinc-400">{t('marketing.pricing.subtitle')}</p>
        </div>
        <div className="mt-14 grid gap-6 lg:grid-cols-3">
          <PricingCard
            name={t('marketing.pricing.free.name')}
            price={t('marketing.pricing.free.price')}
            description={t('marketing.pricing.free.desc')}
            features={[t('marketing.pricing.feature1')]}
            cta={t('marketing.pricing.cta', { name: t('marketing.pricing.free.name') })}
            to="/login"
            highlighted={false}
          />
          <PricingCard
            name={t('marketing.pricing.pro.name')}
            price={t('marketing.pricing.pro.price')}
            description={t('marketing.pricing.pro.desc')}
            features={[
              t('marketing.pricing.feature1'),
              t('marketing.pricing.feature2'),
              t('marketing.pricing.feature3'),
              t('marketing.pricing.feature4'),
            ]}
            cta={t('marketing.pricing.cta', { name: t('marketing.pricing.pro.name') })}
            to="/login"
            highlighted
          />
          <PricingCard
            name={t('marketing.pricing.business.name')}
            price={t('marketing.pricing.business.price')}
            description={t('marketing.pricing.business.desc')}
            features={[
              t('marketing.pricing.feature1'),
              t('marketing.pricing.feature2'),
              t('marketing.pricing.feature3'),
              t('marketing.pricing.feature4'),
            ]}
            cta={t('marketing.pricing.contact')}
            to="#contact"
            highlighted={false}
          />
        </div>

        {/* Comparison table */}
        <div className="mx-auto mt-16 max-w-5xl">
          <h3 className="text-center text-2xl font-bold tracking-tight text-white">{t('marketing.compare.title')}</h3>
          <p className="mx-auto mt-3 max-w-xl text-center text-sm text-zinc-400">{t('marketing.compare.subtitle')}</p>
          <div className="mt-8 overflow-x-auto rounded-2xl border border-white/5 bg-[#121214]">
            <table className="w-full min-w-[640px] border-collapse text-left text-sm">
              <thead>
                <tr className="border-b border-white/5">
                  <th className="px-5 py-4 font-medium text-zinc-400">{t('marketing.compare.feature')}</th>
                  <th className="px-5 py-4 text-center font-semibold text-white">{t('marketing.compare.free')}</th>
                  <th className="px-5 py-4 text-center font-semibold text-brand-200">{t('marketing.compare.pro')}</th>
                  <th className="px-5 py-4 text-center font-semibold text-white">{t('marketing.compare.business')}</th>
                </tr>
              </thead>
              <tbody>
                {compareRows.map((row) => (
                  <tr key={row.feature} className="border-b border-white/5 last:border-0">
                    <td className="px-5 py-3 text-zinc-300">{t(row.feature)}</td>
                    <td className="px-5 py-3 text-center">
                      <PlanCell value={row.free} t={t} />
                    </td>
                    <td className="px-5 py-3 text-center">
                      <PlanCell value={row.pro} t={t} />
                    </td>
                    <td className="px-5 py-3 text-center">
                      <PlanCell value={row.business} t={t} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </section>

      {/* Testimonials */}
      <section className="border-y border-white/5 bg-[#0d0d10]">
        <div className="mx-auto max-w-7xl px-4 py-24 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
              {t('marketing.testimonials.title')}
            </h2>
          </div>
          <div className="mt-14 grid gap-6 lg:grid-cols-3">
            {testimonials.map((testimonial) => (
              <figure key={testimonial.nameKey} className="rounded-2xl border border-white/5 bg-[#121214] p-6">
                <div className="flex gap-1 text-amber-400" aria-label="5/5">
                  {'★★★★★'}
                </div>
                <blockquote className="mt-4 text-sm leading-relaxed text-zinc-300">
                  « {t(testimonial.quoteKey)} »
                </blockquote>
                <figcaption className="mt-5 flex items-center gap-3">
                  <span className="flex h-9 w-9 items-center justify-center rounded-full bg-white/10 text-xs font-semibold text-white">
                    {t(testimonial.nameKey).charAt(0)}
                  </span>
                  <div>
                    <div className="text-sm font-semibold text-white">{t(testimonial.nameKey)}</div>
                    <div className="text-xs text-zinc-500">{t(testimonial.roleKey)}</div>
                  </div>
                </figcaption>
              </figure>
            ))}
          </div>
        </div>
      </section>

      {/* FAQ */}
      <section id="faq" className="mx-auto max-w-3xl px-4 py-24 sm:px-6 lg:px-8">
        <div className="text-center">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">{t('marketing.faq.title')}</h2>
          <p className="mt-4 text-lg text-zinc-400">{t('marketing.faq.subtitle')}</p>
        </div>
        <div className="mt-12 space-y-4">
          {faqs.map((faq) => (
            <details
              key={faq}
              className="group rounded-xl border border-white/5 bg-[#121214] open:border-white/15"
            >
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
        <div className="mx-auto max-w-4xl px-4 py-24 text-center sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {t('marketing.ctaBand.title')}
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-lg text-zinc-400">{t('marketing.ctaBand.subtitle')}</p>
          <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Link to="/login">
              <Button size="lg">
                {t('marketing.cta.trial')}
                <ArrowRight className="h-5 w-5" />
              </Button>
            </Link>
            <a href="#contact">
              <Button variant="outline" size="lg">
                {t('marketing.cta.sales')}
              </Button>
            </a>
          </div>
        </div>
      </section>
    </div>
  );
}

function PricingCard({
  name,
  price,
  description,
  features,
  cta,
  to,
  highlighted,
}: {
  name: string;
  price: string;
  description: string;
  features: string[];
  cta: string;
  to: string;
  highlighted: boolean;
}) {
  const { t } = useI18n();

  const body = (
    <>
      <h3 className="text-sm font-semibold uppercase tracking-wider text-zinc-500">{name}</h3>
      <div className="mt-3 flex items-baseline gap-1">
        <span className="text-4xl font-bold tracking-tight text-white">{price}</span>
        {price !== t('marketing.pricing.business.price') ? (
          <span className="text-sm text-zinc-500">{t('marketing.pricing.perMonth')}</span>
        ) : null}
      </div>
      <p className="mt-2 text-sm text-zinc-400">{description}</p>
      <ul className="mt-6 space-y-3">
        {features.map((feature) => (
          <li key={feature} className="flex items-center gap-2 text-sm text-zinc-300">
            <Check className="h-4 w-4 shrink-0 text-emerald-400" />
            {feature}
          </li>
        ))}
      </ul>
      <div className="mt-8">
        <Button variant={highlighted ? 'primary' : 'outline'} className="w-full">
          {cta}
        </Button>
      </div>
    </>
  );

  return to.startsWith('#') ? (
    <a
      href={to}
      className={`rounded-2xl border p-6 transition-colors ${
        highlighted ? 'border-brand-800 bg-[#16161a]' : 'border-white/5 bg-[#121214] hover:border-white/15'
      }`}
    >
      {body}
    </a>
  ) : (
    <Link
      to={to}
      className={`rounded-2xl border p-6 transition-colors ${
        highlighted ? 'border-brand-800 bg-[#16161a]' : 'border-white/5 bg-[#121214] hover:border-white/15'
      }`}
    >
      {body}
    </Link>
  );
}
