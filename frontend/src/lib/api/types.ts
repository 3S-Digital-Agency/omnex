export interface UserDto {
  id: string;
  name: string;
  email: string;
  email_verified_at?: string | null;
  mfa_enabled: boolean;
  locale: string | null;
  status: string;
  last_login_at?: string | null;
  created_at?: string;
}

export interface RoleDto {
  id: string;
  name: string;
  key: string;
  description?: string | null;
  permissions?: string[];
}

export interface OrganizationDto {
  id: string;
  name: string;
  slug: string;
  plan_tier: string;
  status: string;
  created_at?: string;
}

export interface MembershipDto {
  id: string;
  status: string;
  joined_at?: string | null;
  created_at?: string;
  role?: RoleDto | null;
  user?: UserDto | null;
  organization?: OrganizationDto | null;
}

export interface InvitationDto {
  id: string;
  email: string;
  status: string;
  expires_at?: string | null;
  accepted_at?: string | null;
  created_at?: string;
  role?: RoleDto | null;
  organization?: OrganizationDto | null;
}

export interface AuditLogDto {
  id: number;
  action: string;
  resource_type?: string | null;
  resource_id?: string | null;
  before?: unknown;
  after?: unknown;
  ip_address?: string | null;
  result: string;
  created_at?: string;
  user?: UserDto | null;
}

export type NotificationSeverity = 'info' | 'success' | 'warning' | 'danger';

export interface NotificationDto {
  id: string;
  type: string;
  severity: NotificationSeverity;
  title: string;
  body?: string | null;
  data?: unknown;
  route?: string | null;
  read_at?: string | null;
  created_at?: string;
}

export interface NotificationListDto {
  data: NotificationDto[];
  unread: number;
}

export interface NotificationQuery {
  type?: string;
  severity?: NotificationSeverity;
  unread?: boolean;
  page?: number;
  perPage?: number;
}

