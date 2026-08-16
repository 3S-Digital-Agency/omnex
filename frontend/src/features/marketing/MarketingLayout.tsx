import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { brand } from '../../lib/brand';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';

export function MarketingLayout({ children }: { children: ReactNode }) {
  const { t } = useI18n();

  const navLinks = [
    { to: '#services', label: t('marketing.nav.services') },
    { to: '#pricing', label: t('marketing.nav.pricing') },
    { to: '#faq', label: t('marketing.nav.faq') },
    { to: '#contact', label: t('marketing.nav.contact') },
  ];

  return (
    <div className="min-h-screen bg-[#0a0a0c] text-zinc-100">
      {/* Header */}
      <header className="sticky top-0 z-40 border-b border-white/5 bg-[#0a0a0c]/80 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
          <Link to="/" className="flex items-center" aria-label={brand.name}>
            <img src="/logo.png" alt={`${brand.name} logo`} className="h-11 w-auto" />
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
            <Link to="/login">
              <Button variant="ghost" size="sm">
                {t('marketing.signIn')}
              </Button>
            </Link>
            <Link to="/login">
              <Button size="sm">
                {t('marketing.getStarted')}
                <ArrowRight className="h-4 w-4" />
              </Button>
            </Link>
          </div>
        </div>
      </header>

      <main>{children}</main>

      {/* Footer */}
      <footer id="contact" className="border-t border-white/5 bg-[#0d0d10]">
        <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
          <div className="grid gap-8 md:grid-cols-4">
            <div className="md:col-span-1">
              <div className="flex items-center">
                <img src="/logo.png" alt={`${brand.name} logo`} className="h-10 w-auto" />
              </div>
              <p className="mt-3 text-sm text-zinc-500">{t('marketing.footer.tagline')}</p>
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
                { to: '/login', label: t('marketing.getStarted') },
                { to: '/login', label: t('marketing.footer.signIn') },
                { to: '#contact', label: t('marketing.nav.contact') },
              ]}
            />
            <FooterColumn
              title={t('marketing.footer.resources')}
              links={[
                { to: '#faq', label: t('marketing.nav.faq') },
                { to: '#pricing', label: t('marketing.nav.pricing') },
                { to: '/presentation.html', label: 'Roadmap' },
              ]}
            />
          </div>

          <div className="mt-10 flex flex-col items-center justify-between gap-4 border-t border-white/5 pt-6 text-sm text-zinc-500 sm:flex-row">
            <p>
              © {new Date().getFullYear()} {brand.name}. {t('marketing.footer.rights')}
            </p>
            <div className="flex items-center gap-6">
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
      <ul className="mt-3 space-y-2">
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
