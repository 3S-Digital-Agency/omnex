import { Outlet, useLocation } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode, TouchEvent } from 'react';
import { RefreshCw } from 'lucide-react';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { CommandPalette } from './CommandPalette';
import { useI18n } from '../../lib/i18n';

// Drawer width (w-60 = 15rem) in px, kept in sync with the Tailwind classes
// on the Sidebar. The edge zone and threshold mirror the drawer's own
// close-swipe constants so open and close gestures feel symmetric.
const DRAWER_WIDTH = 240;
const EDGE_ZONE = 24;
const OPEN_THRESHOLD = 80;
const FLING_VELOCITY = 0.5;

// Pull-to-refresh tuning: the damped distance at which releasing reloads the
// page, the maximum visual travel, and the resistance applied to the finger.
const PULL_RELOAD_THRESHOLD = 80;
const PULL_MAX = 120;
const PULL_RESISTANCE = 0.5;

export function AppShell({ children }: { children?: ReactNode }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();
  const { t } = useI18n();
  // Live rightward drag offset (px) while opening the drawer from the left
  // edge; `null` means not dragging. It composes with the drawer's closed
  // `-translate-x-full` base position, so 0 = hidden and 240 = fully open.
  const [openDragX, setOpenDragX] = useState<number | null>(null);
  const edgeStart = useRef<{ x: number; y: number } | null>(null);
  const edgeLastMove = useRef<{ x: number; t: number } | null>(null);
  const edgeDragging = useRef(false);
  // Pull-to-refresh state: `pullDistance` is the damped downward travel (px)
  // while a qualifying top-edge drag is in progress, `null` otherwise.
  const [pullDistance, setPullDistance] = useState<number | null>(null);
  const pullStartY = useRef<number | null>(null);
  const pullTracking = useRef(false);

  // Close the mobile drawer whenever the route changes (e.g. tapping a nav
  // link); the Sidebar also closes itself on NavLink click for same-route taps.
  useEffect(() => {
    setSidebarOpen(false);
  }, [location.pathname]);

  // Lock background scroll while the drawer is open. The app's scroll container
  // is <main> (the body itself doesn't scroll), so both are locked: <main> via
  // the conditional class below, and <body> for iOS overscroll/rubber-banding.
  useEffect(() => {
    if (!sidebarOpen) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = previous;
    };
  }, [sidebarOpen]);

  // Close the mobile drawer with the Escape key while it is open.
  useEffect(() => {
    if (!sidebarOpen) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setSidebarOpen(false);
      }
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [sidebarOpen]);

  function onEdgeTouchStart(event: TouchEvent<HTMLDivElement>) {
    if (sidebarOpen) return;
    edgeStart.current = { x: event.touches[0].clientX, y: event.touches[0].clientY };
    edgeLastMove.current = null;
    edgeDragging.current = false;
  }

  function onEdgeTouchMove(event: TouchEvent<HTMLDivElement>) {
    if (!edgeStart.current || event.touches.length === 0) return;
    const dx = event.touches[0].clientX - edgeStart.current.x;
    const dy = event.touches[0].clientY - edgeStart.current.y;

    // Begin the drag only once the gesture is clearly horizontal to the right,
    // so a vertical scroll that happens to start near the edge is never hijacked.
    if (!edgeDragging.current) {
      if (dx > 6 && Math.abs(dx) > Math.abs(dy)) {
        edgeDragging.current = true;
      } else {
        return;
      }
    }

    // Follow the finger, clamped between fully closed and fully open.
    edgeLastMove.current = { x: event.touches[0].clientX, t: performance.now() };
    setOpenDragX(Math.max(0, Math.min(DRAWER_WIDTH, dx)));
  }

  function onEdgeTouchEnd(event: TouchEvent<HTMLDivElement>) {
    const start = edgeStart.current;
    const last = edgeLastMove.current;
    edgeStart.current = null;
    edgeLastMove.current = null;
    edgeDragging.current = false;
    setOpenDragX(null);

    if (!start) return;
    const endX = event.changedTouches[0].clientX;
    const dx = endX - start.x;
    const dy = event.changedTouches[0].clientY - start.y;

    // Predominantly-horizontal gestures only: a vertical scroll never opens.
    if (Math.abs(dx) <= Math.abs(dy)) return;

    // Rightward fling velocity (px/ms) from the last two move samples; a quick
    // flick opens even if it travelled less than OPEN_THRESHOLD.
    const endT = performance.now();
    const velocity = last && endT > last.t ? (endX - last.x) / (endT - last.t) : 0;

    if (dx > OPEN_THRESHOLD || velocity > FLING_VELOCITY) {
      setSidebarOpen(true);
    }
  }

  function onEdgeTouchCancel() {
    edgeStart.current = null;
    edgeLastMove.current = null;
    edgeDragging.current = false;
    setOpenDragX(null);
  }

  // Pull-to-refresh: purely observational (no preventDefault, no touch-action
  // override), so normal scrolling is never blocked. The gesture only arms when
  // the scroll container is already at the top, and only tracks downward travel.
  function onPullStart(event: TouchEvent<HTMLElement>) {
    if (event.currentTarget.scrollTop <= 0) {
      pullStartY.current = event.touches[0].clientY;
      pullTracking.current = true;
    } else {
      pullTracking.current = false;
    }
  }

  function onPullMove(event: TouchEvent<HTMLElement>) {
    if (!pullTracking.current || pullStartY.current === null || event.touches.length === 0) {
      return;
    }
    // Once content has scrolled, a downward drag is ordinary scrolling.
    if (event.currentTarget.scrollTop > 0) {
      setPullDistance(null);
      return;
    }
    const dy = event.touches[0].clientY - pullStartY.current;
    if (dy <= 0) {
      setPullDistance(null);
      return;
    }
    setPullDistance(Math.min(PULL_MAX, dy * PULL_RESISTANCE));
  }

  function onPullEnd(event: TouchEvent<HTMLElement>) {
    const start = pullStartY.current;
    const tracking = pullTracking.current;
    pullTracking.current = false;
    pullStartY.current = null;
    setPullDistance(null);

    if (!tracking || start === null) return;
    const dy = event.changedTouches[0].clientY - start;
    if (dy > 0 && dy * PULL_RESISTANCE >= PULL_RELOAD_THRESHOLD) {
      window.location.reload();
    }
  }

  function onPullCancel() {
    pullTracking.current = false;
    pullStartY.current = null;
    setPullDistance(null);
  }

  const pullReady = (pullDistance ?? 0) >= PULL_RELOAD_THRESHOLD;

  return (
    <div className="flex h-screen overflow-hidden">
      <Sidebar
        open={sidebarOpen}
        openDragX={openDragX}
        onClose={() => setSidebarOpen(false)}
      />

      {sidebarOpen ? (
        <div
          className="fixed inset-0 z-40 bg-black/60 md:hidden"
          aria-hidden="true"
          onClick={() => setSidebarOpen(false)}
        />
      ) : (
        <div
          className="fixed inset-y-0 left-0 z-30 w-6 touch-pan-y md:hidden"
          aria-hidden="true"
          onTouchStart={onEdgeTouchStart}
          onTouchMove={onEdgeTouchMove}
          onTouchEnd={onEdgeTouchEnd}
          onTouchCancel={onEdgeTouchCancel}
        />
      )}

      {pullDistance !== null ? (
        <div
          className="pointer-events-none fixed inset-x-0 top-14 z-50 flex justify-center md:hidden"
          style={{ transform: `translateY(${pullDistance}px)` }}
          aria-hidden="true"
        >
          <div className="flex items-center gap-2 rounded-full border border-edge bg-panel px-3 py-1.5 text-xs text-zinc-300 shadow-lg">
            <RefreshCw
              className={pullReady ? 'h-3.5 w-3.5 text-brand-300' : 'h-3.5 w-3.5 text-zinc-400'}
              style={{ transform: `rotate(${Math.min(180, (pullDistance / PULL_MAX) * 180)}deg)` }}
            />
            {pullReady ? t('app.pullRefreshRelease') : t('app.pullRefresh')}
          </div>
        </div>
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <Topbar
          sidebarOpen={sidebarOpen}
          onToggleSidebar={() => setSidebarOpen((open) => !open)}
        />
        <main
          key={location.pathname}
          onTouchStart={onPullStart}
          onTouchMove={onPullMove}
          onTouchEnd={onPullEnd}
          onTouchCancel={onPullCancel}
          className={`omnex-anim-page-in ${
            sidebarOpen
              ? 'flex-1 overflow-hidden p-6'
              : 'flex-1 overflow-y-auto overscroll-y-contain p-6'
          }`}
        >
          {children ?? <Outlet />}
        </main>
      </div>
      <CommandPalette />
    </div>
  );
}
