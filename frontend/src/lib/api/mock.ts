import { ApiError } from './client';
import type { ApiClient } from './client';
import { session } from './session';
import type {
  ActivityFeed,
  ActivityItem,
  AppliedCouponDto,
  AuditLogDto,
  AuthSession,
  BillingPlanDto,
  BillingSubscribeResponse,
  CloudProviderDto,
  CloudProviderVerifyDto,
  CouponAdminDto,
  CouponCreateInput,
  CouponDto,
  CouponRedemptionDto,
  CouponUpdateInput,
  CreditEntryDto,
  CreditSummaryDto,
  DnsHistoryDto,
  DnsRecordDto,
  DnsRecordInput,
  DnssecDsRecord,
  DnssecStatus,
  DomainCheckResult,
  DomainDto,
  DomainProviderDto,
  DomainSearchResult,
  DomainUpdateInput,
  DriveDownloadDto,
  DriveFileDto,
  DriveFileUpdateInput,
  DriveFolderDto,
  DriveListing,
  DriveVersionDto,
  InvitationDto,
  InvoiceDto,
  LoginInput,
  LoginResponse,
  MeResponse,
  MembershipDto,
  MfaConfirmResponse,
  MfaSetupResponse,
  NotificationDto,
  NotificationListDto,
  NotificationQuery,
  OrganizationDto,
  Paginated,
  PaginatedNotificationList,
  PaymentProviderDto,
  PropagationCheckDto,
  PropagationStatus,
  PropagationStatusDto,
  RegisterInput,
  RoleDto,
  SecurityFindingDto,
  SecurityScoreDto,
  ServerCreateInput,
  ServerDto,
  ServerMetricsDto,
  ServerOperationDto,
  ServerSnapshotDto,
  ServerUpdateInput,
  ContactLeadDto,
  ContactLeadInput,
  SshKeyCreateInput,
  SshKeyDto,
  SshKeyGenerateInput,
  SshKeyGenerateResponse,
  SshKeyInstallResponse,
  SshKeyUnlockResponse,
  SshKeyUpdateInput,
  SiteCreateInput,
  SiteDeploymentDto,
  SiteDto,
  SiteProviderDto,
  SiteUpdateInput,
  SocialAccountDto,
  SocialProviderDto,
  SocialRedirectResponse,
  StorageProviderDto,
  SubscriptionDto,
  SwitchResponse,
  UpdateProfileInput,
  UserDto,
  VerifyMfaInput,
} from './types';

let seq = 0;
const uid = (prefix: string): string => `${prefix}-${(++seq).toString(36)}${Date.now().toString(36)}`;

// Monotonic clock: several mock operations can run within the same
// millisecond, and features sort by created_at. Bumping ties guarantees
// stable newest-first ordering regardless of wall-clock precision.
let lastTimestamp = 0;
const nowIso = (): string => {
  const now = Date.now();
  const timestamp = now > lastTimestamp ? now : lastTimestamp + 1;
  lastTimestamp = timestamp;
  return new Date(timestamp).toISOString();
};

interface MockUser extends UserDto {
  password: string;
  pendingMfaSecret?: string;
  recoveryCodes?: string[];
}

interface MockMembership {
  id: string;
  organizationId: string;
  userId: string;
  roleId: string;
  status: string;
  joined_at: string | null;
}

interface MockInvitation {
  id: string;
  organizationId: string;
  email: string;
  roleId: string;
  token: string;
  status: 'pending' | 'accepted' | 'cancelled' | 'expired';
  expires_at: string;
  created_at: string;
}

const ALL_PERMISSIONS = [
  'organizations.read',
  'organizations.manage',
  'organizations.invite',
  'members.manage',
  'audit.read',
  'notifications.read',
  'domains.read',
  'domains.manage',
  'dns.read',
  'dns.manage',
  'storage.read',
  'storage.manage',
  'security.read',
  'security.manage',
  'sites.read',
  'sites.manage',
  'cloud.read',
  'cloud.manage',
  'billing.read',
  'billing.manage',
];

const roles: RoleDto[] = [
  { id: 'role-owner', name: 'Owner', key: 'owner', description: 'Full control over the organization.', permissions: ALL_PERMISSIONS },
  {
    id: 'role-admin',
    name: 'Admin',
    key: 'admin',
    description: 'Manage members and settings.',
    permissions: ['organizations.read', 'organizations.invite', 'members.manage', 'audit.read', 'notifications.read', 'domains.read', 'domains.manage', 'dns.read', 'dns.manage', 'storage.read', 'storage.manage', 'security.read', 'security.manage', 'sites.read', 'sites.manage', 'cloud.read', 'cloud.manage', 'billing.read', 'billing.manage'],
  },
  {
    id: 'role-developer',
    name: 'Developer',
    key: 'developer',
    description: 'Read access to the organization and audit log.',
    permissions: ['organizations.read', 'audit.read', 'notifications.read', 'domains.read', 'dns.read', 'storage.read', 'security.read', 'sites.read', 'cloud.read', 'billing.read'],
  },
  {
    id: 'role-viewer',
    name: 'Viewer',
    key: 'viewer',
    description: 'Read-only access.',
    permissions: ['organizations.read', 'notifications.read', 'domains.read', 'dns.read', 'storage.read', 'security.read', 'sites.read', 'cloud.read', 'billing.read'],
  },
];

const roleById = (id: string): RoleDto => roles.find((r) => r.id === id) ?? roles[0];

const organizations: OrganizationDto[] = [
  { id: 'org-omnex-hq', name: 'OMNEX HQ', slug: 'omnex-hq', plan_tier: 'free', status: 'active', created_at: '2026-01-15T09:00:00Z' },
];

const users: MockUser[] = [
  {
    id: 'user-demo-owner',
    name: 'Demo Owner',
    email: 'demo@omnex.cloud',
    password: 'password',
    mfa_enabled: false,
    locale: null,
    status: 'active',
    email_verified_at: '2026-01-15T09:00:00Z',
    created_at: '2026-01-15T09:00:00Z',
  },
  {
    id: 'user-dev',
    name: 'Dev User',
    email: 'dev@omnex.cloud',
    password: 'password',
    mfa_enabled: false,
    locale: null,
    status: 'active',
    email_verified_at: '2026-01-16T10:00:00Z',
    created_at: '2026-01-16T10:00:00Z',
  },
];

const memberships: MockMembership[] = [
  { id: 'memb-owner', organizationId: 'org-omnex-hq', userId: 'user-demo-owner', roleId: 'role-owner', status: 'active', joined_at: '2026-01-15T09:05:00Z' },
  { id: 'memb-dev', organizationId: 'org-omnex-hq', userId: 'user-dev', roleId: 'role-developer', status: 'active', joined_at: '2026-01-16T10:05:00Z' },
];

const invitations: MockInvitation[] = [
  {
    id: 'inv-1',
    organizationId: 'org-omnex-hq',
    email: 'alice@example.com',
    roleId: 'role-viewer',
    token: 'mock-invite-token-1',
    status: 'pending',
    expires_at: '2026-09-01T00:00:00Z',
    created_at: '2026-02-01T12:00:00Z',
  },
];

const auditLogs: AuditLogDto[] = [
  { id: 1, action: 'organization.created', resource_type: 'organization', resource_id: 'org-omnex-hq', result: 'success', ip_address: '127.0.0.1', created_at: '2026-01-15T09:05:00Z' },
  { id: 2, action: 'member.invited', resource_type: 'invitation', resource_id: 'inv-1', result: 'success', ip_address: '127.0.0.1', created_at: '2026-02-01T12:00:00Z' },
  { id: 3, action: 'user.logged_in', resource_type: 'user', resource_id: 'user-demo-owner', result: 'success', ip_address: '127.0.0.1', created_at: '2026-02-10T08:30:00Z' },
];

const contactLeads: ContactLeadDto[] = [];

const notifications: NotificationDto[] = [
  {
    id: 'notif-1',
    type: 'security',
    severity: 'danger',
    title: 'MFA is disabled',
    body: 'Protect your account by enabling two-factor authentication.',
    route: '/settings',
    read_at: null,
    created_at: '2026-08-16T10:45:00Z',
  },
  {
    id: 'notif-2',
    type: 'domain',
    severity: 'warning',
    title: 'Domain expiring soon',
    body: 'omnex.cloud expires in 24 days.',
    route: '/domains/dom-omnex-dev',
    read_at: null,
    created_at: '2026-08-15T18:30:00Z',
  },
  {
    id: 'notif-3',
    type: 'deployment',
    severity: 'success',
    title: 'Deployment completed',
    body: 'main → production succeeded for Marketing.',
    route: '/sites',
    read_at: null,
    created_at: '2026-08-14T12:00:00Z',
  },
  {
    id: 'notif-4',
    type: 'welcome',
    severity: 'info',
    title: 'Welcome to OMNEX',
    body: 'Your OMNEX Cloud OS organization is ready.',
    route: null,
    read_at: '2026-01-15T09:06:00Z',
    created_at: '2026-01-15T09:05:00Z',
  },
  {
    id: 'notif-5',
    type: 'billing',
    severity: 'info',
    title: 'Invoice generated',
    body: 'Invoice #2026-0814 for the OMNEX free plan.',
    route: '/billing',
    read_at: '2026-08-13T11:00:00Z',
    created_at: '2026-08-13T11:00:00Z',
  },
  {
    id: 'notif-6',
    type: 'system',
    severity: 'info',
    title: 'Backup completed',
    body: 'Daily incremental snapshot finished.',
    route: null,
    read_at: null,
    created_at: '2026-08-12T02:00:00Z',
  },
  {
    id: 'notif-7',
    type: 'member',
    severity: 'success',
    title: 'Member invited',
    body: 'Dev User was invited to OMNEX HQ.',
    route: '/members',
    read_at: '2026-08-11T10:30:00Z',
    created_at: '2026-08-11T10:30:00Z',
  },
  {
    id: 'notif-8',
    type: 'security',
    severity: 'warning',
    title: 'SSL certificate expiring',
    body: 'omnex.cloud certificate expires in 24 days.',
    route: '/security',
    read_at: '2026-08-10T09:15:00Z',
    created_at: '2026-08-10T09:15:00Z',
  },
];

function sortedNotifications(): NotificationDto[] {
  return [...notifications].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''));
}

type NotificationListener = (notification: NotificationDto) => void;
const notificationListeners = new Set<NotificationListener>();

function emitNotification(notification: NotificationDto): void {
  for (const listener of notificationListeners) listener(notification);
}

function pushNotification(
  type: string,
  severity: NotificationDto['severity'],
  title: string,
  body: string,
  route: string | null = null,
): NotificationDto {
  const notification: NotificationDto = {
    id: uid('notif'),
    type,
    severity,
    title,
    body,
    route,
    read_at: null,
    created_at: nowIso(),
  };
  notifications.unshift(notification);
  emitNotification(notification);
  return notification;
}

const activityType = (action: string): string => {
  if (action.startsWith('user.mfa')) return 'security';
  if (action.startsWith('member.')) return 'member';
  if (action.startsWith('organization')) return 'organization';
  if (action.startsWith('user.')) return 'auth';
  return 'system';
};

const activityTitle = (action: string): string => {
  switch (action) {
    case 'user.registered': return 'User registered';
    case 'user.logged_in': return 'Sign in';
    case 'user.logged_out': return 'Sign out';
    case 'user.mfa_enabled': return 'MFA enabled';
    case 'user.mfa_disabled': return 'MFA disabled';
    case 'user.mfa_failed': return 'Failed MFA attempt';
    case 'organization.created': return 'Organization created';
    case 'organization.switched': return 'Switched organization';
    case 'member.invited': return 'Member invited';
    case 'member.invitation_accepted': return 'Invitation accepted';
    case 'member.invitation_cancelled': return 'Invitation cancelled';
    case 'member.role_changed': return 'Role changed';
    case 'member.removed': return 'Member removed';
    default: return action;
  }
};

const activityDescription = (action: string): string => {
  switch (action) {
    case 'organization.created': return 'New organization created';
    case 'member.invited': return 'Invitation sent to a new member';
    case 'user.logged_in': return 'Signed in';
    case 'user.registered': return 'New account created';
    default: return action;
  }
};

let activitySeq = 100;
let activity: ActivityItem[] = auditLogs.map((log) => ({
  id: log.id,
  type: activityType(log.action),
  severity: log.result === 'success' ? 'success' : 'danger',
  title: activityTitle(log.action),
  description: activityDescription(log.action),
  actor: null,
  created_at: log.created_at,
}));

const activityPool: Array<Omit<ActivityItem, 'id' | 'created_at'>> = [
  { type: 'deployment', severity: 'info', title: 'Deployment started', description: 'main → production (OMNEX HQ)', actor: 'Dev User' },
  { type: 'deployment', severity: 'success', title: 'Deployment completed', description: 'main → production (OMNEX HQ)', actor: 'Dev User' },
  { type: 'ssl', severity: 'warning', title: 'SSL certificate expiring', description: 'omnex.cloud expires in 24 days', actor: 'System' },
  { type: 'domain', severity: 'success', title: 'Domain registered', description: 'omnex.cloud', actor: 'Demo Owner' },
  { type: 'security', severity: 'info', title: 'Security scan completed', description: 'No critical findings', actor: 'System' },
  { type: 'backup', severity: 'success', title: 'Backup completed', description: 'Daily incremental snapshot', actor: 'System' },
  { type: 'incident', severity: 'warning', title: 'High CPU detected', description: 'worker-1 above 90% for 5 min', actor: 'System' },
];

type ActivityListener = (item: ActivityItem) => void;
const activityListeners = new Set<ActivityListener>();

function emitActivity(item: ActivityItem): void {
  for (const listener of activityListeners) listener(item);
}

