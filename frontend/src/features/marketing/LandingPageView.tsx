import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, BadgeCheck, Check, Copy, Sparkles, Ticket } from 'lucide-react';
import type { LandingPageSection } from '../../lib/api/types';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { track } from '../../lib/analytics';
import { cn } from '../../lib/utils';

/**
 * Render engine for the landing page CMS. Every section kind is rendered
 * from its JSON config with the shared design system — the marketing team
 * ships a full campaign page without touching code.
 */
export function LandingPageView({ sections, slug }: { sections: LandingPageSection[]; slug: string }) {
  return (
    <div>
      {sections.map((section, index) => (
        <LandingSection key={`${section.kind}-${index}`} section={section} slug={slug} />
      ))}
    </div>
  );
}

function LandingSection({ section, slug }: { section: LandingPageSection; slug: string }) {
  switch (section.kind) {
    case 'hero':
      return <HeroSection section={section} slug={slug} />;
    case 'offer':
      return <OfferSection section={section} slug={slug} />;
    case 'promo':
      return <PromoSection section={section} slug={slug} />;
    case 'comparison':
      return <ComparisonSection section={section} slug={slug} />;
    case 'features':
      return <FeaturesSection section={section} />;
    case 'faq':
      return <FaqSection section={section} />;
    case 'cta':
      return <CtaSection section={section} slug={slug} />;
  }
}

function ctaTrack(slug: string, cta: string) {
  track('cta_clicked', { cta, campaign: slug });
}

function HeroSection({
  section,
  slug,
}: {
  section: Extract<LandingPageSection, { kind: 'hero' }>;
  slug: string;
}) {
  return (
    <section className="relative overflow-hidden">
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.07),transparent_60%)]" />
      <div className="relative mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 sm:py-28 lg:px-8">
        {section.badge ? (
          <Badge tone="brand" className="mb-6">
            {section.badge}
          </Badge>
        ) : null}
        <h1 className="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">
          {section.title}
        </h1>
        <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-zinc-400">{section.subtitle}</p>
        <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
          <Link to="/login" onClick={() => ctaTrack(slug, 'hero_primary')}>
            <Button size="lg">
              {section.cta_label}
              <ArrowRight className="h-5 w-5" />
            </Button>
          </Link>
          {section.cta_secondary ? (
            <Link to="/contact" onClick={() => ctaTrack(slug, 'hero_secondary')}>
              <Button variant="outline" size="lg">
                {section.cta_secondary}
              </Button>
            </Link>
          ) : null}
        </div>
      </div>
    </section>
  );
}

function OfferSection({
  section,
  slug,
}: {
  section: Extract<LandingPageSection, { kind: 'offer' }>;
  slug: string;
}) {
  return (
    <section className="border-y border-white/5 bg-[#0d0d10]">
      <div className="mx-auto max-w-5xl px-4 py-20 sm:px-6 lg:px-8">
        <div
          className={cn(
            'mx-auto max-w-xl rounded-3xl border p-8 sm:p-10',
            section.highlight
              ? 'border-white/15 bg-gradient-to-b from-[#161618] to-[#121214] shadow-[0_0_60px_rgba(255,255,255,0.05)]'
              : 'border-white/5 bg-[#121214]',
          )}
        >
          {section.highlight ? (
            <div className="mb-6 flex justify-center">
              <Badge tone="brand">
                <Sparkles className="h-3.5 w-3.5" /> {section.title}
              </Badge>
            </div>
          ) : (
            <h2 className="text-center text-2xl font-semibold text-white">{section.title}</h2>
          )}
          <div className="mt-4 flex items-baseline justify-center gap-2">
            <span className="text-5xl font-bold tracking-tight text-white">{section.price}</span>
            {section.price_note ? <span className="text-sm text-zinc-400">{section.price_note}</span> : null}
          </div>
          <p className="mt-4 text-center text-sm leading-relaxed text-zinc-400">{section.description}</p>
          <ul className="mx-auto mt-8 max-w-md space-y-3">
            {section.features.map((feature) => (
              <li key={feature} className="flex items-center gap-3 text-sm text-zinc-200">
                <Check className="h-4 w-4 shrink-0 text-emerald-400" />
                {feature}
              </li>
            ))}
          </ul>
          <div className="mt-8 text-center">
            <Link to="/login" onClick={() => ctaTrack(slug, 'offer_cta')}>
              <Button size="lg">{section.cta_label}</Button>
            </Link>
          </div>
        </div>
      </div>
    </section>
  );
}

function PromoSection({
  section,
  slug,
}: {
  section: Extract<LandingPageSection, { kind: 'promo' }>;
  slug: string;
}) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);

  const copyCode = async () => {
    try {
      await navigator.clipboard.writeText(section.code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard unavailable — the code stays visible for manual copy.
    }
  };

  return (
    <section className="border-y border-white/5 bg-[#0d0d10]">
      <div className="mx-auto max-w-2xl px-4 py-20 text-center sm:px-6 lg:px-8">
        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/5">
          <Ticket className="h-7 w-7 text-white" />
        </div>
        <h2 className="mt-6 text-3xl font-bold tracking-tight text-white">{section.title}</h2>
        <div className="mt-8 flex items-center justify-center gap-3">
          <div className="rounded-xl border border-dashed border-white/25 bg-white/5 px-8 py-4">
            <span className="text-2xl font-bold tracking-widest text-white sm:text-3xl">{section.code}</span>
          </div>
          <Button variant="outline" onClick={() => void copyCode()}>
            <Copy className="h-4 w-4" />
            {copied ? t('landing.promo.copied') : t('landing.promo.copy')}
          </Button>
        </div>
        <p className="mt-6 text-sm font-semibold uppercase tracking-wider text-brand-200">{section.discount}</p>
        <p className="mx-auto mt-3 max-w-md text-sm leading-relaxed text-zinc-400">{section.description}</p>
        {section.expires_at ? (
          <p className="mt-2 text-xs text-zinc-500">
            {t('landing.promo.validUntil')} {new Date(section.expires_at).toLocaleDateString()}
          </p>
        ) : null}
        <div className="mt-8">
          <Link to="/login" onClick={() => ctaTrack(slug, 'promo_cta')}>
            <Button size="lg">{section.cta_label}</Button>
          </Link>
        </div>
      </div>
    </section>
  );
}

