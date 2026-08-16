import { ApiError } from './client';
import type { ApiClient } from './client';
import { session } from './session';
import type {
  ActivityFeed,
  ActivityItem,
  AuditLogDto,
  AuthSession,
  DnsHistoryDto,
  DnsRecordDto,
  DnsRecordInput,
  DnssecDsRecord,
  DnssecStatus,
  DomainCheckResult,
  DomainDto,
  DomainSearchResult,
  DomainUpdateInput,
  InvitationDto,
  LoginInput,
  LoginResponse,
  MeResponse,
  MembershipDto,
  MfaConfirmResponse,
  MfaSetupResponse,
  NotificationDto,
  OrganizationDto,
  Paginated,
  PropagationCheckDto,
  PropagationStatus,
  PropagationStatusDto,
  RegisterInput,
  RoleDto,
  SocialAccountDto,
  SocialProviderDto,
  SocialRedirectResponse,
  SwitchResponse,
  UpdateProfileInput,
  UserDto,
  VerifyMfaInput,
} from './types';

let seq = 0;
const uid = (prefix: string): string => `${prefix}-${(++seq).toString(36)}${Date.now().toString(36)}`;
const nowIso = (): string => new Date().toISOString();

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
];

const roles: RoleDto[] = [
  { id: 'role-owner', name: 'Owner', key: 'owner', description: 'Full control over the organization.', permissions: ALL_PERMISSIONS },
  {
    id: 'role-admin',
    name: 'Admin',
    key: 'admin',
    description: 'Manage members and settings.',
    permissions: ['organizations.read', 'organizations.invite', 'members.manage', 'audit.read', 'notifications.read', 'domains.read', 'domains.manage', 'dns.read', 'dns.manage'],
  },
  {
    id: 'role-developer',
    name: 'Developer',
    key: 'developer',
    description: 'Read access to the organization and audit log.',
    permissions: ['organizations.read', 'audit.read', 'notifications.read', 'domains.read', 'dns.read'],
  },
  {
    id: 'role-viewer',
    name: 'Viewer',
    key: 'viewer',
    description: 'Read-only access.',
    permissions: ['organizations.read', 'notifications.read', 'domains.read', 'dns.read'],
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
    email: 'demo@omnex.dev',
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
    email: 'dev@omnex.dev',
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

const notifications: NotificationDto[] = [
  {
    id: 'notif-1',
    type: 'welcome',
    title: 'Welcome to OMNEX',
    body: 'Your OMNEX Cloud OS organization is ready.',
    read_at: null,
    created_at: '2026-01-15T09:06:00Z',
  },
];

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

let activitySeq = 100;
let activity: ActivityItem[] = auditLogs.map((log) => ({
  id: log.id,
  type: activityType(log.action),
  severity: log.result === 'success' ? 'success' : 'danger',
  title: activityTitle(log.action),
  description: log.action,
  actor: null,
  created_at: log.created_at,
}));

const activityPool: Array<Omit<ActivityItem, 'id' | 'created_at'>> = [
  { type: 'deployment', severity: 'info', title: 'Deployment started', description: 'main → production (OMNEX HQ)', actor: 'Dev User' },
  { type: 'deployment', severity: 'success', title: 'Deployment completed', description: 'main → production (OMNEX HQ)', actor: 'Dev User' },
  { type: 'ssl', severity: 'warning', title: 'SSL certificate expiring', description: 'omnex.dev expires in 24 days', actor: 'System' },
  { type: 'domain', severity: 'success', title: 'Domain registered', description: 'omnex.dev', actor: 'Demo Owner' },
  { type: 'security', severity: 'info', title: 'Security scan completed', description: 'No critical findings', actor: 'System' },
  { type: 'backup', severity: 'success', title: 'Backup completed', description: 'Daily incremental snapshot', actor: 'System' },
  { type: 'incident', severity: 'warning', title: 'High CPU detected', description: 'worker-1 above 90% for 5 min', actor: 'System' },
];

// --- Domain + DNS engine (Phase 3 sandbox) ---------------------------------

const RESERVED_DOMAINS = ['omnex.dev', 'omnex.io', 'nexus.com', 'cloud.com', 'google.com', 'apple.com'];
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

const domains: DomainDto[] = [
  {
    id: 'dom-omnex-dev',
    name: 'omnex.dev',
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
  { name: 'google', label: 'Google', configured: true },
  { name: 'microsoft', label: 'Microsoft', configured: true },
  { name: 'apple', label: 'Apple', configured: true },
  { name: 'facebook', label: 'Facebook', configured: true },
  { name: 'amazon', label: 'Amazon', configured: true },
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

    const code = encodeURIComponent(`mock:${provider}:demo@omnex.dev`);
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

  async listNotifications(): Promise<NotificationDto[]> {
    requireUser();
    return Promise.resolve(notifications);
  }

  async  markNotificationRead(id: string): Promise<void> {
    requireUser();
    const n = notifications.find((x) => x.id === id);
    if (!n) throw new ApiError(404, 'Not found');
    n.read_at = nowIso();
    return Promise.resolve();
  }

  async listActivity(sinceId?: number): Promise<ActivityFeed> {
    requireUser();

    // Simulate a live event stream so the feed grows between polls.
    const next = activityPool[Math.floor(Math.random() * activityPool.length)];
    activity = [...activity, { ...next, id: ++activitySeq, created_at: nowIso() }];

    const filtered = sinceId ? activity.filter((a) => a.id > sinceId) : activity;
    const latestId = filtered.length > 0 ? Math.max(...filtered.map((a) => a.id)) : (sinceId ?? 0);

    return {
      data: filtered.slice().reverse().slice(0, 50),
      latest_id: latestId,
    };
  }

  async listDomains(): Promise<DomainDto[]> {
    requireUser();
    return Promise.resolve([...domains]);
  }

  async searchDomains(query: string, tlds?: string[]): Promise<DomainSearchResult[]> {
    requireUser();
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
        };
      }),
    );
  }

  async checkDomain(domain: string): Promise<DomainCheckResult> {
    requireUser();
    const name = domain.trim().toLowerCase();
    return Promise.resolve({ domain: name, available: domainAvailable(name), managed: domains.some((d) => d.name === name) });
  }

  async registerDomain(domain: string, years = 1): Promise<DomainDto> {
    requireUser();
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
      provider: 'sandbox',
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
}