function addActivityEvent(item: Omit<ActivityItem, 'id' | 'created_at'>): ActivityItem {
  const next: ActivityItem = { ...item, id: ++activitySeq, created_at: nowIso() };
  activity = [...activity, next];
  emitActivity(next);
  return next;
}

// Keep the demo feed alive with a synthetic event stream, mirroring the real
// backend pushing audit events. Starts on first subscriber, stops on last.
let activityTicker: ReturnType<typeof setInterval> | null = null;
const ACTIVITY_TICK_MS = 5000;

function ensureActivityTicker(): void {
  if (activityTicker !== null) return;
  activityTicker = setInterval(() => {
    const next = activityPool[Math.floor(Math.random() * activityPool.length)];
    addActivityEvent(next);
  }, ACTIVITY_TICK_MS);
}

function stopActivityTicker(): void {
  if (activityTicker !== null) {
    clearInterval(activityTicker);
    activityTicker = null;
  }
}

// --- Domain + DNS engine (Phase 3 sandbox) ---------------------------------

const RESERVED_DOMAINS = ['omnex.cloud', 'omnex.io', 'nexus.com', 'cloud.com', 'google.com', 'apple.com'];
const DOMAIN_PRICES: Record<string, number> = {
  com: 12.99,
  net: 14.99,
  org: 11.99,
  io: 49.99,
  dev: 14.99,
  co: 29.99,
  app: 19.99,
  cloud: 19.99,
  ca: 13.99,
};

function crc32(value: string): number {
  let crc = 0xffffffff;
  for (let i = 0; i < value.length; i++) {
    let c = (crc ^ value.charCodeAt(i)) & 0xff;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    crc = (crc >>> 8) ^ c;
  }
  return (crc ^ 0xffffffff) >>> 0;
}

const domainAvailable = (domain: string): boolean =>
  !RESERVED_DOMAINS.includes(domain.toLowerCase()) && crc32(domain.toLowerCase()) % 5 !== 0;

const DOMAIN_PROVIDERS: DomainProviderDto[] = [
  { name: 'sandbox', label: 'Sandbox', configured: true },
  { name: 'namecheap', label: 'Namecheap', configured: false },
  { name: 'ovh', label: 'OVH', configured: false },
  { name: 'custom', label: 'Custom', configured: false },
];

function requireProviderConfigured(provider?: string): void {
  if (!provider) return;
  const selected = DOMAIN_PROVIDERS.find((p) => p.name === provider);
  if (!selected) {
    throw new ApiError(422, 'Unknown provider', `Unknown domain provider [${provider}].`);
  }
  if (!selected.configured) {
    throw new ApiError(422, 'Not configured', `The [${selected.label}] registrar is not configured.`);
  }
}

const domains: DomainDto[] = [
  {
    id: 'dom-omnex-dev',
    name: 'omnex.cloud',
    status: 'active',
    provider: 'sandbox',
    registered_at: '2025-10-01T00:00:00Z',
    expires_at: '2026-10-01T00:00:00Z',
    auto_renew: true,
    privacy_protection: true,
    transfer_lock: true,
    nameservers: ['ns1.omnex.io', 'ns2.omnex.io'],
    created_at: '2025-10-01T00:00:00Z',
    zone_id: 'zone-omnex-dev',
  },
  {
    id: 'dom-omnex-io',
    name: 'omnex.io',
    status: 'active',
    provider: 'sandbox',
    registered_at: '2025-09-14T00:00:00Z',
    expires_at: '2026-09-14T00:00:00Z',
    auto_renew: true,
    privacy_protection: true,
    transfer_lock: true,
    nameservers: ['ns1.omnex.io', 'ns2.omnex.io'],
    created_at: '2025-09-14T00:00:00Z',
    zone_id: 'zone-omnex-io',
  },
];

const dnsRecords: DnsRecordDto[] = [
  { id: 'rr-1', zone_id: 'zone-omnex-dev', type: 'A', name: '@', content: '192.0.2.10', ttl: 3600, priority: null, proxied: false, created_at: '2025-10-01T00:05:00Z' },
  { id: 'rr-2', zone_id: 'zone-omnex-dev', type: 'CNAME', name: 'www', content: '@', ttl: 3600, priority: null, proxied: false, created_at: '2025-10-01T00:05:00Z' },
  { id: 'rr-3', zone_id: 'zone-omnex-dev', type: 'TXT', name: '@', content: 'v=spf1 include:spf.omnex.io ~all', ttl: 3600, priority: null, proxied: false, created_at: '2025-10-01T00:05:00Z' },
  { id: 'rr-4', zone_id: 'zone-omnex-io', type: 'A', name: '@', content: '198.51.100.7', ttl: 3600, priority: null, proxied: false, created_at: '2025-09-14T00:05:00Z' },
  { id: 'rr-5', zone_id: 'zone-omnex-io', type: 'MX', name: '@', content: 'mail', priority: 10, ttl: 3600, proxied: false, created_at: '2025-09-14T00:05:00Z' },
];

const dnsHistory: DnsHistoryDto[] = [];

interface MockDriveVersion extends DriveVersionDto {
  content: string;
}

const DRIVE_QUOTA_LIMIT = 10 * 1024 * 1024 * 1024; // 10 GiB

const driveFolders: DriveFolderDto[] = [];
const driveFiles: DriveFileDto[] = [];
const driveVersions: MockDriveVersion[] = [];

function driveQuotaUsed(): number {
  return driveVersions.reduce((sum, v) => sum + v.size, 0);
}

function driveFileById(fileId: string): DriveFileDto {
  const file = driveFiles.find((f) => f.id === fileId);
  if (!file) throw new ApiError(404, 'Not found', 'File not found.');
  return file;
}

function driveVersionsOf(fileId: string): MockDriveVersion[] {
  return driveVersions.filter((v) => v.file_id === fileId).sort((a, b) => b.version - a.version);
}

const BILLING_PROVIDERS: PaymentProviderDto[] = [
  { name: 'sandbox', label: 'Sandbox', configured: true },
  { name: 'stripe', label: 'Stripe', configured: false },
];

const BILLING_PLANS: BillingPlanDto[] = [
  { id: 'plan-free', slug: 'free', name: 'Free', description: 'For personal projects and evaluation.', price_monthly: 0, price_yearly: 0, currency: 'usd', features: ['1 seat', '1 domain', '1 GB storage', 'Community support'] },
  { id: 'plan-starter', slug: 'starter', name: 'Starter', description: 'For small teams shipping their first product.', price_monthly: 1200, price_yearly: 12000, currency: 'usd', features: ['5 seats', '10 domains', '25 GB storage', 'Email support'] },
  { id: 'plan-pro', slug: 'pro', name: 'Pro', description: 'For growing teams with production workloads.', price_monthly: 4900, price_yearly: 49000, currency: 'usd', features: ['Unlimited seats', 'Unlimited domains', '250 GB storage', 'Priority support'] },
  { id: 'plan-business', slug: 'business', name: 'Business', description: 'For organizations with compliance and scale needs.', price_monthly: 19900, price_yearly: 199000, currency: 'usd', features: ['Unlimited everything', 'SLA & compliance', 'Dedicated support', 'SSO & audit'] },
];

const subscriptions: SubscriptionDto[] = [];
const invoices: InvoiceDto[] = [];

// Demo coupon catalog mirroring the backend `coupons` table.
const mockCoupons: CouponAdminDto[] = [
  { id: 'coupon-launch25', code: 'LAUNCH25', name: 'Launch 25%', description: '25% off the first month.', discount_type: 'percent', discount_value: 25, currency: 'usd', max_redemptions: 500, times_redeemed: 0, active: true, expires_at: null, created_at: '2026-08-01T09:00:00Z' },
  { id: 'coupon-credit10', code: 'CREDIT10', name: '10$ off', description: '10 USD off any plan.', discount_type: 'amount', discount_value: 1000, currency: 'usd', max_redemptions: null, times_redeemed: 0, active: true, expires_at: null, created_at: '2026-08-01T09:00:00Z' },
];

const couponRedemptions: Array<CouponRedemptionDto & { couponId: string }> = [];

function couponByCode(code: string): CouponDto {
  const coupon = mockCoupons.find((c) => c.code === code.trim().toUpperCase() && c.active);
  if (!coupon) throw new ApiError(422, 'Validation failed', undefined, { coupon: ['This coupon code does not exist.'] });
  return { code: coupon.code, name: coupon.name, discount_type: coupon.discount_type, discount_value: coupon.discount_value, discount: 0 };
}

function couponDiscount(coupon: CouponDto, amountCents: number): number {
  if (coupon.discount_type === 'percent') return Math.round((amountCents * coupon.discount_value) / 100);
  return Math.min(coupon.discount_value, amountCents);
}

function toAppliedCoupon(coupon: CouponDto): AppliedCouponDto {
  return { id: coupon.code, code: coupon.code, name: coupon.name, discount_type: coupon.discount_type, discount_value: coupon.discount_value };
}

const creditEntries: CreditEntryDto[] = [];

function creditBalance(): number {
  return creditEntries.reduce((sum, entry) => sum + entry.amount, 0);
}

function billingPlanById(id: string): BillingPlanDto {
  const plan = BILLING_PLANS.find((p) => p.id === id);
  if (!plan) throw new ApiError(404, 'Not found', 'Plan not found.');
  return plan;
}

const SITE_PROVIDERS: SiteProviderDto[] = [
  { name: 'sandbox', label: 'Sandbox', configured: true },
  { name: 'custom', label: 'Custom', configured: false },
];

const sites: SiteDto[] = [];
const siteDeployments: SiteDeploymentDto[] = [];

function siteById(siteId: string): SiteDto {
  const site = sites.find((s) => s.id === siteId);
  if (!site) throw new ApiError(404, 'Not found', 'Site not found.');
  return site;
}

function siteDeploymentsOf(siteId: string): SiteDeploymentDto[] {
  return siteDeployments
    .filter((d) => d.site_id === siteId)
    .sort((a, b) => b.number - a.number);
}

function sandboxCommitSha(gitUrl: string, branch: string): string {
  // Deterministic pseudo-hash, mirroring the backend sandbox.
  let hash = 0;
  const input = `${gitUrl}:${branch}`;
  for (let i = 0; i < input.length; i += 1) {
    hash = (hash * 31 + input.charCodeAt(i)) >>> 0;
  }
  return hash.toString(16).padStart(12, '0').slice(0, 12);
}

function sandboxSiteUrl(name: string): string {
  const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  return `https://${slug}.omnex-sites.test`;
}

// Simulated provider tokens: set them to see the provider marked configured
// in the UI (mirrors backend/.env HETZNER_API_TOKEN / DO_API_TOKEN / etc).
const mockProviderTokens: Record<string, boolean> = {
  sandbox: true,
  hetzner: false,
  digitalocean: false,
  custom: false,
};

export function setMockProviderConfigured(provider: string, configured: boolean): void {
  mockProviderTokens[provider] = configured;
}

const CLOUD_PROVIDERS: CloudProviderDto[] = [
  { name: 'sandbox', label: 'Sandbox', configured: true },
  { name: 'hetzner', label: 'Hetzner', configured: mockProviderTokens.hetzner },
  { name: 'digitalocean', label: 'DigitalOcean', configured: mockProviderTokens.digitalocean },
  { name: 'custom', label: 'Custom', configured: mockProviderTokens.custom },
];

export function cloudProviders(): CloudProviderDto[] {
  return CLOUD_PROVIDERS.map((provider) => ({ ...provider, configured: mockProviderTokens[provider.name] ?? false }));
}

const sshKeys: SshKeyDto[] = [];
const servers: ServerDto[] = [];
const serverOperations: ServerOperationDto[] = [];
const serverSnapshots: ServerSnapshotDto[] = [];

// Encrypted vault (mock): the private key text is "sealed" with a verifier
// derived from the vault password. Only the presence of a private key is
// exposed on the DTO; the ciphertext and salt are never returned by the API.
const sshVault = new Map<string, { privateKey: string; verifier: string }>();

function mockVaultVerifier(password: string): string {
  let hash = 2166136261;
  const input = `omnex.vault.v1:${password}`;
  for (let i = 0; i < input.length; i += 1) {
    hash ^= input.charCodeAt(i);
    hash = Math.imul(hash, 16777619) >>> 0;
  }
  return hash.toString(16).padStart(16, '0');
}

function sshKeyById(keyId: string): SshKeyDto {
  const key = sshKeys.find((k) => k.id === keyId);
  if (!key) throw new ApiError(404, 'Not found', 'SSH key not found.');
  return key;
}

function mockFingerprint(publicKey: string): string {
  let hash = 2166136261;
  for (let i = 0; i < publicKey.length; i += 1) {
    hash ^= publicKey.charCodeAt(i);
    hash = Math.imul(hash, 16777619) >>> 0;
  }
  return `SHA256:${hash.toString(16).padStart(8, '0')}${hash.toString(16).slice(0, 8)}`;
}

function serverById(serverId: string): ServerDto {
  const server = servers.find((s) => s.id === serverId);
  if (!server) throw new ApiError(404, 'Not found', 'Server not found.');
  return server;
}

function serverOperationsOf(serverId: string): ServerOperationDto[] {
  return serverOperations
    .filter((o) => o.server_id === serverId)
    .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''));
}

function sandboxServerHash(name: string, region: string, plan: string, image: string): string {
  let hash = 2166136261;
  const input = `${name}:${region}:${plan}:${image}`;
  for (let i = 0; i < input.length; i += 1) {
    hash ^= input.charCodeAt(i);
    hash = Math.imul(hash, 16777619) >>> 0;
  }
  return hash.toString(16).padStart(8, '0');
}

function sandboxServerId(name: string, region: string, plan: string, image: string): string {
  const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
  return `sbox-srv-${slug}-${sandboxServerHash(name, region, plan, image).slice(0, 6)}`;
}

