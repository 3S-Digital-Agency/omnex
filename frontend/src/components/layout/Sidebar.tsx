import { useRef, useState } from 'react';
import type { TouchEvent } from 'react';
import { Link, NavLink } from 'react-router-dom';
import {
  Activity,
  Command,
  CreditCard,
  ExternalLink,
  Globe,
  HardDrive,
  LayoutDashboard,
  LayoutTemplate,
  Megaphone,
  ScrollText,
  Server,
  Settings,
  ShieldCheck,
  Users,
  X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useAuth } from '../../app/AuthProvider';
import { brand } from '../../lib/brand';
import { useFeatures } from '../../lib/features';
import { useI18n } from '../../lib/i18n';
import { cn, initials } from '../../lib/utils';

interface NavItem {
  to: string;
  labelKey: string;
  icon: LucideIcon;
  /** Feature-flag key gating this nav entry (omitted = always visible). */
  feature?: string;
}

interface NavSection {
  labelKey: string;
  items: NavItem[];
}

const sections: NavSection[] = [
  {
    labelKey: 'nav.platform',
    items: [
      { to: '/overview', labelKey: 'nav.overview', icon: LayoutDashboard },
      { to: '/activity', labelKey: 'nav.activity', icon: Activity },
    ],
  },
  {
    labelKey: 'nav.services',
    items: [
      { to: '/domains', labelKey: 'nav.domains', icon: Globe, feature: 'domains' },
      { to: '/sites', labelKey: 'nav.sites', icon: LayoutTemplate, feature: 'sites' },
      { to: '/cloud', labelKey: 'nav.cloud', icon: Server, feature: 'cloud' },
      { to: '/storage', labelKey: 'nav.storage', icon: HardDrive, feature: 'drive' },
      { to: '/billing', labelKey: 'nav.billing', icon: CreditCard, feature: 'billing' },
      { to: '/campaigns', labelKey: 'nav.campaigns', icon: Megaphone },
    ],
  },
  {
    labelKey: 'nav.system',
    items: [
      { to: '/security', labelKey: 'nav.security', icon: ShieldCheck, feature: 'security' },
      { to: '/members', labelKey: 'nav.members', icon: Users },
      { to: '/audit', labelKey: 'nav.audit', icon: ScrollText },
      { to: '/settings', labelKey: 'nav.settings', icon: Settings },
    ],
  },
];

// Drawer width (w-60 = 15rem), the leftward drag distance that dismisses the
// drawer on release, and the minimum leftward fling velocity (px/ms) that
// dismisses it regardless of distance.
const DRAWER_WIDTH = 240;
const CLOSE_THRESHOLD = 80;
const FLING_VELOCITY = 0.5;

export function Sidebar({
  open = false,
  openDragX = null,
  onClose,
}: {
  open?: boolean;
  /** Rightward edge-drag offset while opening; null = not dragging. */
  openDragX?: number | null;
  onClose?: () => void;
}) {
  const { user } = useAuth();
  const { t } = useI18n();
  const { enabled, isLoading } = useFeatures();
  const touchStart = useRef<{ x: number; y: number } | null>(null);
  const lastMove = useRef<{ x: number; t: number } | null>(null);
  const dragging = useRef(false);
  // Live horizontal drag offset in px (negative = pulled left); `null` means
  // not dragging and the position follows the `open` prop.
  const [dragX, setDragX] = useState<number | null>(null);

  const visible = (item: NavItem): boolean => !item.feature || isLoading || enabled(item.feature);

  function onTouchStart(event: TouchEvent<HTMLElement>) {
    // Only the mobile drawer (open) is draggable; the static desktop sidebar
    // must never shift.
    if (!open) return;
    touchStart.current = { x: event.touches[0].clientX, y: event.touches[0].clientY };
    lastMove.current = null;
    dragging.current = false;
  }

  function onTouchMove(event: TouchEvent<HTMLElement>) {
    if (!touchStart.current || event.touches.length === 0) return;
    const dx = event.touches[0].clientX - touchStart.current.x;
    const dy = event.touches[0].clientY - touchStart.current.y;

    // Begin the horizontal drag only once the gesture is clearly horizontal,
    // so vertical scrolling of the nav is never hijacked.
    if (!dragging.current) {
      if (Math.abs(dx) > Math.abs(dy) && dx < -6) {
        dragging.current = true;
      } else {
        return;
      }
    }

    // Follow the finger, clamped between closed and open.
    lastMove.current = { x: event.touches[0].clientX, t: performance.now() };
    setDragX(Math.max(-DRAWER_WIDTH, Math.min(0, dx)));
  }

  function onTouchEnd(event: TouchEvent<HTMLElement>) {
    const start = touchStart.current;
    const last = lastMove.current;
    touchStart.current = null;
    lastMove.current = null;
    dragging.current = false;
    setDragX(null);

    if (!start) return;
    const endX = event.changedTouches[0].clientX;
    const dx = endX - start.x;
    const dy = event.changedTouches[0].clientY - start.y;

    // Predominantly-horizontal gestures only: a vertical scroll never closes.
    if (Math.abs(dx) <= Math.abs(dy)) return;

    // Leftward fling velocity (px/ms) from the last two move samples; a quick
    // flick closes even if it travelled less than CLOSE_THRESHOLD.
    const endT = performance.now();
    const velocity = last && endT > last.t ? (endX - last.x) / (endT - last.t) : 0;

    if (dx < -CLOSE_THRESHOLD || velocity < -FLING_VELOCITY) {
      onClose?.();
    }
  }

  return (
    <aside
      onTouchStart={onTouchStart}
      onTouchMove={onTouchMove}
      onTouchEnd={onTouchEnd}
      onTouchCancel={() => {
        touchStart.current = null;
        lastMove.current = null;
        dragging.current = false;
        setDragX(null);
      }}
      style={
        openDragX !== null
          ? { transform: `translateX(${openDragX}px)`, transition: 'none' }
          : dragX !== null
            ? { transform: `translateX(${dragX}px)`, transition: 'none' }
            : undefined
      }
      className={cn(
        'fixed inset-y-0 left-0 z-50 flex w-60 shrink-0 flex-col border-r border-edge bg-panel transition-transform duration-200 ease-in-out touch-pan-y',
        'md:static md:translate-x-0 md:touch-auto',
        open ? 'translate-x-0' : '-translate-x-full',
      )}
    >
      <div className="flex items-center justify-between border-b border-edge px-4 py-4">
        <Link
          to="/"
          title={t('nav.viewSite')}
          aria-label={t('nav.viewSite')}
          className="transition-opacity hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded-lg"
        >
          <img src="/logo.png" alt={`${brand.name} logo`} className="h-auto w-40 rounded-lg" />
        </Link>
        <button
          type="button"
          onClick={onClose}
          className="flex h-8 w-8 items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-raised hover:text-white md:hidden"
          aria-label={t('nav.closeMenu')}
        >
          <X className="h-5 w-5" />
        </button>
      </div>

      <nav className="flex-1 space-y-4 overflow-y-auto p-2">
        {sections.map((section) => (
          <div key={section.labelKey}>
            <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-600">
              {t(section.labelKey)}
            </div>
            <div className="space-y-0.5">
              {section.items.filter(visible).map((item) => (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.to === '/overview'}
                  onClick={onClose}
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
        <Link
          to="/"
          className="mb-3 flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-zinc-400 transition-colors hover:bg-raised hover:text-white"
        >
          <ExternalLink className="h-4 w-4" />
          {t('nav.viewSite')}
        </Link>
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