export interface PaginatedNotificationList extends NotificationListDto {
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export interface AuthSession {
  token: string;
  user: UserDto;
  memberships: MembershipDto[];
  active_organization: OrganizationDto | null;
  permissions: string[];
  pending_invitations: InvitationDto[];
}

export interface MeResponse {
  user: UserDto;
  memberships: MembershipDto[];
  active_organization: OrganizationDto | null;
  permissions: string[];
  pending_invitations: InvitationDto[];
}

export type LoginResponse =
  | AuthSession
  | { mfa_required: true; mfa_token: string };

export interface RegisterInput {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

export interface LoginInput {
  email: string;
  password: string;
}

export interface UpdateProfileInput {
  locale: string;
}

export interface SocialProviderDto {
  name: string;
  label: string;
  configured: boolean;
}

export interface SocialAccountDto {
  id: string;
  provider: string;
  provider_email?: string | null;
  name?: string | null;
  avatar_url?: string | null;
  created_at?: string;
}

export interface SocialRedirectResponse {
  url: string | null;
}

export interface VerifyMfaInput {
  mfa_token: string;
  code?: string;
  recovery_code?: string;
}

export interface MfaSetupResponse {
  secret: string;
  otpauth_uri: string;
}

export interface MfaConfirmResponse {
  recovery_codes: string[];
}

export interface SwitchResponse {
  active_organization: OrganizationDto;
  role: { id: string; name: string; key: string } | null;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export type ActivitySeverity = 'info' | 'success' | 'warning' | 'danger';

export interface ActivityItem {
  id: number;
  type: string;
  severity: ActivitySeverity;
  title: string;
  description?: string | null;
  actor?: string | null;
  created_at?: string;
}

export interface ActivityFeed {
  data: ActivityItem[];
  latest_id: number;
}

export interface DomainDto {
  id: string;
  name: string;
  status: string;
  provider: string;
  registered_at?: string | null;
  expires_at?: string | null;
  auto_renew: boolean;
  privacy_protection: boolean;
  transfer_lock: boolean;
  nameservers?: string[] | null;
  contacts?: Record<string, unknown> | null;
  created_at?: string;
  zone_id?: string | null;
}

export interface DomainProviderDto {
  name: string;
  label: string;
  configured: boolean;
}

export interface DomainSearchResult {
  domain: string;
  tld: string;
  available: boolean;
  premium: boolean;
  price: { amount: number; currency: string; years: number };
  provider?: string;
}

export interface DomainCheckResult {
  domain: string;
  available: boolean;
  managed?: boolean;
  provider?: string;
}

export interface DomainUpdateInput {
  auto_renew?: boolean;
  privacy_protection?: boolean;
  transfer_lock?: boolean;
  nameservers?: string[];
  contacts?: Record<string, unknown>;
}

export type DnsRecordType = 'A' | 'AAAA' | 'CNAME' | 'MX' | 'TXT' | 'NS' | 'SRV' | 'CAA';

export const DNS_RECORD_TYPES: DnsRecordType[] = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];

export interface DnsRecordDto {
  id: string;
  zone_id: string;
  type: string;
  name: string;
  content: string;
  ttl: number;
  priority: number | null;
  proxied: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface DnsRecordInput {
  type: string;
  name?: string;
  content: string;
  ttl?: number;
  priority?: number | null;
  proxied?: boolean;
}

export interface DnsHistoryDto {
  id: string;
  zone_id: string;
  record_id?: string | null;
  action: string;
  before?: unknown;
  after?: unknown;
  created_at?: string;
  user?: UserDto | null;
}

export interface DnsZoneDto {
  id: string;
  provider: string;
  status: string;
}

export interface DnssecDsRecord {
  key_tag: number;
  algorithm: number;
  digest_type: number;
  digest: string;
}

export interface DnssecStatus {
  enabled: boolean;
  status: string;
  ds_records: DnssecDsRecord[];
}

export type PropagationStatus = 'synced' | 'pending' | 'outdated' | 'error';

export interface PropagationCheckDto {
  id: string;
  nameserver: string;
  record_type: string;
  record_name: string;
  status: PropagationStatus;
  expected?: string[] | null;
  observed?: string[] | null;
  checked_at?: string;
}

export interface PropagationSummary {
  synced: number;
  pending: number;
  outdated: number;
  error: number;
  total: number;
}

export interface PropagationStatusDto {
  domain: string;
  nameservers: string[];
  checked_at: string | null;
  data: PropagationCheckDto[];
  summary: PropagationSummary;
}

export interface StorageProviderDto {
  name: string;
  label: string;
  configured: boolean;
}

export interface DriveFolderDto {
  id: string;
  parent_id: string | null;
  name: string;
  created_at?: string;
  updated_at?: string;
}

export interface DriveFileDto {
  id: string;
  folder_id: string | null;
  name: string;
  mime_type: string;
  size: number;
  checksum?: string | null;
  version: number;
  status: string;
  trashed_at?: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface DriveVersionDto {
  id: string;
  file_id: string;
  version: number;
  size: number;
  checksum?: string | null;
  created_at?: string;
}

export interface DriveQuota {
  used: number;
  limit: number;
}

export interface DriveListing {
  folder: DriveFolderDto | null;
  folders: DriveFolderDto[];
  files: DriveFileDto[];
  quota: DriveQuota;
}

export interface DriveDownloadDto {
  url: string;
  name: string;
  mime_type: string;
  size: number;
}

export interface DriveFileUpdateInput {
  name?: string;
  contents?: string;
  mime_type?: string;
}

export interface SiteProviderDto {
  name: string;
  label: string;
  configured: boolean;
}

export type SiteFramework = 'static' | 'laravel' | 'next';
export type SiteStatus = 'provisioning' | 'ready' | 'failed';
export type SiteDeploymentStatus = 'building' | 'live' | 'failed' | 'rolled_back';

export interface SiteDto {
  id: string;
  name: string;
  framework: string;
  git_url: string;
  git_branch: string;
  provider: string;
  status: string;
  url: string | null;
  current_deployment_id: string | null;
  environment_variable_keys: string[];
  deployments_count?: number;
  created_at?: string;
  updated_at?: string;
}

export interface SiteDeploymentDto {
  id: string;
  site_id: string;
  number: number;
  commit_sha: string | null;
  status: string;
  url: string | null;
  logs: string | null;
  deployed_at: string | null;
  created_at?: string;
  updated_at?: string;
}

export interface SiteCreateInput {
  name: string;
  framework: SiteFramework;
  git_url: string;
  git_branch?: string;
  environment_variables?: Record<string, string>;
  provider?: string;
}

export interface SiteUpdateInput {
  name?: string;
  framework?: SiteFramework;
  git_url?: string;
  git_branch?: string;
  environment_variables?: Record<string, string>;
}

export interface PaymentProviderDto {
  name: string;
  label: string;
  configured: boolean;
}

export interface BillingPlanDto {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  price_monthly: number;
  price_yearly: number;
  currency: string;
  features: string[];
}

export type SubscriptionStatus = 'pending' | 'active' | 'past_due' | 'trialing' | 'canceled';

export interface AppliedCouponDto {
  id: string;
  code: string;
  name: string;
  discount_type: string;
  discount_value: number;
}

export interface SubscriptionDto {
  id: string;
  plan: BillingPlanDto | null;
  coupon: AppliedCouponDto | null;
  provider: string;
  status: SubscriptionStatus;
  current_period_start: string | null;
  current_period_end: string | null;
  canceled_at: string | null;
  created_at?: string;
  updated_at?: string;
}

export type InvoiceStatus = 'open' | 'paid' | 'failed' | 'void';

export interface InvoiceDto {
  id: string;
  number: string;
  amount: number;
  discount: number;
  credit_applied: number;
  amount_due: number;
  currency: string;
  status: InvoiceStatus;
  provider: string;
  paid_at: string | null;
  period_start: string | null;
  period_end: string | null;
  plan: BillingPlanDto | null;
  created_at?: string;
}

export interface BillingSubscribeResponse {
  subscription: SubscriptionDto;
  checkout_url: string;
}

export interface CouponDto {
  code: string;
  name: string;
  discount_type: 'percent' | 'amount';
  discount_value: number;
  discount: number;
}

export interface CreditEntryDto {
  id: string;
  amount: number;
  currency: string;
  reason: string;
  created_at: string | null;
}

export interface CreditSummaryDto {
  balance: number;
  entries: CreditEntryDto[];
}

export type SecuritySeverity = 'high' | 'medium' | 'low';
export type SecurityFindingStatus = 'open' | 'resolved' | 'dismissed';

export interface SecurityFindingDto {
  id: string;
  rule: string;
  severity: SecuritySeverity;
  status: SecurityFindingStatus;
  resource_type?: string | null;
  resource_id?: string | null;
  metadata?: Record<string, unknown> | null;
  resolved_at?: string | null;
  dismissed_at?: string | null;
  created_at?: string;
}

export interface SecurityScoreSummary {
  open: number;
  resolved: number;
  dismissed: number;
  high: number;
  medium: number;
  low: number;
}

export interface SecurityScoreDto {
  score: number;
  summary: SecurityScoreSummary;
  findings: SecurityFindingDto[];
}