function sandboxServerIpv4(name: string, region: string, plan: string, image: string): string {
  const hex = sandboxServerHash(name, region, plan, image);
  const a = (parseInt(hex.slice(0, 2), 16) % 250) + 1;
  const b = (parseInt(hex.slice(2, 4), 16) % 250) + 1;
  const c = (parseInt(hex.slice(4, 6), 16) % 250) + 1;
  return `10.${a}.${b}.${c}`;
}

function mockMetricsSample(seed: string, bucketOffset = 0): ServerMetricsDto {
  // Deterministic walk over 5-second buckets, mirroring the backend sandbox.
  // bucketOffset < 0 backfills the history (older buckets).
  const bucket = Math.floor(Date.now() / 5000) + bucketOffset;
  let hash = 2166136261;
  const input = `${seed}:${bucket}`;
  for (let i = 0; i < input.length; i += 1) {
    hash ^= input.charCodeAt(i);
    hash = Math.imul(hash, 16777619) >>> 0;
  }

  const cpu = Math.max(5, Math.min(95, 12 + (hash % 68) + (bucket % 6)));
  const memoryTotal = 4 * 1024 * 1024 * 1024;
  const memoryUsed = Math.round(memoryTotal * (0.26 + ((hash >>> 3) % 34) / 100));
  const diskTotal = 80 * 1024 * 1024 * 1024;
  const diskUsed = Math.round(diskTotal * (0.34 + ((hash >>> 5) % 8) / 100));

  return {
    server_id: seed,
    cpu,
    memory_used: memoryUsed,
    memory_total: memoryTotal,
    disk_used: diskUsed,
    disk_total: diskTotal,
    sampled_at: nowIso(),
  };
}

// Threshold alerts: when a sample crosses a usage limit an OMNEX notification
// (type `server.alert`) is raised — mirroring the backend's
// `ServerService::checkMetricsThresholds`. Cooldown is per metric per server.
// Exported so tests can force a breach (the synthetic cpu never exceeds 85%).
export const ALERT_THRESHOLDS = { cpu: 90, memory: 90, disk: 90 };
const ALERT_COOLDOWN_MS = 3600 * 1000;
const lastAlertAt = new Map<string, number>();

function checkMetricsThresholds(server: ServerDto, metrics: ServerMetricsDto): void {
  const usage = {
    cpu: metrics.cpu,
    memory: metrics.memory_total > 0 ? (metrics.memory_used / metrics.memory_total) * 100 : 0,
    disk: metrics.disk_total > 0 ? (metrics.disk_used / metrics.disk_total) * 100 : 0,
  };

  const now = Date.now();
  const breached: string[] = [];

  for (const [metric, limit] of Object.entries(ALERT_THRESHOLDS)) {
    const percent = usage[metric as keyof typeof usage];
    if (percent < limit) continue;

    const key = `${server.id}:${metric}`;
    const last = lastAlertAt.get(key) ?? 0;
    if (now - last < ALERT_COOLDOWN_MS) continue;

    lastAlertAt.set(key, now);
    breached.push(`${metric} at ${Math.round(percent)}% (limit ${limit}%)`);
  }

  if (breached.length === 0) return;

  pushNotification(
    'server.alert',
    'warning',
    `High resource usage on ${server.name}`,
    breached.join(', '),
    '/cloud',
  );
}

function runServerOperation(server: ServerDto, type: string, successStatus: string): ServerOperationDto {
  const now = nowIso();
  const failed = server.name.includes('fail');
  const operation: ServerOperationDto = {
    id: uid('server-op'),
    server_id: server.id,
    type,
    status: failed ? 'failed' : 'succeeded',
    started_at: now,
    completed_at: now,
    result: failed ? null : null,
    error: failed ? `Operation failed: the server name contains the deterministic failure trigger "fail".` : null,
    created_at: now,
    updated_at: now,
  };
  serverOperations.push(operation);
  if (!failed) {
    server.status = successStatus;
  }
  server.updated_at = now;
  return operation;
}

const dismissedSecurityFindingIds = new Set<string>();

const SECURITY_PENALTIES: Record<string, number> = { high: 25, medium: 15, low: 10 };

function securityFindingId(rule: string, resourceId: string | null): string {
  return `sec-${rule}-${resourceId ?? 'org'}`;
}

function securityFinding(
  rule: string,
  severity: 'high' | 'medium' | 'low',
  resourceType: string | null,
  resourceId: string | null,
  metadata: Record<string, unknown>,
): SecurityFindingDto {
  const id = securityFindingId(rule, resourceId);
  return {
    id,
    rule,
    severity,
    status: dismissedSecurityFindingIds.has(id) ? 'dismissed' : 'open',
    resource_type: resourceType,
    resource_id: resourceId,
    metadata,
    created_at: nowIso(),
  };
}

function computeSecurityFindings(): SecurityFindingDto[] {
  const activeMembers = memberships.filter((m) => m.status === 'active');
  const memberUsers = activeMembers
    .map((m) => users.find((u) => u.id === m.userId))
    .filter((u): u is MockUser => !!u);

  const findings: SecurityFindingDto[] = [];

  for (const u of memberUsers) {
    if (!u.mfa_enabled) {
      findings.push(securityFinding('mfa', 'high', 'user', u.id, { name: u.name, email: u.email }));
    }
    if (!u.email_verified_at) {
      findings.push(securityFinding('email', 'low', 'user', u.id, { name: u.name, email: u.email }));
    }
  }

  if (activeMembers.length <= 1) {
    findings.push(securityFinding('single_member', 'medium', null, null, { member_count: activeMembers.length }));
  }

  const now = Date.now();
  const warningMs = 30 * 86400000;
  for (const domain of domains) {
    if (!domain.expires_at) continue;
    const exp = new Date(domain.expires_at).getTime();
    if (exp > now && exp <= now + warningMs) {
      const days = Math.max(0, Math.ceil((exp - now) / 86400000));
      findings.push(
        securityFinding('domain_expiring', 'medium', 'domain', domain.id, {
          domain: domain.name,
          expires_at: domain.expires_at,
          days,
        }),
      );
    }
  }

  for (const domain of domains) {
    if (!domain.zone_id) continue;
    if (!dnssecState(domain.zone_id).enabled) {
      findings.push(
        securityFinding('dnssec_disabled', 'low', 'dns_zone', domain.zone_id, { domain: domain.name }),
      );
    }
  }

  return findings;
}

function computeSecurityReport(): SecurityScoreDto {
  const findings = computeSecurityFindings();
  const open = findings.filter((f) => f.status === 'open');
  const score = Math.max(0, 100 - open.reduce((sum, f) => sum + (SECURITY_PENALTIES[f.severity] ?? 0), 0));

  return {
    score,
    summary: {
      open: open.length,
      resolved: 0,
      dismissed: dismissedSecurityFindingIds.size,
      high: open.filter((f) => f.severity === 'high').length,
      medium: open.filter((f) => f.severity === 'medium').length,
      low: open.filter((f) => f.severity === 'low').length,
    },
    findings: open,
  };
}

const DNS_TEMPLATES: Record<string, DnsRecordInput[]> = {
  website: [
    { type: 'A', name: '@', content: '192.0.2.10', ttl: 3600 },
    { type: 'AAAA', name: '@', content: '2001:db8::10', ttl: 3600 },
    { type: 'CNAME', name: 'www', content: '@', ttl: 3600 },
  ],
  email: [
    { type: 'MX', name: '@', content: 'mail', priority: 10, ttl: 3600 },
    { type: 'TXT', name: '@', content: 'v=spf1 include:spf.omnex.io ~all', ttl: 3600 },
    { type: 'TXT', name: '_dmarc', content: 'v=DMARC1; p=none', ttl: 3600 },
    { type: 'CAA', name: '@', content: '0 issue "letsencrypt.org"', ttl: 3600 },
  ],
};

function recordSnapshot(record: DnsRecordDto): DnsRecordInput {
  return {
    type: record.type,
    name: record.name,
    content: record.content,
    ttl: record.ttl,
    priority: record.priority,
    proxied: record.proxied,
  };
}

function computeDsRecord(domain: string): DnssecDsRecord {
  let digest = '';
  for (let i = 0; i < 8; i++) {
    digest += crc32(`omnex:dnssec:${i}:${domain}`).toString(16).padStart(8, '0');
  }
  return {
    key_tag: crc32(domain) % 65536,
    algorithm: 13,
    digest_type: 2,
    digest: digest.toUpperCase(),
  };
}

const dnssecByZone = new Map<string, DnssecStatus>();

function dnssecState(zoneId: string): DnssecStatus {
  let state = dnssecByZone.get(zoneId);
  if (!state) {
    state = { enabled: false, status: 'unsigned', ds_records: [] };
    dnssecByZone.set(zoneId, state);
  }
  return state;
}

function computePropagationChecks(domain: DomainDto): PropagationCheckDto[] {
  const nameservers = domain.nameservers?.length ? domain.nameservers : ['ns1.omnex.io', 'ns2.omnex.io'];
  const checks: PropagationCheckDto[] = [];
  for (const ns of nameservers) {
    for (const record of zoneRecords(domain.zone_id ?? '')) {
      const seed = `${domain.name}|${ns}|${record.name}|${record.type}|${record.content}`;
      const roll = crc32(seed) % 10;
      const status: PropagationStatus = roll < 7 ? 'synced' : roll < 9 ? 'pending' : 'outdated';
      checks.push({
        id: uid('prop'),
        nameserver: ns,
        record_type: record.type,
        record_name: record.name,
        status,
        expected: [record.content],
        observed: status === 'synced' ? [record.content] : null,
        checked_at: nowIso(),
      });
    }
  }
  return checks;
}

function buildPropagationStatus(domain: DomainDto, checks: PropagationCheckDto[]): PropagationStatusDto {
  const summary: Record<PropagationStatus, number> = { synced: 0, pending: 0, outdated: 0, error: 0 };
  for (const check of checks) summary[check.status] += 1;
  const nameservers = domain.nameservers?.length ? domain.nameservers : ['ns1.omnex.io', 'ns2.omnex.io'];
  return {
    domain: domain.name,
    nameservers,
    checked_at: checks.length > 0 ? (checks[0].checked_at ?? null) : null,
    data: checks,
    summary: { ...summary, total: checks.length },
  };
}

const propagationByZone = new Map<string, PropagationCheckDto[]>();

function requireDomain(domainId: string): DomainDto {
  const domain = domains.find((d) => d.id === domainId);
  if (!domain) throw new ApiError(404, 'Not found');
  return domain;
}

function zoneRecords(zoneId: string): DnsRecordDto[] {
  return dnsRecords.filter((r) => r.zone_id === zoneId);
}

function buildZoneFile(domain: DomainDto): string {
  const lines = [`$ORIGIN ${domain.name}.`, '$TTL 3600', ''];
  for (const r of zoneRecords(domain.zone_id ?? '')) {
    const name = r.name === '@' || r.name === '' ? '@' : r.name;
    const hostnameTypes = ['CNAME', 'MX', 'NS', 'SRV'];
    const content = hostnameTypes.includes(r.type) && r.content !== '@' ? `${r.content.replace(/\.$/, '')}.` : r.content;
    const priority = r.type === 'MX' || r.type === 'SRV' ? `\t${r.priority ?? 0}` : '';
    lines.push(`${name}\t${r.ttl}\tIN\t${r.type}${priority}\t${content}`);
  }
  return `${lines.join('\n')}\n`;
}

function parseZoneFile(zoneFile: string): DnsRecordInput[] {
  const records: DnsRecordInput[] = [];
  let ttl = 3600;

  for (const raw of zoneFile.split(/\r?\n/)) {
    let line = raw;
    const comment = line.indexOf(';');
    if (comment !== -1) line = line.slice(0, comment);
    line = line.trim();
    if (!line) continue;

    const ttlMatch = line.match(/^\$TTL\s+(\d+)$/i);
    if (ttlMatch) {
      ttl = parseInt(ttlMatch[1], 10);
      continue;
    }
    if (/^\$ORIGIN/i.test(line)) continue;

    const tokens = line.split(/\s+/);
    const name = (tokens.shift() ?? '@').replace(/\.$/, '');
    if (/^\d+$/.test(tokens[0] ?? '')) ttl = parseInt(tokens.shift() ?? '3600', 10);
    if ((tokens[0] ?? '').toUpperCase() === 'IN') tokens.shift();
    const type = (tokens.shift() ?? '').toUpperCase();

    let priority: number | null = null;
    if ((type === 'MX' || type === 'SRV') && /^\d+$/.test(tokens[0] ?? '')) {
      priority = parseInt(tokens.shift() ?? '0', 10);
    }

    const content = tokens.join(' ').trim().replace(/\.$/, '');
    if (!content) continue;

    records.push({ type, name: name === '@' || name === '' ? '@' : name, content, ttl, priority });
  }

  return records;
}

const SOCIAL_PROVIDERS: SocialProviderDto[] = [
  { name: 'sdp', label: 'Serveurs du Peuple', configured: true },
  { name: 'google', label: 'Google', configured: true },
  { name: 'microsoft', label: 'Microsoft', configured: true },
  { name: 'apple', label: 'Apple', configured: true },
  { name: 'facebook', label: 'Facebook', configured: true },
  { name: 'amazon', label: 'Amazon', configured: true },
  { name: 'github', label: 'GitHub', configured: true },
  { name: 'openai', label: 'OpenAI', configured: true },
];

interface MockSocialAccount extends SocialAccountDto {
  userId: string;
}

const socialAccounts: MockSocialAccount[] = [];

let currentUserId: string | null = null;
const mfaChallenges = new Map<string, string>();

const findUserByEmail = (email: string): MockUser | undefined =>
  users.find((u) => u.email.toLowerCase() === email.toLowerCase());

const requireUser = (): MockUser => {
  const user = users.find((u) => u.id === currentUserId);
  if (!user) throw new ApiError(401, 'Unauthenticated');
  return user;
};

