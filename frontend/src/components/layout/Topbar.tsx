import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Check, ChevronsUpDown, LogOut, Menu, Plus, Search } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { useI18n } from '../../lib/i18n';
import { NotificationBell } from './NotificationBell';

export function Topbar({
  sidebarOpen,
  onToggleSidebar,
}: {
  sidebarOpen?: boolean;
  onToggleSidebar?: () => void;
}) {
  const { activeOrganization, memberships, switchOrganization, logout } = useAuth();
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const navigate = useNavigate();

  useEffect(() => {
    function onClick(event: MouseEvent) {
      if (ref.current && !ref.current.contains(event.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, []);

  async function select(orgId: string) {
    setOpen(false);
    await switchOrganization(orgId);
  }

  return (
    <header className="flex h-14 items-center justify-between border-b border-edge bg-surface px-6">
      <div className="flex min-w-0 items-center gap-2">
        <button
          type="button"
          onClick={onToggleSidebar}
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-raised hover:text-white md:hidden"
          aria-label={t('nav.openMenu')}
          aria-expanded={sidebarOpen ?? false}
        >
          <Menu className="h-5 w-5" />
        </button>
        <div className="relative" ref={ref}>
        <button
          onClick={() => setOpen((o) => !o)}
          className="flex items-center gap-2 rounded-md border border-edge bg-panel px-3 py-1.5 text-sm transition-colors hover:bg-raised"
        >
          <span className="max-w-52 truncate font-medium text-white">
            {activeOrganization?.name ?? t('nav.selectOrganization')}
          </span>
          <ChevronsUpDown className="h-4 w-4 text-zinc-500" />
        </button>

        {open ? (
          <div className="absolute left-0 top-full z-40 mt-1 w-72 rounded-lg border border-edge bg-panel p-1 shadow-xl">
            <div className="px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-500">
              {t('nav.organizations')}
            </div>
            {memberships.map((m) => (
              <button
                key={m.id}
                onClick={() => void select(m.organization?.id ?? '')}
                className="flex w-full items-center justify-between rounded-md px-2 py-2 text-left text-sm hover:bg-raised"
              >
                <span className="truncate text-zinc-200">{m.organization?.name}</span>
                <span className="ml-2 flex shrink-0 items-center gap-2">
                  <span className="text-xs text-zinc-500">{m.role?.name}</span>
                  {m.organization?.id === activeOrganization?.id ? (
                    <Check className="h-4 w-4 text-brand-400" />
                  ) : null}
                </span>
              </button>
            ))}
            <button
              onClick={() => {
                setOpen(false);
                navigate('/organizations');
              }}
              className="mt-1 flex w-full items-center gap-2 rounded-md px-2 py-2 text-sm text-brand-300 hover:bg-raised"
            >
              <Plus className="h-4 w-4" /> {t('nav.newOrganization')}
            </button>
          </div>
        ) : null}
        </div>
      </div>

      <div className="flex items-center gap-2">
        <button
          onClick={() => window.dispatchEvent(new Event('omnex:open-palette'))}
          className="flex h-9 items-center gap-2 rounded-md border border-edge bg-panel px-3 text-sm text-zinc-400 transition-colors hover:bg-raised hover:text-white"
        >
          <Search className="h-4 w-4" />
          <span className="hidden sm:inline">{t('nav.search')}</span>
          <kbd className="rounded border border-edge bg-raised px-1.5 text-[10px]">Ctrl K</kbd>
        </button>
        <NotificationBell />
        <button
          onClick={() => void logout()}
          className="flex h-9 w-9 items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-raised hover:text-white"
          aria-label={t('nav.logout')}
        >
          <LogOut className="h-4 w-4" />
        </button>
      </div>
    </header>
  );
}
