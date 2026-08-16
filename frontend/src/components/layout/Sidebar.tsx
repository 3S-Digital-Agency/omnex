import { NavLink } from 'react-router-dom';
import {
  Activity,
  Command,
  CreditCard,
  Globe,
  HardDrive,
  LayoutDashboard,
  LayoutTemplate,
  ScrollText,
  Server,
  Settings,
  ShieldCheck,
  Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { brand } from '../../lib/brand';
import { useI18n } from '../../lib/i18n';
import { cn, initials } from '../../lib/utils';

interface NavItem {
  to: string;
  labelKey: string;
  icon: LucideIcon;
}

interface NavSection {
  labelKey: string;
  items: NavItem[];
}

const sections: NavSection[] = [
  {
    labelKey: 'nav.platform',
    items: [
      { to: '/', labelKey: 'nav.overview', icon: LayoutDashboard },
      { to: '/activity', labelKey: 'nav.activity', icon: Activity },
    ],
  },
  {
    labelKey: 'nav.services',
    items: [
      { to: '/domains', labelKey: 'nav.domains', icon: Globe },
      { to: '/sites', labelKey: 'nav.sites', icon: LayoutTemplate },
      { to: '/cloud', labelKey: 'nav.cloud', icon: Server },
      { to: '/storage', labelKey: 'nav.storage', icon: HardDrive },
      { to: '/billing', labelKey: 'nav.billing', icon: CreditCard },
    ],
  },
  {
    labelKey: 'nav.system',
    items: [
      { to: '/security', labelKey: 'nav.security', icon: ShieldCheck },
      { to: '/members', labelKey: 'nav.members', icon: Users },
      { to: '/audit', labelKey: 'nav.audit', icon: ScrollText },
      { to: '/settings', labelKey: 'nav.settings', icon: Settings },
    ],
  },
];

export function Sidebar() {
  const { user } = useAuth();
  const { t } = useI18n();

  return (
    <aside className="flex w-60 shrink-0 flex-col border-r border-edge bg-panel">
      <div className="flex items-center gap-3 border-b border-edge px-4 py-4">
        <img src="/logo.png" alt={`${brand.name} logo`} className="h-16 w-16 rounded-lg object-cover" />
        <div className="min-w-0">
          <div className="text-sm font-bold tracking-wide text-white">{brand.name}</div>
          <div className="text-[11px] text-zinc-500">{t('nav.cloudOs')}</div>
        </div>
      </div>

      <nav className="flex-1 space-y-4 overflow-y-auto p-2">
        {sections.map((section) => (
          <div key={section.labelKey}>
            <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">
              {t(section.labelKey)}
            </div>
            <div className="space-y-0.5">
              {section.items.map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.to === '/'}
                  className={({ isActive }) =>
                    cn(
                      'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                      isActive
                        ? 'bg-brand-700/15 text-brand-300'
                        : 'text-zinc-400 hover:bg-raised hover:text-white',
                    )
                  }
                >
                  <item.icon className="h-4 w-4" />
                  {t(item.labelKey)}
                </NavLink>
              ))}
            </div>
          </div>
        ))}
      </nav>

      <div className="border-t border-edge p-4">
        <div className="flex items-center gap-2">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-700 text-xs font-bold text-zinc-950">
            {initials(user?.name ?? '')}
          </div>
          <div className="min-w-0">
            <div className="truncate text-sm font-medium text-white">{user?.name}</div>
            <div className="truncate text-xs text-zinc-500">{user?.email}</div>
          </div>
        </div>
        <div className="mt-3 flex items-center gap-1.5 rounded-md border border-edge bg-raised px-2.5 py-1.5 text-[11px] text-zinc-500">
          <Command className="h-3 w-3" /> {t('nav.pressCtrlK')}
        </div>
      </div>
    </aside>
  );
}