const activeOrganization = (): OrganizationDto | null => {
  const orgId = session.getOrganizationId();
  return organizations.find((o) => o.id === orgId) ?? null;
};

const permissionKeysFor = (roleId: string): string[] =>
  roleById(roleId).permissions ?? [];

const toUserDto = (user: MockUser): UserDto => ({
  id: user.id,
  name: user.name,
  email: user.email,
  email_verified_at: user.email_verified_at,
  mfa_enabled: user.mfa_enabled,
  locale: user.locale,
  status: user.status,
  last_login_at: user.last_login_at,
  created_at: user.created_at,
});

const toMembershipDto = (m: MockMembership): MembershipDto => {
  const user = users.find((u) => u.id === m.userId);
  return {
    id: m.id,
    status: m.status,
    joined_at: m.joined_at,
    role: roleById(m.roleId),
    user: user ? toUserDto(user) : null,
    organization: organizations.find((o) => o.id === m.organizationId) ?? null,
  };
};

const toInvitationDto = (inv: MockInvitation): InvitationDto => ({
  id: inv.id,
  email: inv.email,
  status: inv.status,
  expires_at: inv.expires_at,
  created_at: inv.created_at,
  role: roleById(inv.roleId),
  organization: organizations.find((o) => o.id === inv.organizationId) ?? null,
});

const pendingInvitationsFor = (email: string): InvitationDto[] =>
  invitations
    .filter((inv) => inv.status === 'pending' && inv.email.toLowerCase() === email.toLowerCase())
    .map(toInvitationDto);

function buildSession(user: MockUser): AuthSession {
  const userMemberships = memberships.filter((m) => m.userId === user.id);
  const org = activeOrganization() ?? organizations.find((o) => userMemberships.some((m) => m.organizationId === o.id)) ?? null;
  const role = userMemberships.find((m) => m.organizationId === org?.id)?.roleId;
  return {
    token: `mock-token-${user.id}`,
    user: toUserDto(user),
    memberships: userMemberships.map(toMembershipDto),
    active_organization: org,
    permissions: role ? permissionKeysFor(role) : [],
    pending_invitations: pendingInvitationsFor(user.email),
  };
}

export class MockApiClient implements ApiClient {
  async register(input: RegisterInput): Promise<AuthSession> {
    if (findUserByEmail(input.email)) {
      throw new ApiError(422, 'Validation failed', undefined, { email: ['The email has already been taken.'] });
    }
    const user: MockUser = {
      id: uid('user'),
      name: input.name,
      email: input.email.toLowerCase(),
      password: input.password,
      mfa_enabled: false,
      locale: null,
      status: 'active',
      email_verified_at: nowIso(),
      created_at: nowIso(),
    };
    users.push(user);
    currentUserId = user.id;
    session.setToken(`mock-token-${user.id}`);
    session.setOrganizationId(null);
    auditLogs.unshift({ id: auditLogs.length + 1, action: 'user.registered', resource_type: 'user', resource_id: user.id, result: 'success', created_at: nowIso() });
    return Promise.resolve(buildSession(user));
  }

  async login(input: LoginInput): Promise<LoginResponse> {
    const user = findUserByEmail(input.email);
    if (!user || user.password !== input.password) {
      throw new ApiError(422, 'Validation failed', undefined, { email: ['The provided credentials are incorrect.'] });
    }
    if (user.mfa_enabled) {
      const token = uid('mfa');
      mfaChallenges.set(token, user.id);
      return Promise.resolve({ mfa_required: true, mfa_token: token });
    }
    currentUserId = user.id;
    session.setToken(`mock-token-${user.id}`);
    const activeOrg = organizations.find((o) => memberships.some((m) => m.userId === user.id && m.organizationId === o.id)) ?? null;
    session.setOrganizationId(activeOrg?.id ?? null);
    return Promise.resolve(buildSession(user));
  }

  async verifyMfa(input: VerifyMfaInput): Promise<AuthSession> {
    const userId = mfaChallenges.get(input.mfa_token);
    if (!userId) throw new ApiError(401, 'The MFA challenge has expired or is invalid.');
    mfaChallenges.delete(input.mfa_token);
    const code = input.recovery_code ?? input.code ?? '';
    if (!/^\d{6}$/.test(code)) {
      throw new ApiError(422, 'Validation failed', undefined, { code: ['Invalid verification code.'] });
    }
    const user = users.find((u) => u.id === userId);
    if (!user) throw new ApiError(401, 'Unauthenticated');
    currentUserId = user.id;
    session.setToken(`mock-token-${user.id}`);
    return Promise.resolve(buildSession(user));
  }

  async me(): Promise<MeResponse> {
    const user = requireUser();
    const s = buildSession(user);
    return Promise.resolve({
      user: s.user,
      memberships: s.memberships,
      active_organization: s.active_organization,
      permissions: s.permissions,
      pending_invitations: s.pending_invitations,
    });
  }

  async updateProfile(input: UpdateProfileInput): Promise<UserDto> {
    const user = requireUser();
    user.locale = input.locale;
    return Promise.resolve(toUserDto(user));
  }

  async socialProviders(): Promise<SocialProviderDto[]> {
    return Promise.resolve(SOCIAL_PROVIDERS.map((provider) => ({ ...provider })));
  }

  async socialRedirect(provider: string, link = false): Promise<SocialRedirectResponse> {
    if (!SOCIAL_PROVIDERS.some((p) => p.name === provider)) {
      throw new ApiError(422, 'Unknown or unconfigured provider.');
    }

    if (link) {
      const user = requireUser();
      if (!socialAccounts.some((account) => account.provider === provider && account.userId === user.id)) {
        socialAccounts.push({
          id: uid('social'),
          provider,
          provider_email: user.email,
          name: user.name,
          created_at: nowIso(),
          userId: user.id,
        });
      }
      return Promise.resolve({ url: null });
    }

    const code = encodeURIComponent(`mock:${provider}:demo@omnex.cloud`);
    return Promise.resolve({ url: `/social/callback?code=${code}&provider=${provider}` });
  }

  async completeSocial(code: string): Promise<AuthSession> {
    const match = decodeURIComponent(code).match(/^mock:([a-z]+):(.+)$/);
    if (!match) throw new ApiError(400, 'Invalid mock social code.');

    const provider = match[1];
    const email = match[2].toLowerCase();

    let user = users.find((candidate) => candidate.email.toLowerCase() === email);
    if (!user) {
      const local = email.split('@')[0];
      user = {
        id: uid('user'),
        name: local.replace(/[._]+/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase()),
        email,
        password: uid('pw'),
        mfa_enabled: false,
        locale: null,
        status: 'active',
        email_verified_at: nowIso(),
        created_at: nowIso(),
      };
      users.push(user);
    }

    if (!socialAccounts.some((account) => account.provider === provider && account.userId === user.id)) {
      socialAccounts.push({
        id: uid('social'),
        provider,
        provider_email: email,
        name: user.name,
        created_at: nowIso(),
        userId: user.id,
      });
    }

    currentUserId = user.id;
    session.setToken(`mock-token-${user.id}`);
    const activeOrg = organizations.find((org) =>
      memberships.some((m) => m.userId === user.id && m.organizationId === org.id),
    ) ?? null;
    session.setOrganizationId(activeOrg?.id ?? null);

    return Promise.resolve(buildSession(user));
  }

  async listSocialAccounts(): Promise<SocialAccountDto[]> {
    const user = requireUser();
    return Promise.resolve(
      socialAccounts
        .filter((account) => account.userId === user.id)
        .map(({ userId: _userId, ...account }) => ({ ...account })),
    );
  }

  async unlinkSocial(provider: string): Promise<void> {
    const user = requireUser();
    const index = socialAccounts.findIndex((account) => account.provider === provider && account.userId === user.id);
    if (index !== -1) socialAccounts.splice(index, 1);
    return Promise.resolve();
  }

  async logout(): Promise<void> {
    currentUserId = null;
    session.clear();
    return Promise.resolve();
  }

  async setupMfa(): Promise<MfaSetupResponse> {
    const user = requireUser();
    const secret = 'MOCKTOTPSECRET' + uid('').toUpperCase();
    user.pendingMfaSecret = secret;
    return Promise.resolve({
      secret,
      otpauth_uri: `otpauth://totp/${encodeURIComponent('OMNEX')}:${encodeURIComponent(user.email)}?secret=${secret}&issuer=${encodeURIComponent('OMNEX')}`,
    });
  }

  async confirmMfa(code: string): Promise<MfaConfirmResponse> {
    const user = requireUser();
    if (!/^\d{6}$/.test(code)) {
      throw new ApiError(422, 'Validation failed', undefined, { code: ['Invalid verification code.'] });
    }
    user.mfa_enabled = true;
    user.pendingMfaSecret = undefined;
    const recoveryCodes = Array.from({ length: 8 }, () => `OMNEX-${uid('rc').toUpperCase()}`);
    user.recoveryCodes = recoveryCodes;
    return Promise.resolve({ recovery_codes: recoveryCodes });
  }

  async disableMfa(password: string): Promise<void> {
    const user = requireUser();
    if (user.password !== password) {
      throw new ApiError(422, 'Validation failed', undefined, { password: ['The password is incorrect.'] });
    }
    user.mfa_enabled = false;
    user.recoveryCodes = undefined;
    return Promise.resolve();
  }

  async listOrganizations(): Promise<MembershipDto[]> {
    const user = requireUser();
    return Promise.resolve(memberships.filter((m) => m.userId === user.id).map(toMembershipDto));
  }

  async createOrganization(name: string): Promise<OrganizationDto> {
    const user = requireUser();
    const org: OrganizationDto = { id: uid('org'), name, slug: `${name.toLowerCase().replace(/\s+/g, '-')}-${uid('').slice(-4)}`, plan_tier: 'free', status: 'active', created_at: nowIso() };
    organizations.push(org);
    memberships.push({ id: uid('memb'), organizationId: org.id, userId: user.id, roleId: 'role-owner', status: 'active', joined_at: nowIso() });
    session.setOrganizationId(org.id);
    return Promise.resolve(org);
  }

  async switchOrganization(id: string): Promise<SwitchResponse> {
    const user = requireUser();
    const m = memberships.find((x) => x.organizationId === id && x.userId === user.id);
    if (!m) throw new ApiError(403, 'You are not a member of this organization.');
    session.setOrganizationId(id);
    const org = organizations.find((o) => o.id === id);
    if (!org) throw new ApiError(404, 'Not found');
    return Promise.resolve({ active_organization: org, role: roleById(m.roleId) });
  }

  async listMembers(orgId: string): Promise<MembershipDto[]> {
    requireUser();
    return Promise.resolve(memberships.filter((m) => m.organizationId === orgId).map(toMembershipDto));
  }

  async updateMemberRole(orgId: string, membershipId: string, roleId: string): Promise<MembershipDto> {
    requireUser();
    const m = memberships.find((x) => x.id === membershipId && x.organizationId === orgId);
    if (!m) throw new ApiError(404, 'Not found');
    m.roleId = roleId;
    return Promise.resolve(toMembershipDto(m));
  }

  async removeMember(orgId: string, membershipId: string): Promise<void> {
    requireUser();
    const index = memberships.findIndex((x) => x.id === membershipId && x.organizationId === orgId);
    if (index === -1) throw new ApiError(404, 'Not found');
    memberships.splice(index, 1);
    return Promise.resolve();
  }

  async listInvitations(orgId: string): Promise<InvitationDto[]> {
    requireUser();
    return Promise.resolve(invitations.filter((inv) => inv.organizationId === orgId && inv.status === 'pending').map(toInvitationDto));
  }

  async createInvitation(orgId: string, email: string, roleId: string): Promise<InvitationDto> {
    const user = requireUser();
    if (memberships.some((m) => m.organizationId === orgId && users.find((u) => u.id === m.userId)?.email === email)) {
      throw new ApiError(422, 'This user is already a member of the organization.');
    }
    const inv: MockInvitation = {
      id: uid('inv'),
      organizationId: orgId,
      email: email.toLowerCase(),
      roleId,
      token: uid('token'),
      status: 'pending',
      expires_at: new Date(Date.now() + 7 * 86400000).toISOString(),
      created_at: nowIso(),
    };
    invitations.push(inv);
    auditLogs.unshift({ id: auditLogs.length + 1, action: 'member.invited', resource_type: 'invitation', resource_id: inv.id, result: 'success', created_at: nowIso() });
    return Promise.resolve(toInvitationDto(inv));
  }

  async cancelInvitation(orgId: string, invitationId: string): Promise<void> {
    requireUser();
    const inv = invitations.find((x) => x.id === invitationId && x.organizationId === orgId);
    if (!inv) throw new ApiError(404, 'Not found');
    inv.status = 'cancelled';
    return Promise.resolve();
  }

  async acceptInvitation(invitationId: string): Promise<void> {
    const user = requireUser();
    const inv = invitations.find((x) => x.id === invitationId && x.status === 'pending');
    if (!inv) throw new ApiError(404, 'Not found');
    inv.status = 'accepted';
    memberships.push({ id: uid('memb'), organizationId: inv.organizationId, userId: user.id, roleId: inv.roleId, status: 'active', joined_at: nowIso() });
    session.setOrganizationId(inv.organizationId);
    return Promise.resolve();
  }

  async listRoles(): Promise<RoleDto[]> {
    requireUser();
    return Promise.resolve(roles);
  }

  async listAudit(perPage = 25): Promise<Paginated<AuditLogDto>> {
    requireUser();
    const total = auditLogs.length;
    const data = auditLogs.slice(0, perPage).map((log) => {
      const user = users.find((u) => u.id === (log.resource_type === 'user' ? log.resource_id : null));
      return { ...log, user: user ? toUserDto(user) : null };
    });
    return Promise.resolve({
      data,
      meta: { current_page: 1, per_page: perPage, total, last_page: Math.max(1, Math.ceil(total / perPage)) },
    });
  }

