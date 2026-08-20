import { Navigate, Route, Routes, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from './app/AuthProvider';
import { AppShell } from './components/layout/AppShell';
import { FullPageLoader } from './components/ui/Spinner';
import { LoginPage } from './features/auth/LoginPage';
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
import { CloudPage } from './features/cloud/CloudPage';
import { SshKeysPage } from './features/cloud/SshKeysPage';
import { SitesPage } from './features/sites/SitesPage';
import { BillingPage } from './features/billing/BillingPage';
import { CouponAdminPage } from './features/billing/CouponAdminPage';
import { ModulePage } from './features/modules/ModulePage';
import { MarketingLayout } from './features/marketing/MarketingLayout';
import { MarketingHomePage } from './features/marketing/MarketingHomePage';
import { MarketingServicePage } from './features/marketing/MarketingServicePage';
import { BlogPage } from './features/marketing/BlogPage';
import { BlogPostPage } from './features/marketing/BlogPostPage';
import { ContactPage } from './features/marketing/ContactPage';
import { LandingPageRoute } from './features/marketing/LandingPageRoute';
import { LandingPagesPage } from './features/marketing/LandingPagesPage';

function RequireAuth({ children }: { children: ReactNode }) {
  const { status, activeOrganization } = useAuth();
  const location = useLocation();

  if (status === 'loading') return <FullPageLoader />;
  if (status === 'unauthenticated') return <Navigate to="/login" replace />;
  if (status === 'mfa') return <Navigate to="/mfa" replace />;

  if (!activeOrganization && location.pathname !== '/organizations') {
    return <Navigate to="/organizations" replace />;
  }

  return <>{children}</>;
}

function Home() {
  const { status, activeOrganization } = useAuth();

  if (status === 'loading') return <FullPageLoader />;
  if (status === 'unauthenticated') {
    return (
      <MarketingLayout>
        <MarketingHomePage />
      </MarketingLayout>
    );
  }
  if (status === 'mfa') return <Navigate to="/mfa" replace />;
  if (!activeOrganization) return <Navigate to="/organizations" replace />;

  return (
    <AppShell>
      <DashboardPage />
    </AppShell>
  );
}

export function App() {
  const { status } = useAuth();

  if (status === 'loading') return <FullPageLoader />;

  return (
    <Routes>
      {/* Public marketing site — shown to visitors, the authenticated app takes over below. */}
      <Route path="/" element={<Home />} />
      <Route path="/pricing" element={<Navigate to="/#pricing" replace />} />
      <Route
        path="/marketing/:serviceId"
        element={
          <MarketingLayout>
            <MarketingServicePage />
          </MarketingLayout>
        }
      />
      <Route
        path="/blog"
        element={
          <MarketingLayout>
            <BlogPage />
          </MarketingLayout>
        }
      />
      <Route
        path="/blog/:slug"
        element={
          <MarketingLayout>
            <BlogPostPage />
          </MarketingLayout>
        }
      />
      <Route
        path="/contact"
        element={
          <MarketingLayout>
            <ContactPage />
          </MarketingLayout>
        }
      />
      <Route
        path="/landing/:slug"
        element={
          <MarketingLayout>
            <LandingPageRoute />
          </MarketingLayout>
        }
      />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<Navigate to="/login" replace />} />
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
        <Route path="/sites" element={<SitesPage />} />
        <Route path="/cloud/ssh-keys" element={<SshKeysPage />} />
        <Route path="/cloud" element={<CloudPage />} />
        <Route path="/storage" element={<StoragePage />} />
        <Route path="/billing/coupons" element={<CouponAdminPage />} />
        <Route path="/billing" element={<BillingPage />} />
        <Route path="/campaigns" element={<LandingPagesPage />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
