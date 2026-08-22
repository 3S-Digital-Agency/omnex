import { Outlet, useLocation } from 'react-router-dom';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { CommandPalette } from './CommandPalette';

export function AppShell({ children }: { children?: ReactNode }) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const location = useLocation();

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

  return (
    <div className="flex h-screen overflow-hidden">
      <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />

      {sidebarOpen ? (
        <div
          className="fixed inset-0 z-40 bg-black/60 md:hidden"
          aria-hidden="true"
          onClick={() => setSidebarOpen(false)}
        />
      ) : null}

      <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
        <Topbar
          sidebarOpen={sidebarOpen}
          onToggleSidebar={() => setSidebarOpen((open) => !open)}
        />
        <main
          className={sidebarOpen ? 'flex-1 overflow-hidden p-6' : 'flex-1 overflow-y-auto p-6'}
        >
          {children ?? <Outlet />}
        </main>
      </div>
      <CommandPalette />
    </div>
  );
}
