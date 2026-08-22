import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Building2, LayoutDashboard, LogOut, Plus, ScrollText, Settings, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { useI18n } from '../../lib/i18n';
import { modules } from '../../lib/modules';

interface Command {
  label: string;
  icon: LucideIcon;
  action: () => void;
}

export function CommandPalette() {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const navigate = useNavigate();
  const { switchOrganization, logout, memberships } = useAuth();
  const { t } = useI18n();

  useEffect(() => {
    function onKey(event: KeyboardEvent) {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        setOpen((o) => !o);
      }
    }
    function onOpen() {
      setOpen(true);
    }
    window.addEventListener('keydown', onKey);
    window.addEventListener('omnex:open-palette', onOpen);
    return () => {
      window.removeEventListener('keydown', onKey);
      window.removeEventListener('omnex:open-palette', onOpen);
    };
  }, []);

  const moduleName = (id: string) => t(`module.${id}.name`);

  const commands = useMemo<Command[]>(() => {
    const all: Command[] = [
      { label: t('nav.goto', { name: t('nav.overview') }), icon: LayoutDashboard, action: () => navigate('/overview') },
      ...modules.map((module) => ({
        label: t('nav.goto', { name: moduleName(module.id) }),
        icon: module.icon,
        action: () => navigate(module.path),
      })),
      { label: t('nav.goto', { name: t('nav.members') }), icon: Users, action: () => navigate('/members') },
      { label: t('nav.goto', { name: t('nav.audit') }), icon: ScrollText, action: () => navigate('/audit') },
      { label: t('nav.goto', { name: t('nav.settings') }), icon: Settings, action: () => navigate('/settings') },
      ...memberships.map((m) => ({
        label: t('nav.switchTo', { name: m.organization?.name ?? t('nav.organizations') }),
        icon: Building2,
        action: () => {
          if (m.organization) void switchOrganization(m.organization.id);
        },
      })),
      { label: t('nav.createOrganization'), icon: Plus, action: () => navigate('/organizations') },
      { label: t('nav.logout'), icon: LogOut, action: () => void logout() },
    ];

    const q = query.trim().toLowerCase();
    if (!q) return all;
    return all.filter((command) => command.label.toLowerCase().includes(q));
  }, [query, navigate, memberships, switchOrganization, logout, t]);

  if (!open) return null;

  function run(command: Command) {
    command.action();
    setOpen(false);
    setQuery('');
  }

  return (
    <div className="fixed inset-0 z-[70] flex items-start justify-center pt-[15vh]">
      <div className="absolute inset-0 bg-black/60" onClick={() => setOpen(false)} />
      <div className="relative w-full max-w-lg overflow-hidden rounded-xl border border-edge bg-panel shadow-2xl">
        <input
          autoFocus
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={t('common.typeCommand')}
          className="w-full border-b border-edge bg-transparent px-4 py-3 text-sm text-white placeholder:text-zinc-500 focus:outline-none"
        />
        <div className="max-h-72 overflow-y-auto p-1">
          {commands.length === 0 ? (
            <p className="px-3 py-6 text-center text-sm text-zinc-500">{t('common.noResults')}</p>
          ) : (
            commands.map((command) => (
              <button
                key={command.label}
                onClick={() => run(command)}
                className="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm text-zinc-200 hover:bg-raised"
              >
                <command.icon className="h-4 w-4 text-zinc-500" />
                {command.label}
              </button>
            ))
          )}
        </div>
      </div>
    </div>
  );
}