function ComparisonSection({
  section,
  slug,
}: {
  section: Extract<LandingPageSection, { kind: 'comparison' }>;
  slug: string;
}) {
  return (
    <section className="mx-auto max-w-5xl px-4 py-20 sm:px-6 lg:px-8">
      <div className="text-center">
        <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">{section.title}</h2>
        {section.subtitle ? <p className="mx-auto mt-4 max-w-2xl text-lg text-zinc-400">{section.subtitle}</p> : null}
      </div>
      <div className="mt-12 overflow-x-auto rounded-2xl border border-white/5 bg-[#121214]">
        <table className="w-full min-w-[640px] text-sm">
          <thead>
            <tr className="border-b border-white/5">
              <th className="px-5 py-4 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">
                {section.title}
              </th>
              {section.columns.map((column) => (
                <th
                  key={column.name}
                  className={cn(
                    'px-5 py-4 text-center font-semibold',
                    column.highlight ? 'bg-white/[0.04] text-white' : 'text-zinc-300',
                  )}
                >
                  {column.name}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {section.rows.map((row) => (
              <tr key={row.label} className="border-b border-white/5 last:border-0">
                <td className="px-5 py-3.5 text-zinc-300">{row.label}</td>
                {row.values.map((value, index) => (
                  <td
                    key={index}
                    className={cn(
                      'px-5 py-3.5 text-center',
                      section.columns[index]?.highlight ? 'bg-white/[0.04]' : '',
                    )}
                  >
                    {value === true ? (
                      <Check className="mx-auto h-4 w-4 text-emerald-400" />
                    ) : value === false ? (
                      <span className="text-zinc-700">—</span>
                    ) : (
                      <span className={cn(value === 'None' || value === 'Aucun' ? 'text-emerald-400' : 'text-zinc-300')}>
                        {value}
                      </span>
                    )}
                  </td>
                ))}
              </tr>
            ))}
            <tr>
              <td className="px-5 py-4" />
              {section.columns.map((column) => (
                <td key={column.name} className={cn('px-5 py-4 text-center', column.highlight ? 'bg-white/[0.04]' : '')}>
                  {column.cta_label ? (
                    <Link to="/login" onClick={() => ctaTrack(slug, 'comparison_cta')}>
                      <Button size="sm" variant={column.highlight ? 'primary' : 'outline'}>
                        {column.cta_label}
                      </Button>
                    </Link>
                  ) : null}
                </td>
              ))}
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  );
}

function FeaturesSection({
  section,
}: {
  section: Extract<LandingPageSection, { kind: 'features' }>;
}) {
  return (
    <section className="border-y border-white/5 bg-[#0d0d10]">
      <div className="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8">
        <h2 className="text-center text-3xl font-bold tracking-tight text-white sm:text-4xl">{section.title}</h2>
        <div className="mt-12 grid gap-6 lg:grid-cols-3">
          {section.items.map((item, index) => (
            <div key={item.title} className="rounded-2xl border border-white/5 bg-[#121214] p-6">
              <div className="flex items-center gap-2">
                <BadgeCheck className="h-4 w-4 text-brand-200" />
                <span className="text-xs font-semibold uppercase tracking-wider text-zinc-500">0{index + 1}</span>
              </div>
              <h3 className="mt-3 text-lg font-semibold text-white">{item.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-zinc-400">{item.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function FaqSection({ section }: { section: Extract<LandingPageSection, { kind: 'faq' }> }) {
  return (
    <section className="mx-auto max-w-3xl px-4 py-20 sm:px-6 lg:px-8">
      <h2 className="text-center text-3xl font-bold tracking-tight text-white sm:text-4xl">{section.title}</h2>
      <div className="mt-12 space-y-4">
        {section.items.map((item) => (
          <details key={item.q} className="group rounded-xl border border-white/5 bg-[#121214] open:border-white/15">
            <summary className="flex cursor-pointer items-center justify-between gap-4 px-5 py-4 text-sm font-medium text-white">
              {item.q}
              <span className="text-zinc-500 transition-transform group-open:rotate-45">+</span>
            </summary>
            <p className="px-5 pb-5 text-sm leading-relaxed text-zinc-400">{item.a}</p>
          </details>
        ))}
      </div>
    </section>
  );
}

function CtaSection({
  section,
  slug,
}: {
  section: Extract<LandingPageSection, { kind: 'cta' }>;
  slug: string;
}) {
  return (
    <section className="border-t border-white/5 bg-gradient-to-b from-[#121214] to-[#0a0a0c]">
      <div className="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 lg:px-8">
        <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">{section.title}</h2>
        {section.subtitle ? <p className="mx-auto mt-4 max-w-xl text-lg text-zinc-400">{section.subtitle}</p> : null}
        <div className="mt-10">
          <Link to="/login" onClick={() => ctaTrack(slug, 'final_cta')}>
            <Button size="lg">
              {section.label}
              <ArrowRight className="h-5 w-5" />
            </Button>
          </Link>
        </div>
      </div>
    </section>
  );
}