  async listNotifications(): Promise<NotificationListDto> {
    requireUser();
    const data = sortedNotifications();
    return Promise.resolve({ data, unread: data.filter((n) => !n.read_at).length });
  }

  async listNotificationsPage(query: NotificationQuery = {}): Promise<PaginatedNotificationList> {
    requireUser();

    let list = sortedNotifications();
    if (query.type) list = list.filter((n) => n.type === query.type);
    if (query.severity) list = list.filter((n) => n.severity === query.severity);
    if (query.unread !== undefined) {
      list = list.filter((n) => (query.unread ? !n.read_at : !!n.read_at));
    }

    const perPage = query.perPage ?? 10;
    const page = Math.max(1, query.page ?? 1);
    const total = list.length;
    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const data = list.slice((page - 1) * perPage, page * perPage);

    return Promise.resolve({
      data,
      unread: notifications.filter((n) => !n.read_at).length,
      meta: { current_page: page, per_page: perPage, total, last_page: lastPage },
    });
  }

  async markNotificationRead(id: string): Promise<NotificationDto> {
    requireUser();
    const n = notifications.find((x) => x.id === id);
    if (!n) throw new ApiError(404, 'Not found');
    n.read_at = nowIso();
    return Promise.resolve({ ...n });
  }

  async markAllNotificationsRead(): Promise<void> {
    requireUser();
    for (const n of notifications) n.read_at = nowIso();
    return Promise.resolve();
  }

  subscribeNotifications(handler: NotificationListener): () => void {
    notificationListeners.add(handler);
    return () => {
      notificationListeners.delete(handler);
    };
  }

  async listActivity(sinceId?: number): Promise<ActivityFeed> {
    requireUser();

    // Pure cursor read; new events arrive through subscribeActivity().
    const filtered = sinceId ? activity.filter((a) => a.id > sinceId) : activity;
    const latestId = filtered.length > 0 ? Math.max(...filtered.map((a) => a.id)) : (sinceId ?? 0);

    return {
      data: filtered.slice().reverse().slice(0, 50),
      latest_id: latestId,
    };
  }

  subscribeActivity(handler: ActivityListener): () => void {
    activityListeners.add(handler);
    ensureActivityTicker();
    return () => {
      activityListeners.delete(handler);
      if (activityListeners.size === 0) stopActivityTicker();
    };
  }

  async listDomains(): Promise<DomainDto[]> {
    requireUser();
    return Promise.resolve([...domains]);
  }

  async listDomainProviders(): Promise<DomainProviderDto[]> {
    requireUser();
    return Promise.resolve([...DOMAIN_PROVIDERS]);
  }

  async searchDomains(query: string, tlds?: string[], provider?: string): Promise<DomainSearchResult[]> {
    requireUser();
    requireProviderConfigured(provider);
    const base = query.trim().toLowerCase().replace(/\s+/g, '-');
    const list = tlds && tlds.length > 0 ? tlds : ['com', 'io', 'dev', 'net', 'org'];

    return Promise.resolve(
      list.map((tld) => {
        const lower = tld.toLowerCase();
        const domain = `${base}.${lower}`;
        return {
          domain,
          tld: lower,
          available: domainAvailable(domain),
          premium: ['io', 'co', 'app', 'cloud'].includes(lower),
          price: { amount: DOMAIN_PRICES[lower] ?? 14.99, currency: 'USD', years: 1 },
          ...(provider ? { provider } : {}),
        };
      }),
    );
  }

  async checkDomain(domain: string, provider?: string): Promise<DomainCheckResult> {
    requireUser();
    requireProviderConfigured(provider);
    const name = domain.trim().toLowerCase();
    return Promise.resolve({
      domain: name,
      available: domainAvailable(name),
      managed: domains.some((d) => d.name === name),
      ...(provider ? { provider } : {}),
    });
  }

  async registerDomain(domain: string, years = 1, provider = 'sandbox'): Promise<DomainDto> {
    requireUser();
    requireProviderConfigured(provider);
    const name = domain.trim().toLowerCase();

    if (domains.some((d) => d.name === name)) {
      throw new ApiError(422, 'Already managed', `The domain [${name}] is already managed.`);
    }
    if (!domainAvailable(name)) {
      throw new ApiError(422, 'Unavailable', `The domain [${name}] is not available.`);
    }

    const registered = nowIso();
    const expires = new Date(Date.now() + years * 365 * 86400000).toISOString();
    const zoneId = uid('zone');

    const domainDto: DomainDto = {
      id: uid('dom'),
      name,
      status: 'active',
      provider,
      registered_at: registered,
      expires_at: expires,
      auto_renew: true,
      privacy_protection: true,
      transfer_lock: true,
      nameservers: ['ns1.omnex.io', 'ns2.omnex.io'],
      created_at: registered,
      zone_id: zoneId,
    };

    domains.push(domainDto);
    dnsRecords.push(
      { id: uid('rr'), zone_id: zoneId, type: 'NS', name: '@', content: 'ns1.omnex.io', ttl: 3600, priority: null, proxied: false, created_at: registered },
      { id: uid('rr'), zone_id: zoneId, type: 'NS', name: '@', content: 'ns2.omnex.io', ttl: 3600, priority: null, proxied: false, created_at: registered },
    );

    pushNotification('domain', 'success', 'Domain registered', name, `/domains/${domainDto.id}`);
    addActivityEvent({ type: 'domain', severity: 'success', title: 'Domain registered', description: name, actor: 'Demo Owner' });

    return Promise.resolve(domainDto);
  }

  async getDomain(id: string): Promise<DomainDto> {
    requireUser();
    return Promise.resolve(requireDomain(id));
  }

  async renewDomain(id: string, years = 1): Promise<DomainDto> {
    requireUser();
    const domain = requireDomain(id);
    const base = new Date(domain.expires_at ?? Date.now());
    domain.expires_at = new Date(base.getTime() + years * 365 * 86400000).toISOString();
    return Promise.resolve(domain);
  }

  async updateDomain(id: string, input: DomainUpdateInput): Promise<DomainDto> {
    requireUser();
    const domain = requireDomain(id);
    if (input.auto_renew !== undefined) domain.auto_renew = input.auto_renew;
    if (input.privacy_protection !== undefined) domain.privacy_protection = input.privacy_protection;
    if (input.transfer_lock !== undefined) domain.transfer_lock = input.transfer_lock;
    if (input.nameservers) domain.nameservers = input.nameservers;
    if (input.contacts !== undefined) domain.contacts = input.contacts;
    return Promise.resolve(domain);
  }

  async listDnsRecords(domainId: string): Promise<DnsRecordDto[]> {
    requireUser();
    const domain = requireDomain(domainId);
    return Promise.resolve(zoneRecords(domain.zone_id ?? '').slice());
  }

