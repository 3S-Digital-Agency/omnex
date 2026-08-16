import { Navigate, Route, Routes } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from './app/AuthProvider';
import { AppShell } from './components/layout/AppShell';
import { FullPageLoader } from './components/ui/Spinner';
import { LoginPage } from './features/auth/LoginPage';
import { RegisterPage } from './features/auth/RegisterPage';
import { MfaVerifyPage } from './features/auth/MfaVerifyPage';
import { SocialCallbackPage } from './features/auth/SocialCallbackPage';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { OrganizationsPage } from './features/organizations/OrganizationsPage';
import { MembersPage } from './features/members/MembersPage';
import { AuditPage } from './features/audit/AuditPage';
import { SettingsPage } from './features/settings/SettingsPage';
import { ActivityPage } from './features/activity/ActivityPage';
import { SecurityPage } from './features/security/SecurityPage';
import { DomainsPage } from './features/domains/DomainsPage';
import { DomainDetailPage } from './features/domains/DomainDetailPage';
import { StoragePage } from './features/storage/StoragePage';
import { ModulePage } from './features/modules/ModulePage';

function RequireAuth({ children }: { children: ReactNode }) {
  const { status, activeOrganization } = useAuth();

  if (status === 'loading') return <FullPageLoader />;
  if (status === 'unauthenticated') return <Navigate to="/login" replace />;
  if (status === 'mfa') return <Navigate to="/mfa" replace />;
  if (!activeOrganization) return <Navigate to="/organizations" replace />;

  return <>{children}</>;
}

export function App() {
  const { status } = useAuth();

  if (status === 'loading') return <FullPageLoader />;

  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/mfa" element={<MfaVerifyPage />} />
      <Route path="/social/callback" element={<SocialCallbackPage />} />
      <Route
        element={
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        }
      >
        <Route path="/" element={<DashboardPage />} />
        <Route path="/activity" element={<ActivityPage />} />
        <Route path="/organizations" element={<OrganizationsPage />} />
        <Route path="/members" element={<MembersPage />} />
        <Route path="/audit" element={<AuditPage />} />
        <Route path="/settings" element={<SettingsPage />} />
        <Route path="/security" element={<SecurityPage />} />
        <Route path="/domains" element={<DomainsPage />} />
        <Route path="/domains/:domainId" element={<DomainDetailPage />} />
        <Route path="/sites" element={<ModulePage moduleId="sites" />} />
        <Route path="/cloud" element={<ModulePage moduleId="cloud" />} />
        <Route path="/storage" element={<StoragePage />} />
        <Route path="/billing" element={<ModulePage moduleId="billing" />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
