import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, LayoutDashboard } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { brand } from '../../lib/brand';
import { setPublicLocale, useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { cn } from '../../lib/utils';
import { usePageviewTracking } from '../../lib/analytics';
import { ScrollProgress } from '../../components/ScrollProgress';
import { ConsentBanner } from './ConsentBanner';
import { AbDebugPanel } from './AbDebugPanel';

export function MarketingLayout({ children }: { children: ReactNode }) {
  const { t } = useI18n();
  const { status } = useAuth();
  usePageviewTracking();

  const navLinks = [
    { to: '#services', label: t('marketing.nav.services') },
    { to: '#pricing', label: t('marketing.nav.pricing') },
    { to: '#faq', label: t('marketing.nav.faq') },
    { to: '/blog', label: t('marketing.nav.blog') },
    { to: '/contact', label: t('marketing.nav.contact') },
  ];

  return (
    <div className="min-h-screen bg-[#0a0a0c] text-zinc-100">
      <ScrollProgress />
      {/* Header */}
      <header className="sticky top-0 z-40 border-b border-white/5 bg-[#0a0a0c]/80 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
          <Link to="/" className="group relative flex items-center" aria-label={brand.name}>
            <img
              src="/logo.png"
              alt={`${brand.name} logo`}
              title={t('marketing.footer.desc')}
              className="h-11 w-auto cursor-help transition-transform duration-300 hover:scale-[1.04]"
            />
            <span
              role="tooltip"
              className="pointer-events-none absolute left-1/2 top-full z-50 mt-2 hidden w-64 -translate-x-1/2 rounded-lg border border-white/10 bg-[#1a1a1f] px-3 py-2 text-xs leading-relaxed text-zinc-300 shadow-xl group-hover:block"
            >
              {t('marketing.footer.desc')}
            </span>
          </Link>

          <nav className="hidden items-center gap-7 md:flex" aria-label={brand.name}>
            {navLinks.map((link) => (
              <a
                key={link.to}
                href={link.to}
                className="text-sm font-medium text-zinc-400 transition-colors hover:text-white"
              >
                {link.label}
              </a>
            ))}
          </nav>

          <div className="flex items-center gap-3">
            {status === 'authenticated' ? (
              <Link to="/overview">
                <Button size="sm">
                  <LayoutDashboard className="h-4 w-4" />
                  {t('marketing.console')}
                </Button>
              </Link>
            ) : (
              <Link to="/login">
                <Button size="sm">
                  {t('marketing.getStarted')}
                  <ArrowRight className="h-4 w-4" />
                </Button>
              </Link>
            )}
          </div>
        </div>
      </header>

      <main>{children}</main>

      <ConsentBanner />
      <AbDebugPanel />

      {/* Footer */}
      <footer id="contact" className="border-t border-white/5 bg-[#0d0d10]">
        <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
          <div className="grid gap-6 md:grid-cols-4">
            <div className="md:col-span-1">
              <div className="group relative inline-flex items-center">
                <img
                  src="/logo.png"
                  alt={`${brand.name} logo`}
                  title={t('marketing.footer.desc')}
                  className="h-10 w-auto cursor-help"
                />
                <span
                  role="tooltip"
                  className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden w-64 -translate-x-1/2 rounded-lg border border-white/10 bg-[#1a1a1f] px-3 py-2 text-xs leading-relaxed text-zinc-300 shadow-xl group-hover:block"
                >
                  {t('marketing.footer.desc')}
                </span>
              </div>
            </div>

            <FooterColumn
              title={t('marketing.footer.product')}
              links={[
                { to: '/marketing/domains', label: 'Domains' },
                { to: '/marketing/sites', label: 'Sites' },
                { to: '/marketing/cloud', label: 'Cloud' },
                { to: '/marketing/storage', label: 'OMNEX Drive' },
              ]}
            />
            <FooterColumn
              title={t('marketing.footer.company')}
              links={[
                status === 'authenticated'
                  ? { to: '/overview', label: t('marketing.console') }
                  : { to: '/login', label: t('marketing.getStarted') },
                ...(status === 'authenticated'
                  ? []
                  : [{ to: '/login', label: t('marketing.footer.signIn') }]),
                { to: '/contact', label: t('marketing.nav.contact') },
              ]}
            />
            <FooterColumn
              title={t('marketing.footer.resources')}
              links={[
                { to: '/blog', label: t('marketing.nav.blog') },
                { to: '#faq', label: t('marketing.nav.faq') },
                { to: '#pricing', label: t('marketing.nav.pricing') },
                { to: '/presentation.html', label: 'Roadmap' },
              ]}
            />
          </div>

          <div className="mt-6 flex flex-col items-center justify-between gap-4 border-t border-white/5 pt-4 text-sm text-zinc-500 sm:flex-row">
            <p>
              © {new Date().getFullYear()} {brand.name}. {t('marketing.footer.rights')}
            </p>
            <div className="flex flex-wrap items-center justify-center gap-6">
              <LanguageToggle />
              <Link to="/login" className="transition-colors hover:text-white">
                {t('marketing.footer.legal')}
              </Link>
              <span className="text-zinc-700">·</span>
              <Link to="/login" className="transition-colors hover:text-white">
                {t('marketing.pricing.contact')}
              </Link>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}

function FooterColumn({ title, links }: { title: string; links: { to: string; label: string }[] }) {
  return (
    <div>
      <h4 className="text-xs font-semibold uppercase tracking-wider text-zinc-500">{title}</h4>
      <ul className="mt-2 flex flex-wrap gap-x-4 gap-y-1.5">
        {links.map((link) => (
          <li key={link.to + link.label}>
            <Link to={link.to} className="text-sm text-zinc-400 transition-colors hover:text-white">
              {link.label}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}

function LanguageToggle() {
  const { locale } = useI18n();

  return (
    <div
      className="flex items-center overflow-hidden rounded-md border border-white/10 bg-white/5 text-xs font-medium"
      role="group"
      aria-label="Language"
    >
      {(['en', 'fr'] as const).map((code) => (
        <button
          key={code}
          type="button"
          onClick={() => setPublicLocale(code)}
          aria-pressed={locale === code}
          className={cn(
            'h-7 px-2.5 uppercase transition-colors',
            locale === code ? 'bg-white text-black' : 'text-zinc-400 hover:text-white',
          )}
        >
          {code}
        </button>
      ))}
    </div>
  );
}