  async createDnsRecord(domainId: string, input: DnsRecordInput): Promise<DnsRecordDto> {
    requireUser();
    const domain = requireDomain(domainId);
    const zoneId = domain.zone_id ?? '';
    const record: DnsRecordDto = {
      id: uid('rr'),
      zone_id: zoneId,
      type: input.type.toUpperCase(),
      name: input.name ?? '@',
      content: input.content,
      ttl: input.ttl ?? 3600,
      priority: input.priority ?? null,
      proxied: input.proxied ?? false,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    dnsRecords.push(record);
    dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: record.id, action: 'created', before: null, after: recordSnapshot(record), created_at: nowIso() });
    return Promise.resolve(record);
  }

  async updateDnsRecord(domainId: string, recordId: string, input: DnsRecordInput): Promise<DnsRecordDto> {
    requireUser();
    const domain = requireDomain(domainId);
    const zoneId = domain.zone_id ?? '';
    const record = dnsRecords.find((r) => r.id === recordId && r.zone_id === zoneId);
    if (!record) throw new ApiError(404, 'Not found');

    const before = recordSnapshot(record);
    record.type = input.type.toUpperCase();
    record.name = input.name ?? '@';
    record.content = input.content;
    record.ttl = input.ttl ?? 3600;
    record.priority = input.priority ?? null;
    record.proxied = input.proxied ?? false;
    record.updated_at = nowIso();

    dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: record.id, action: 'updated', before, after: recordSnapshot(record), created_at: nowIso() });
    return Promise.resolve(record);
  }

  async deleteDnsRecord(domainId: string, recordId: string): Promise<void> {
    requireUser();
    const domain = requireDomain(domainId);
    const zoneId = domain.zone_id ?? '';
    const index = dnsRecords.findIndex((r) => r.id === recordId && r.zone_id === zoneId);
    if (index === -1) throw new ApiError(404, 'Not found');

    const before = recordSnapshot(dnsRecords[index]);
    dnsRecords.splice(index, 1);
    dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: null, action: 'deleted', before, after: null, created_at: nowIso() });
    return Promise.resolve();
  }

  async listDnsHistory(domainId: string): Promise<DnsHistoryDto[]> {
    requireUser();
    const domain = requireDomain(domainId);
    return Promise.resolve(dnsHistory.filter((h) => h.zone_id === (domain.zone_id ?? '')));
  }

  async rollbackDns(domainId: string, historyId: string): Promise<DnsRecordDto[]> {
    requireUser();
    const domain = requireDomain(domainId);
    const zoneId = domain.zone_id ?? '';
    const entry = dnsHistory.find((h) => h.id === historyId && h.zone_id === zoneId);
    if (!entry) throw new ApiError(404, 'Not found');

    if (entry.action === 'created' && entry.record_id) {
      const index = dnsRecords.findIndex((r) => r.id === entry.record_id && r.zone_id === zoneId);
      if (index !== -1) {
        const before = recordSnapshot(dnsRecords[index]);
        dnsRecords.splice(index, 1);
        dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: null, action: 'deleted', before, after: null, created_at: nowIso() });
      }
    } else if (entry.action === 'updated' && entry.record_id && entry.before) {
      const record = dnsRecords.find((r) => r.id === entry.record_id && r.zone_id === zoneId);
      if (record) {
        const after = recordSnapshot(record);
        const before = entry.before as DnsRecordInput;
        record.type = before.type;
        record.name = before.name ?? '@';
        record.content = before.content;
        record.ttl = before.ttl ?? 3600;
        record.priority = before.priority ?? null;
        record.proxied = before.proxied ?? false;
        record.updated_at = nowIso();
        dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: record.id, action: 'updated', before: after, after: recordSnapshot(record), created_at: nowIso() });
      }
    } else if (entry.action === 'deleted' && entry.before) {
      const before = entry.before as DnsRecordInput;
      const record: DnsRecordDto = {
        id: uid('rr'),
        zone_id: zoneId,
        type: before.type,
        name: before.name ?? '@',
        content: before.content,
        ttl: before.ttl ?? 3600,
        priority: before.priority ?? null,
        proxied: before.proxied ?? false,
        created_at: nowIso(),
      };
      dnsRecords.push(record);
      dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: record.id, action: 'created', before: null, after: recordSnapshot(record), created_at: nowIso() });
    }

    return Promise.resolve(zoneRecords(zoneId));
  }

  async exportDns(domainId: string): Promise<string> {
    requireUser();
    return Promise.resolve(buildZoneFile(requireDomain(domainId)));
  }

  async importDns(domainId: string, zoneFile: string): Promise<DnsRecordDto[]> {
    requireUser();
    const domain = requireDomain(domainId);
    const zoneId = domain.zone_id ?? '';
    const incoming = parseZoneFile(zoneFile);

    const removed = zoneRecords(zoneId).length;
    const kept = dnsRecords.filter((r) => r.zone_id !== zoneId);
    dnsRecords.length = 0;
    dnsRecords.push(...kept);

    const created = incoming.map((input) => {
      const record: DnsRecordDto = {
        id: uid('rr'),
        zone_id: zoneId,
        type: input.type.toUpperCase(),
        name: input.name ?? '@',
        content: input.content,
        ttl: input.ttl ?? 3600,
        priority: input.priority ?? null,
        proxied: input.proxied ?? false,
        created_at: nowIso(),
      };
      dnsRecords.push(record);
      return record;
    });

    dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: null, action: 'imported', before: { count: removed }, after: { count: created.length }, created_at: nowIso() });
    return Promise.resolve(created);
  }

  async applyDnsTemplate(domainId: string, template: string): Promise<DnsRecordDto[]> {
    requireUser();
    const domain = requireDomain(domainId);
    const zoneId = domain.zone_id ?? '';
    const inputs = DNS_TEMPLATES[template];
    if (!inputs) throw new ApiError(422, 'Unknown template', `Unknown DNS template [${template}].`);

    const created = inputs.map((input) => {
      const record: DnsRecordDto = {
        id: uid('rr'),
        zone_id: zoneId,
        type: input.type.toUpperCase(),
        name: input.name ?? '@',
        content: input.content,
        ttl: input.ttl ?? 3600,
        priority: input.priority ?? null,
        proxied: input.proxied ?? false,
        created_at: nowIso(),
      };
      dnsRecords.push(record);
      dnsHistory.unshift({ id: uid('hist'), zone_id: zoneId, record_id: record.id, action: 'created', before: null, after: recordSnapshot(record), created_at: nowIso() });
      return record;
    });

    return Promise.resolve(created);
  }

  async getDnssec(domainId: string): Promise<DnssecStatus> {
    requireUser();
    const domain = requireDomain(domainId);
    return Promise.resolve({ ...dnssecState(domain.zone_id ?? '') });
  }

  async enableDnssec(domainId: string): Promise<DnssecStatus> {
    requireUser();
    const domain = requireDomain(domainId);
    const state = dnssecState(domain.zone_id ?? '');
    if (state.enabled) {
      throw new ApiError(422, 'Validation failed', undefined, { dnssec: ['DNSSEC is already enabled on this zone.'] });
    }
    state.enabled = true;
    state.status = 'active';
    state.ds_records = [computeDsRecord(domain.name)];
    return Promise.resolve({ ...state });
  }

  async disableDnssec(domainId: string): Promise<DnssecStatus> {
    requireUser();
    const domain = requireDomain(domainId);
    const state = dnssecState(domain.zone_id ?? '');
    if (!state.enabled) {
      throw new ApiError(422, 'Validation failed', undefined, { dnssec: ['DNSSEC is not enabled on this zone.'] });
    }
    state.enabled = false;
    state.status = 'unsigned';
    state.ds_records = [];
    return Promise.resolve({ ...state });
  }

  async getDnsPropagation(domainId: string): Promise<PropagationStatusDto> {
    requireUser();
    const domain = requireDomain(domainId);
    return Promise.resolve(buildPropagationStatus(domain, propagationByZone.get(domain.zone_id ?? '') ?? []));
  }

  async checkDnsPropagation(domainId: string): Promise<PropagationStatusDto> {
    requireUser();
    const domain = requireDomain(domainId);
    const checks = computePropagationChecks(domain);
    propagationByZone.set(domain.zone_id ?? '', checks);
    return Promise.resolve(buildPropagationStatus(domain, checks));
  }

  async listStorageProviders(): Promise<StorageProviderDto[]> {
    requireUser();
    return Promise.resolve([
      { name: 'sandbox', label: 'Sandbox', configured: true },
      { name: 's3', label: 'S3', configured: false },
    ]);
  }

  async listDrive(folderId?: string): Promise<DriveListing> {
    requireUser();
    const folder = folderId ? driveFolders.find((f) => f.id === folderId) ?? null : null;

    return Promise.resolve({
      folder,
      folders: driveFolders
        .filter((f) => (f.parent_id ?? null) === (folderId ?? null))
        .sort((a, b) => a.name.localeCompare(b.name)),
      files: driveFiles
        .filter((f) => (f.folder_id ?? null) === (folderId ?? null) && !f.trashed_at)
        .sort((a, b) => a.name.localeCompare(b.name)),
      quota: { used: driveQuotaUsed(), limit: DRIVE_QUOTA_LIMIT },
    });
  }

  async listDriveTrash(): Promise<DriveFileDto[]> {
    requireUser();
    return Promise.resolve(driveFiles.filter((f) => f.trashed_at));
  }

  async createFolder(parentId: string | null, name: string): Promise<DriveFolderDto> {
    requireUser();
    if (parentId && !driveFolders.some((f) => f.id === parentId)) {
      throw new ApiError(404, 'Not found', 'Folder not found.');
    }

    const folder: DriveFolderDto = {
      id: uid('drv-dir'),
      parent_id: parentId,
      name,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    driveFolders.push(folder);
    return Promise.resolve(folder);
  }

  async renameFolder(folderId: string, name: string): Promise<DriveFolderDto> {
    requireUser();
    const folder = driveFolders.find((f) => f.id === folderId);
    if (!folder) throw new ApiError(404, 'Not found', 'Folder not found.');
    folder.name = name;
    folder.updated_at = nowIso();
    return Promise.resolve({ ...folder });
  }

  async deleteFolder(folderId: string): Promise<void> {
    requireUser();
    const folder = driveFolders.find((f) => f.id === folderId);
    if (!folder) throw new ApiError(404, 'Not found', 'Folder not found.');

    const hasChildren = driveFolders.some((f) => f.parent_id === folderId);
    const hasFiles = driveFiles.some((f) => f.folder_id === folderId && !f.trashed_at);
    if (hasChildren || hasFiles) {
      throw new ApiError(422, 'Validation failed', undefined, { folder: ['Only empty folders can be deleted.'] });
    }

    driveFolders.splice(driveFolders.indexOf(folder), 1);
    return Promise.resolve();
  }

  async uploadFile(folderId: string | null, name: string, contents: string, mimeType = 'text/plain'): Promise<DriveFileDto> {
    requireUser();
    if (folderId && !driveFolders.some((f) => f.id === folderId)) {
      throw new ApiError(404, 'Not found', 'Folder not found.');
    }

    const fileId = uid('drv-file');
    const file: DriveFileDto = {
      id: fileId,
      folder_id: folderId,
      name,
      mime_type: mimeType,
      size: contents.length,
      checksum: null,
      version: 1,
      status: 'active',
      trashed_at: null,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    driveFiles.push(file);

    driveVersions.push({
      id: uid('drv-ver'),
      file_id: fileId,
      version: 1,
      size: contents.length,
      checksum: null,
      created_at: nowIso(),
      content: contents,
    });

    return Promise.resolve({ ...file });
  }

  async downloadFile(fileId: string): Promise<DriveDownloadDto> {
    requireUser();
    const file = driveFileById(fileId);
    return Promise.resolve({
      url: `https://storage.sandbox.omnex.test/${encodeURIComponent(fileId)}?dl=${encodeURIComponent(file.name)}`,
      name: file.name,
      mime_type: file.mime_type,
      size: file.size,
    });
  }

  async updateFile(fileId: string, input: DriveFileUpdateInput): Promise<DriveFileDto> {
    requireUser();
    const file = driveFileById(fileId);

    if (input.name !== undefined) file.name = input.name;

    if (input.contents !== undefined) {
      const next = file.version + 1;
      driveVersions.push({
        id: uid('drv-ver'),
        file_id: fileId,
        version: next,
        size: input.contents.length,
        checksum: null,
        created_at: nowIso(),
        content: input.contents,
      });
      file.version = next;
      file.size = input.contents.length;
      if (input.mime_type !== undefined) file.mime_type = input.mime_type;
    } else if (input.mime_type !== undefined) {
      file.mime_type = input.mime_type;
    }

    file.updated_at = nowIso();
    return Promise.resolve({ ...file });
  }

  async trashFile(fileId: string): Promise<DriveFileDto> {
    requireUser();
    const file = driveFileById(fileId);
    if (file.trashed_at) throw new ApiError(422, 'Validation failed', undefined, { file: ['The file is already in the trash.'] });
    file.status = 'trashed';
    file.trashed_at = nowIso();
    return Promise.resolve({ ...file });
  }

  async restoreFile(fileId: string): Promise<DriveFileDto> {
    requireUser();
    const file = driveFileById(fileId);
    if (!file.trashed_at) throw new ApiError(422, 'Validation failed', undefined, { file: ['The file is not in the trash.'] });
    file.status = 'active';
    file.trashed_at = null;
    return Promise.resolve({ ...file });
  }

  async deleteFile(fileId: string): Promise<void> {
    requireUser();
    const file = driveFileById(fileId);
    const versions = driveVersionsOf(fileId);
    for (const version of versions) {
      driveVersions.splice(driveVersions.indexOf(version), 1);
    }
    driveFiles.splice(driveFiles.indexOf(file), 1);
    return Promise.resolve();
  }

  async listFileVersions(fileId: string): Promise<DriveVersionDto[]> {
    requireUser();
    driveFileById(fileId);
    return Promise.resolve(
      driveVersionsOf(fileId).map(({ content: _content, ...version }) => version),
    );
  }

  async restoreFileVersion(fileId: string, versionId: string): Promise<DriveFileDto> {
    requireUser();
    const file = driveFileById(fileId);
    const source = driveVersions.find((v) => v.id === versionId && v.file_id === fileId);
    if (!source) throw new ApiError(404, 'Not found', 'Version not found.');

    const next = file.version + 1;
    driveVersions.push({
      id: uid('drv-ver'),
      file_id: fileId,
      version: next,
      size: source.size,
      checksum: source.checksum,
      created_at: nowIso(),
      content: source.content,
    });
    file.version = next;
    file.size = source.size;
    file.updated_at = nowIso();
    return Promise.resolve({ ...file });
  }

  async getSecurityScore(): Promise<SecurityScoreDto> {
    requireUser();
    return Promise.resolve(computeSecurityReport());
  }

  async scanSecurity(): Promise<SecurityScoreDto> {
    requireUser();
    return Promise.resolve(computeSecurityReport());
  }

  async dismissSecurityFinding(id: string): Promise<SecurityFindingDto> {
    requireUser();
    const finding = computeSecurityFindings().find((f) => f.id === id);
    if (!finding) throw new ApiError(404, 'Not found', 'Finding not found.');
    dismissedSecurityFindingIds.add(id);
    return Promise.resolve({ ...finding, status: 'dismissed', dismissed_at: nowIso() });
  }

  async reopenSecurityFinding(id: string): Promise<SecurityFindingDto> {
    requireUser();
    const finding = computeSecurityFindings().find((f) => f.id === id);
    if (!finding) throw new ApiError(404, 'Not found', 'Finding not found.');
    dismissedSecurityFindingIds.delete(id);
    return Promise.resolve({ ...finding, status: 'open', dismissed_at: null });
  }

  async listSiteProviders(): Promise<SiteProviderDto[]> {
    requireUser();
    return Promise.resolve(SITE_PROVIDERS.map((p) => ({ ...p })));
  }

  async listSites(): Promise<SiteDto[]> {
    requireUser();
    return Promise.resolve(
      sites
        .map((site) => ({
          ...site,
          deployments_count: siteDeploymentsOf(site.id).length,
        }))
        .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')),
    );
  }

  async getSite(id: string): Promise<SiteDto> {
    requireUser();
    const site = siteById(id);
    return Promise.resolve({ ...site, deployments_count: siteDeploymentsOf(id).length });
  }

  async createSite(input: SiteCreateInput): Promise<SiteDto> {
    requireUser();
    if (!input.name?.trim()) throw new ApiError(422, 'Validation failed', undefined, { name: ['The name is required.'] });
    if (!input.git_url?.trim()) throw new ApiError(422, 'Validation failed', undefined, { git_url: ['The git URL is required.'] });

    const provider = input.provider ?? 'sandbox';
    if (provider === 'custom') throw new ApiError(422, 'Validation failed', undefined, { provider: ['The Custom provider is not configured.'] });

    const site: SiteDto = {
      id: uid('site'),
      name: input.name.trim(),
      framework: input.framework,
      git_url: input.git_url.trim(),
      git_branch: input.git_branch ?? 'main',
      provider,
      status: 'provisioning',
      url: sandboxSiteUrl(input.name),
      current_deployment_id: null,
      environment_variable_keys: Object.keys(input.environment_variables ?? {}),
      deployments_count: 0,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    sites.push(site);
    return Promise.resolve({ ...site });
  }

  async updateSite(id: string, input: SiteUpdateInput): Promise<SiteDto> {
    requireUser();
    const site = siteById(id);
    if (input.name !== undefined) site.name = input.name.trim();
    if (input.framework !== undefined) site.framework = input.framework;
    if (input.git_url !== undefined) site.git_url = input.git_url.trim();
    if (input.git_branch !== undefined) site.git_branch = input.git_branch;
    if (input.environment_variables !== undefined) {
      site.environment_variable_keys = Object.keys(input.environment_variables);
    }
    site.updated_at = nowIso();
    return Promise.resolve({ ...site, deployments_count: siteDeploymentsOf(id).length });
  }

  async deleteSite(id: string): Promise<void> {
    requireUser();
    const site = siteById(id);
    for (const d of siteDeploymentsOf(id)) {
      siteDeployments.splice(siteDeployments.indexOf(d), 1);
    }
    sites.splice(sites.indexOf(site), 1);
    return Promise.resolve();
  }

  async listSiteDeployments(siteId: string): Promise<SiteDeploymentDto[]> {
    requireUser();
    siteById(siteId);
    return Promise.resolve(siteDeploymentsOf(siteId).map((d) => ({ ...d })));
  }

  async getSiteDeployment(siteId: string, deploymentId: string): Promise<SiteDeploymentDto> {
    requireUser();
    const deployment = siteDeployments.find((d) => d.site_id === siteId && d.id === deploymentId);
    if (!deployment) throw new ApiError(404, 'Not found', 'Deployment not found.');
    return Promise.resolve({ ...deployment });
  }

  async deploySite(siteId: string): Promise<SiteDeploymentDto> {
    requireUser();
    const site = siteById(siteId);
    const number = siteDeploymentsOf(siteId).length + 1;
    const failed = site.git_branch === 'fail';

    const deployment: SiteDeploymentDto = {
      id: uid('site-dep'),
      site_id: siteId,
      number,
      commit_sha: failed ? null : sandboxCommitSha(site.git_url, site.git_branch),
      status: failed ? 'failed' : 'live',
      url: failed ? null : sandboxSiteUrl(site.name),
      logs: failed
        ? '[omnex-sites] ERROR: build script exited with code 1'
        : `[omnex-sites] deploy succeeded @ ${sandboxCommitSha(site.git_url, site.git_branch)}`,
      deployed_at: failed ? null : nowIso(),
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    siteDeployments.push(deployment);

    if (!failed) {
      site.status = 'ready';
      site.url = sandboxSiteUrl(site.name);
      site.current_deployment_id = deployment.id;
      pushNotification('deployment', 'success', 'Deployment completed', `${site.name} is live.`, '/sites');
      addActivityEvent({ type: 'deployment', severity: 'success', title: 'Deployment completed', description: `${site.name} is live`, actor: 'Demo Owner' });
    } else {
      if (!site.current_deployment_id) site.status = 'failed';
      pushNotification('deployment', 'danger', 'Deployment failed', `${site.name} build failed.`, '/sites');
      addActivityEvent({ type: 'deployment', severity: 'danger', title: 'Deployment failed', description: `${site.name} build failed`, actor: 'System' });
    }
    site.updated_at = nowIso();

    return Promise.resolve({ ...deployment });
  }

  async rollbackSite(siteId: string, deploymentId: string): Promise<SiteDeploymentDto> {
    requireUser();
    const site = siteById(siteId);
    const target = siteDeployments.find((d) => d.site_id === siteId && d.id === deploymentId);
    if (!target) throw new ApiError(404, 'Not found', 'Deployment not found.');
    if (target.status !== 'live') {
      throw new ApiError(422, 'Validation failed', undefined, { deployment: ['Only a live deployment can be rolled back to.'] });
    }
    if (site.current_deployment_id === target.id) {
      throw new ApiError(422, 'Validation failed', undefined, { deployment: ['This deployment is already serving traffic.'] });
    }

    const current = siteDeployments.find((d) => d.site_id === siteId && d.id === site.current_deployment_id);
    if (current) current.status = 'rolled_back';
    site.current_deployment_id = target.id;
    site.updated_at = nowIso();

    return Promise.resolve({ ...target });
  }

  async listCloudProviders(): Promise<CloudProviderDto[]> {
    requireUser();
    return Promise.resolve(cloudProviders());
  }

  async verifyCloudProviders(provider?: string): Promise<CloudProviderVerifyDto[]> {
    requireUser();
    const all = cloudProviders();
    const selected = provider ? all.filter((p) => p.name === provider) : all;
    return Promise.resolve(
      selected.map((p) => ({
        ...p,
        verified: p.configured
          ? { ok: true, detail: `${p.label} API reachable with the configured token.` }
          : { ok: false, detail: `${p.name.toUpperCase().replace(/^CUSTOM$/, 'CUSTOM_CLOUD_ENDPOINT')} is not set.` },
      })),
    );
  }

  async listSshKeys(): Promise<SshKeyDto[]> {
    requireUser();
    return Promise.resolve(
      [...sshKeys]
        .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''))
        .map((key) => ({ ...key, servers_count: servers.filter((s) => s.ssh_key_id === key.id).length })),
    );
  }

  async createSshKey(input: SshKeyCreateInput): Promise<SshKeyDto> {
    requireUser();
    if (!input.name?.trim()) throw new ApiError(422, 'Validation failed', undefined, { name: ['The name is required.'] });
    const body = input.public_key.trim();
    const parts = body.split(/\s+/);
    if (parts.length < 2 || !/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|ssh-dss)$/.test(parts[0])) {
      throw new ApiError(422, 'Validation failed', undefined, { public_key: ['A valid OpenSSH public key is required (type + base64 body).'] });
    }

    // Normalize to type + body (comment stripped) before hashing, so a key
    // pasted with or without its comment has the same fingerprint.
    const normalized = `${parts[0]} ${parts[1]}`;
    const fingerprint = mockFingerprint(normalized);
    if (sshKeys.some((k) => k.fingerprint === fingerprint)) {
      throw new ApiError(422, 'Validation failed', undefined, { public_key: ['This public key is already registered in the organization.'] });
    }

    const key: SshKeyDto = {
      id: uid('ssh-key'),
      name: input.name.trim(),
      fingerprint,
      public_key: normalized,
      has_private_key: false,
      servers_count: 0,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    sshKeys.push(key);
    return Promise.resolve({ ...key });
  }

  async generateSshKey(input: SshKeyGenerateInput): Promise<SshKeyGenerateResponse> {
    requireUser();
    if (!input.name?.trim()) throw new ApiError(422, 'Validation failed', undefined, { name: ['The name is required.'] });
    if (input.vault_password !== undefined && input.vault_password.length < 8) {
      throw new ApiError(422, 'Validation failed', undefined, { vault_password: ['The vault password must be at least 8 characters.'] });
    }

    const type = input.type === 'rsa' ? 'ssh-rsa' : 'ssh-ed25519';
    // Fake OpenSSH bodies — the mock only needs plausible shapes.
    const body = `AAAAC3NzaC1lZDI1NTE5AAAAI${mockFingerprint(input.name).replace('SHA256:', '').slice(0, 32)}`;
    const comment = `omnex-${input.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
    const normalized = `${type} ${body}`;
    const fingerprint = mockFingerprint(normalized);

    const sealed = input.vault_password !== undefined && input.vault_password !== '';
    const key: SshKeyDto = {
      id: uid('ssh-key'),
      name: input.name.trim(),
      fingerprint,
      public_key: normalized,
      has_private_key: sealed,
      vault_enabled_at: sealed ? nowIso() : null,
      servers_count: 0,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    sshKeys.push(key);

    // Private key is returned exactly once. With a vault password the mock
    // seals it (verifier only) so it can be recovered later via unlock.
    const privateKey = `-----BEGIN OPENSSH PRIVATE KEY-----\nmock-private-key-for-${key.name}-${body.slice(0, 16)}\n-----END OPENSSH PRIVATE KEY-----\n`;
    if (sealed) {
      sshVault.set(key.id, { privateKey, verifier: mockVaultVerifier(input.vault_password!) });
    }

    return Promise.resolve({ data: { ...key }, private_key: privateKey });
  }

  async unlockSshKey(id: string, vaultPassword: string): Promise<SshKeyUnlockResponse> {
    requireUser();
    const key = sshKeyById(id);
    const sealed = sshVault.get(id);
    if (!key.has_private_key || !sealed) {
      throw new ApiError(422, 'Validation failed', undefined, { vault_password: ['No private key is stored in the vault for this key.'] });
    }
    if (sealed.verifier !== mockVaultVerifier(vaultPassword)) {
      throw new ApiError(422, 'Validation failed', undefined, { vault_password: ['The vault password is incorrect.'] });
    }
    return Promise.resolve({ data: { ...key }, private_key: sealed.privateKey });
  }

  async installServerSshKey(serverId: string, sshKeyId: string): Promise<SshKeyInstallResponse> {
    requireUser();
    const server = serverById(serverId);
    const key = sshKeyById(sshKeyId);

    const failed = server.name.includes('fail');
    const now = nowIso();
    serverOperations.push({
      id: uid('server-op'),
      server_id: server.id,
      type: 'install_key',
      status: failed ? 'failed' : 'succeeded',
      started_at: now,
      completed_at: now,
      result: failed ? null : JSON.stringify({ status: 'installed' }),
      error: failed ? `Operation failed: the server name contains the deterministic failure trigger "fail".` : null,
      created_at: now,
      updated_at: now,
    });

    if (failed) {
      throw new ApiError(503, 'Provider error', `Operation failed: the server name contains the deterministic failure trigger "fail".`);
    }

    server.ssh_key = key.public_key;
    server.ssh_key_id = key.id;
    server.updated_at = now;

    auditLogs.unshift({ id: auditLogs.length + 1, action: 'server.ssh_key_installed', resource_type: 'server', resource_id: server.id, result: 'success', created_at: now });
    addActivityEvent({
      type: 'cloud',
      severity: 'success',
      title: 'SSH key installed',
      description: `${key.name} → ${server.name}`,
      actor: null,
    });

    return Promise.resolve({ status: 'installed', detail: 'Key installed on the server.' });
  }

  async updateSshKey(id: string, input: SshKeyUpdateInput): Promise<SshKeyDto> {
    requireUser();
    const key = sshKeyById(id);
    if (!input.name?.trim()) throw new ApiError(422, 'Validation failed', undefined, { name: ['The name is required.'] });
    key.name = input.name.trim();
    key.updated_at = nowIso();
    return Promise.resolve({ ...key });
  }

  async deleteSshKey(id: string): Promise<void> {
    requireUser();
    const key = sshKeyById(id);
    const inUseBy = servers.filter((s) => s.ssh_key_id === id).length;
    if (inUseBy > 0) {
      throw new ApiError(422, 'Validation failed', undefined, {
        ssh_key: [`This key is used by ${inUseBy} server${inUseBy > 1 ? 's' : ''}. Remove it from those servers before deleting it.`],
      });
    }
    sshKeys.splice(sshKeys.indexOf(key), 1);
    return Promise.resolve();
  }

  async listServers(): Promise<ServerDto[]> {
    requireUser();
    return Promise.resolve(
      servers
        .map((server) => ({ ...server, operations_count: serverOperationsOf(server.id).length }))
        .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')),
    );
  }

  async getServer(id: string): Promise<ServerDto> {
    requireUser();
    const server = serverById(id);
    return Promise.resolve({ ...server, operations_count: serverOperationsOf(id).length });
  }

  async createServer(input: ServerCreateInput): Promise<ServerDto> {
    requireUser();
    if (!input.name?.trim()) throw new ApiError(422, 'Validation failed', undefined, { name: ['The name is required.'] });
    if (!/^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(input.name)) {
      throw new ApiError(422, 'Validation failed', undefined, { name: ['The name must contain only lowercase letters, numbers and dashes.'] });
    }

    const provider = input.provider ?? 'sandbox';
    if (provider !== 'sandbox') {
      throw new ApiError(422, 'Validation failed', undefined, { provider: ['This cloud provider is not configured.'] });
    }
    if (input.name === 'fail') {
      throw new ApiError(503, 'Provider error', 'Provisioning failed: name "fail" is a deterministic failure trigger.');
    }

    const name = input.name.trim();
    const region = input.region ?? 'fsn1';
    const plan = input.plan ?? 'cpx11';
    const image = input.image ?? 'ubuntu-24.04';
    const now = nowIso();

    // A saved key wins over a raw pasted key (mirrors the backend).
    const savedKey = input.ssh_key_id ? sshKeyById(input.ssh_key_id) : null;
    const sshKey = savedKey ? savedKey.public_key : (input.ssh_key?.trim() || null);

    const server: ServerDto = {
      id: uid('server'),
      name,
      region,
      plan,
      image,
      provider,
      status: 'running',
      ipv4: sandboxServerIpv4(name, region, plan, image),
      ipv6: null,
      ssh_key: sshKey,
      ssh_key_id: savedKey?.id ?? null,
      tags: input.tags ?? [],
      snapshot_frequency: input.snapshot_frequency ?? 'disabled',
      snapshot_retention_days: input.snapshot_retention_days ?? 7,
      last_snapshot_at: null,
      operations_count: 1,
      created_at: now,
      updated_at: now,
    };
    servers.push(server);

    serverOperations.push({
      id: uid('server-op'),
      server_id: server.id,
      type: 'provision',
      status: 'succeeded',
      started_at: now,
      completed_at: now,
      result: server.ipv4,
      error: null,
      created_at: now,
      updated_at: now,
    });

    pushNotification('cloud', 'success', 'Server provisioned', `${server.name} is running at ${server.ipv4}.`, '/cloud');
    addActivityEvent({ type: 'provision', severity: 'success', title: 'Server provisioned', description: `${server.name} is running`, actor: 'Demo Owner' });

    return Promise.resolve({ ...server });
  }

  async updateServer(id: string, input: ServerUpdateInput): Promise<ServerDto> {
    requireUser();
    const server = serverById(id);
    if (input.name !== undefined) {
      if (!/^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(input.name)) {
        throw new ApiError(422, 'Validation failed', undefined, { name: ['The name must contain only lowercase letters, numbers and dashes.'] });
      }
      server.name = input.name.trim();
    }
    if (input.ssh_key !== undefined) server.ssh_key = input.ssh_key?.trim() ? input.ssh_key.trim() : null;
    if (input.tags !== undefined) server.tags = [...input.tags];
    if (input.snapshot_frequency !== undefined) server.snapshot_frequency = input.snapshot_frequency;
    if (input.snapshot_retention_days !== undefined) server.snapshot_retention_days = input.snapshot_retention_days;
    server.updated_at = nowIso();
    return Promise.resolve({ ...server, operations_count: serverOperationsOf(id).length });
  }

  async deleteServer(id: string): Promise<void> {
    requireUser();
    const server = serverById(id);
    for (const op of serverOperationsOf(id)) {
      serverOperations.splice(serverOperations.indexOf(op), 1);
    }
    for (const snap of serverSnapshots.filter((s) => s.server_id === id)) {
      serverSnapshots.splice(serverSnapshots.indexOf(snap), 1);
    }
    servers.splice(servers.indexOf(server), 1);
    return Promise.resolve();
  }

  async listServerOperations(serverId: string): Promise<ServerOperationDto[]> {
    requireUser();
    serverById(serverId);
    return Promise.resolve(serverOperationsOf(serverId).map((o) => ({ ...o })));
  }

  async listServerSnapshots(serverId: string): Promise<ServerSnapshotDto[]> {
    requireUser();
    serverById(serverId);
    return Promise.resolve(
      serverSnapshots
        .filter((s) => s.server_id === serverId)
        .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')),
    );
  }

  async createServerSnapshot(serverId: string, label?: string): Promise<ServerSnapshotDto> {
    requireUser();
    const server = serverById(serverId);
    if (server.name.includes('fail')) {
      const now = nowIso();
      serverOperations.push({
        id: uid('server-op'),
        server_id: server.id,
        type: 'snapshot',
        status: 'failed',
        started_at: now,
        completed_at: now,
        result: null,
        error: 'Operation failed: the server name contains the deterministic failure trigger "fail".',
        created_at: now,
        updated_at: now,
      });
      throw new ApiError(503, 'Provider error', 'Snapshot failed: the server name contains the deterministic failure trigger "fail".');
    }

    const now = nowIso();
    const snapshot: ServerSnapshotDto = {
      id: uid('snap'),
      server_id: server.id,
      provider_snapshot_id: `sbox-snap-${mockFingerprint(server.name + now).slice(7, 15).toLowerCase()}`,
      label: label?.trim() || `snapshot-${now.slice(0, 19).replace(/[^0-9]/g, '').slice(0, 14)}`,
      status: 'available',
      size_bytes: null,
      created_at: now,
    };
    serverSnapshots.push(snapshot);
    server.last_snapshot_at = now;
    server.updated_at = now;

    serverOperations.push({
      id: uid('server-op'),
      server_id: server.id,
      type: 'snapshot',
      status: 'succeeded',
      started_at: now,
      completed_at: now,
      result: snapshot.provider_snapshot_id,
      error: null,
      created_at: now,
      updated_at: now,
    });

    pushNotification('cloud', 'success', 'Snapshot created', `${server.name}: ${snapshot.label}`, '/cloud');
    addActivityEvent({ type: 'backup', severity: 'success', title: 'Snapshot created', description: `${server.name}: ${snapshot.label}`, actor: 'Demo Owner' });

    return Promise.resolve({ ...snapshot });
  }

  async deleteServerSnapshot(serverId: string, snapshotId: string): Promise<void> {
    requireUser();
    const snapshot = serverSnapshots.find((s) => s.id === snapshotId && s.server_id === serverId);
    if (!snapshot) throw new ApiError(404, 'Not found', 'Snapshot not found.');
    serverSnapshots.splice(serverSnapshots.indexOf(snapshot), 1);
    return Promise.resolve();
  }

  async listServerMetricsHistory(serverId: string, limit = 60): Promise<ServerMetricsDto[]> {
    requireUser();
    serverById(serverId);
    // Deterministic backfill of the last `limit` buckets, oldest first,
    // mirroring the backend's persisted history + synthetic generator.
    const count = Math.max(1, Math.min(240, Math.floor(limit)));
    const samples: ServerMetricsDto[] = [];
    for (let offset = -(count - 1); offset <= 0; offset += 1) {
      samples.push(mockMetricsSample(serverId, offset));
    }
    return Promise.resolve(samples);
  }

  subscribeServerMetrics(serverId: string, handler: (metrics: ServerMetricsDto) => void): () => void {
    const server = serverById(serverId);
    const emit = (metrics: ServerMetricsDto) => {
      handler(metrics);
      checkMetricsThresholds(server, metrics);
    };
    emit(mockMetricsSample(serverId));
    const timer = setInterval(() => emit(mockMetricsSample(serverId)), 5000);
    return () => clearInterval(timer);
  }

  async startServer(serverId: string): Promise<ServerOperationDto> {
    requireUser();
    return runServerOperation(serverById(serverId), 'start', 'running');
  }

  async stopServer(serverId: string): Promise<ServerOperationDto> {
    requireUser();
    return runServerOperation(serverById(serverId), 'stop', 'stopped');
  }

  async rebootServer(serverId: string): Promise<ServerOperationDto> {
    requireUser();
    return runServerOperation(serverById(serverId), 'reboot', 'running');
  }

  async rebuildServer(serverId: string, image: string): Promise<ServerOperationDto> {
    requireUser();
    const server = serverById(serverId);
    const now = nowIso();
    const failed = server.name.includes('fail');
    const operation: ServerOperationDto = {
      id: uid('server-op'),
      server_id: server.id,
      type: 'rebuild',
      status: failed ? 'failed' : 'succeeded',
      started_at: now,
      completed_at: now,
      result: failed ? null : server.ipv4,
      error: failed ? 'Rebuild failed: the server name contains the deterministic failure trigger "fail".' : null,
      created_at: now,
      updated_at: now,
    };
    serverOperations.push(operation);
    if (!failed) {
      server.image = image || 'ubuntu-24.04';
      server.status = 'running';
    }
    server.updated_at = now;
    return Promise.resolve({ ...operation });
  }

  async listBillingProviders(): Promise<PaymentProviderDto[]> {
    requireUser();
    return Promise.resolve(BILLING_PROVIDERS.map((p) => ({ ...p })));
  }

  async listBillingPlans(): Promise<BillingPlanDto[]> {
    requireUser();
    return Promise.resolve(BILLING_PLANS.map((p) => ({ ...p, features: [...p.features] })));
  }

  async getSubscription(): Promise<SubscriptionDto | null> {
    requireUser();
    const sub = subscriptions
      .filter((s) => s.status !== 'canceled')
      .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''))[0];
    return Promise.resolve(sub ? { ...sub } : null);
  }

  async listInvoices(): Promise<InvoiceDto[]> {
    requireUser();
    return Promise.resolve(
      [...invoices].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')),
    );
  }

  async subscribeToPlan(plan: string, provider = 'sandbox', couponCode?: string): Promise<BillingSubscribeResponse> {
    requireUser();
    if (provider === 'stripe') {
      throw new ApiError(422, 'Validation failed', undefined, { provider: ['The Stripe provider is not configured.'] });
    }
    const planDto = BILLING_PLANS.find((p) => p.slug === plan);
    if (!planDto) throw new ApiError(422, 'Validation failed', undefined, { plan: ['The selected plan is not available.'] });

    const existing = subscriptions.find((s) => s.status !== 'canceled' && s.plan?.slug === plan);
    if (existing) {
      throw new ApiError(422, 'Validation failed', undefined, { plan: ['This organization already has an active subscription to this plan.'] });
    }

    const coupon = couponCode && couponCode.trim() !== '' ? couponByCode(couponCode) : null;
    const discount = coupon ? couponDiscount(coupon, planDto.price_monthly) : 0;
    if (coupon && discount > 0) {
      const admin = mockCoupons.find((c) => c.code === coupon.code);
      if (admin) admin.times_redeemed += 1;
      couponRedemptions.unshift({
        id: uid('redem'),
        couponId: admin?.id ?? coupon.code,
        organization_id: activeOrganization()?.id ?? 'org-omnex-hq',
        organization_name: activeOrganization()?.name ?? 'OMNEX HQ',
        discount_amount: discount,
        currency: planDto.currency,
        created_at: nowIso(),
      });
    }
    const netAfterCoupon = Math.max(planDto.price_monthly - discount, 0);
    const creditApplied = Math.min(creditBalance(), netAfterCoupon);
    const amountDue = netAfterCoupon - creditApplied;

    if (creditApplied > 0) {
      creditEntries.unshift({
        id: uid('credit'),
        amount: -creditApplied,
        currency: planDto.currency,
        reason: `invoice:${invoices.length + 1}`,
        created_at: nowIso(),
      });
    }

    const subscription: SubscriptionDto = {
      id: uid('sub'),
      plan: { ...planDto, features: [...planDto.features] },
      coupon: coupon ? toAppliedCoupon(coupon) : null,
      provider,
      status: 'active',
      current_period_start: nowIso(),
      current_period_end: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString(),
      canceled_at: null,
      created_at: nowIso(),
      updated_at: nowIso(),
    };
    subscriptions.push(subscription);

    const invoice: InvoiceDto = {
      id: uid('inv'),
      number: `${new Date().getFullYear()}-${String(invoices.length + 1).padStart(4, '0')}`,
      amount: planDto.price_monthly,
      discount,
      credit_applied: creditApplied,
      amount_due: amountDue,
      currency: planDto.currency,
      status: 'paid',
      provider,
      paid_at: nowIso(),
      period_start: nowIso(),
      period_end: subscription.current_period_end,
      plan: { ...planDto, features: [...planDto.features] },
      created_at: nowIso(),
    };
    invoices.push(invoice);

    pushNotification('billing', 'success', 'Subscription activated', `Your ${planDto.name} subscription is active.`, '/billing');
    addActivityEvent({ type: 'billing', severity: 'success', title: 'Subscription activated', description: `${planDto.name} plan`, actor: 'Demo Owner' });

    return Promise.resolve({
      subscription: { ...subscription },
      checkout_url: `/billing/sandbox/checkout/${subscription.id}`,
    });
  }

  async validateCoupon(code: string): Promise<CouponDto> {
    requireUser();
    const coupon = couponByCode(code);
    return Promise.resolve({ ...coupon, discount: couponDiscount(coupon, 10000) });
  }

  async changePlan(plan: string): Promise<SubscriptionDto> {
    requireUser();
    const current = subscriptions
      .filter((s) => s.status !== 'canceled')
      .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? ''))[0];
    if (!current || current.status !== 'active') {
      throw new ApiError(422, 'Validation failed', undefined, { plan: ['Only an active subscription can change plan.'] });
    }
    if (current.plan?.slug === plan) {
      throw new ApiError(422, 'Validation failed', undefined, { plan: ['This organization is already subscribed to this plan.'] });
    }

    const planDto = BILLING_PLANS.find((p) => p.slug === plan);
    if (!planDto) throw new ApiError(422, 'Validation failed', undefined, { plan: ['The selected plan is not available.'] });

    // Proration: half of the current period is credited on change.
    const prorated = current.plan ? Math.round(current.plan.price_monthly / 2) : 0;
    if (prorated > 0) {
      creditEntries.unshift({ id: uid('credit'), amount: prorated, currency: 'usd', reason: 'proration', created_at: nowIso() });
    }

    current.plan = { ...planDto, features: [...planDto.features] };
    current.current_period_start = nowIso();
    current.current_period_end = new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString();
    current.updated_at = nowIso();

    const org = activeOrganization();
    if (org) org.plan_tier = plan;

    pushNotification('billing', 'info', 'Plan changed', `Your plan is now ${planDto.name}.`, '/billing');
    addActivityEvent({ type: 'billing', severity: 'info', title: 'Plan changed', description: `${planDto.name} plan`, actor: 'Demo Owner' });

    return Promise.resolve({ ...current });
  }

  async getCredits(): Promise<CreditSummaryDto> {
    requireUser();
    return Promise.resolve({
      balance: creditBalance(),
      entries: [...creditEntries].sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')),
    });
  }

  async addCredits(amount: number, reason: string): Promise<CreditEntryDto> {
    requireUser();
    const entry: CreditEntryDto = {
      id: uid('credit'),
      amount,
      currency: 'usd',
      reason,
      created_at: nowIso(),
    };
    creditEntries.unshift(entry);
    return Promise.resolve(entry);
  }

  async listCoupons(): Promise<CouponAdminDto[]> {
    requireUser();
    return Promise.resolve([...mockCoupons].sort((a, b) => a.code.localeCompare(b.code)));
  }

  async createCoupon(input: CouponCreateInput): Promise<CouponAdminDto> {
    requireUser();
    const code = input.code.trim().toUpperCase();
    if (mockCoupons.some((c) => c.code === code)) {
      throw new ApiError(422, 'Validation failed', undefined, { code: ['This coupon code already exists.'] });
    }
    if (input.discount_type === 'percent' && (input.discount_value < 1 || input.discount_value > 100)) {
      throw new ApiError(422, 'Validation failed', undefined, { discount_value: ['A percent discount must be between 1 and 100.'] });
    }
    const coupon: CouponAdminDto = {
      id: uid('coupon'),
      code,
      name: input.name,
      description: input.description ?? null,
      discount_type: input.discount_type,
      discount_value: input.discount_value,
      currency: input.currency ?? 'usd',
      max_redemptions: input.max_redemptions ?? null,
      times_redeemed: 0,
      active: true,
      expires_at: input.expires_at ?? null,
      created_at: nowIso(),
    };
    mockCoupons.push(coupon);
    return Promise.resolve({ ...coupon });
  }

  async updateCoupon(id: string, input: CouponUpdateInput): Promise<CouponAdminDto> {
    requireUser();
    const coupon = mockCoupons.find((c) => c.id === id);
    if (!coupon) throw new ApiError(404, 'Not found', 'Coupon not found.');
    if (input.name !== undefined) coupon.name = input.name;
    if (input.description !== undefined) coupon.description = input.description;
    if (input.discount_type !== undefined) coupon.discount_type = input.discount_type;
    if (input.discount_value !== undefined) coupon.discount_value = input.discount_value;
    if (input.currency !== undefined) coupon.currency = input.currency;
    if (input.max_redemptions !== undefined) coupon.max_redemptions = input.max_redemptions;
    if (input.expires_at !== undefined) coupon.expires_at = input.expires_at;
    if (input.active !== undefined) coupon.active = input.active;
    return Promise.resolve({ ...coupon });
  }

  async listCouponRedemptions(id: string): Promise<CouponRedemptionDto[]> {
    requireUser();
    return Promise.resolve(
      couponRedemptions
        .filter((r) => r.couponId === id)
        .map(({ couponId: _couponId, ...redemption }) => ({ ...redemption })),
    );
  }

  async cancelSubscription(id: string): Promise<SubscriptionDto> {
    requireUser();
    const sub = subscriptions.find((s) => s.id === id);
    if (!sub) throw new ApiError(404, 'Not found', 'Subscription not found.');
    sub.status = 'canceled';
    sub.canceled_at = nowIso();
    sub.updated_at = nowIso();
    return Promise.resolve({ ...sub });
  }

  async submitContactLead(input: ContactLeadInput): Promise<ContactLeadDto> {
    // Public endpoint — no session required.
    if (input.website) {
      throw new ApiError(422, 'Validation failed', 'Honeypot triggered.');
    }
    if (!input.name?.trim()) throw new ApiError(422, 'Validation failed', undefined, { name: ['The name field is required.'] });
    if (!input.subject?.trim()) throw new ApiError(422, 'Validation failed', undefined, { subject: ['The subject field is required.'] });
    if (!input.message?.trim() || input.message.trim().length < 10) {
      throw new ApiError(422, 'Validation failed', undefined, { message: ['The message must be at least 10 characters.'] });
    }
    if (!input.email?.trim() || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(input.email.trim())) {
      throw new ApiError(422, 'Validation failed', undefined, { email: ['The email must be a valid email address.'] });
    }

    const lead: ContactLeadDto = {
      id: uid('lead'),
      name: input.name.trim(),
      email: input.email.trim(),
      company: input.company?.trim() || null,
      subject: input.subject.trim(),
      message: input.message.trim(),
      source: input.source || 'marketing-site',
      status: 'new',
      created_at: nowIso(),
    };
    contactLeads.unshift(lead);

    // Route the lead to the team: an internal notification for the owner.
    notifications.unshift({
      id: uid('notif'),
      type: 'lead',
      severity: 'info',
      title: `New contact lead — ${lead.name}`,
      body: lead.subject,
      route: '/marketing/contact',
      read_at: null,
      created_at: nowIso(),
    });

    return Promise.resolve({ ...lead });
  }
}
