import { ApiError } from './client';
import type { ApiClient } from './client';
import { session } from './session';
import type {
  ActivityFeed,
  ActivityItem,
  AuditLogDto,
  AuthSession,
  BillingPlanDto,
  BillingSubscribeResponse,
  CouponDto,
  CreditEntryDto,
  CreditSummaryDto,
  DnsHistoryDto,
  DnsRecordDto,
  DnsRecordInput,
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
  PropagationStatusDto,
  RegisterInput,
  RoleDto,
  SecurityFindingDto,
  SecurityScoreDto,
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

const BASE = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api/v1';

function toBase64(input: string): string {
  const bytes = new TextEncoder().encode(input);
  let binary = '';
  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte);
  });
  return btoa(binary);
}

export class HttpApiClient implements ApiClient {
  private async request<T>(path: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
    };

    const token = session.getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    const org = session.getOrganizationId();
    if (org) headers['X-Organization'] = org;

    const response = await fetch(`${BASE}${path}`, {
      method: init.method ?? 'GET',
      headers,
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });

    if (response.status === 204) {
      return undefined as T;
    }

    const text = await response.text();
    const data: unknown = text.length > 0 ? JSON.parse(text) : null;

    if (!response.ok) {
      const problem = data as { title?: string; detail?: string; errors?: Record<string, string[]> } | null;
      throw new ApiError(
        response.status,
        problem?.title ?? 'Request failed',
        problem?.detail,
        problem?.errors,
      );
    }

    return data as T;
  }

  register(input: RegisterInput): Promise<AuthSession> {
    return this.request('/auth/register', { method: 'POST', body: input });
  }

  login(input: LoginInput): Promise<LoginResponse> {
    return this.request('/auth/login', { method: 'POST', body: input });
  }

  verifyMfa(input: VerifyMfaInput): Promise<AuthSession> {
    return this.request('/auth/mfa/verify', { method: 'POST', body: input });
  }

  me(): Promise<MeResponse> {
    return this.request('/me');
  }

  updateProfile(input: UpdateProfileInput): Promise<UserDto> {
    return this.request('/me', { method: 'PATCH', body: input });
  }

  async socialProviders(): Promise<SocialProviderDto[]> {
    const res = await this.request<{ data: SocialProviderDto[] }>('/auth/social/providers');
    return res.data;
  }

  socialRedirect(provider: string, link = false): Promise<SocialRedirectResponse> {
    return this.request(`/auth/${provider}/redirect${link ? '?link=1' : ''}`);
  }

  completeSocial(code: string): Promise<AuthSession> {
    return this.request('/auth/social/complete', { method: 'POST', body: { code } });
  }

  async listSocialAccounts(): Promise<SocialAccountDto[]> {
    const res = await this.request<{ data: SocialAccountDto[] }>('/auth/social/accounts');
    return res.data;
  }

  unlinkSocial(provider: string): Promise<void> {
    return this.request(`/auth/social/accounts/${provider}`, { method: 'DELETE' });
  }

  logout(): Promise<void> {
    return this.request('/auth/logout', { method: 'POST' });
  }

  setupMfa(): Promise<MfaSetupResponse> {
    return this.request('/auth/mfa/setup', { method: 'POST' });
  }

  confirmMfa(code: string): Promise<MfaConfirmResponse> {
    return this.request('/auth/mfa/confirm', { method: 'POST', body: { code } });
  }

  disableMfa(password: string): Promise<void> {
    return this.request('/auth/mfa/disable', { method: 'POST', body: { password } });
  }

  async listOrganizations(): Promise<MembershipDto[]> {
    const res = await this.request<{ data: MembershipDto[] }>('/organizations');
    return res.data;
  }

  createOrganization(name: string): Promise<OrganizationDto> {
    return this.request('/organizations', { method: 'POST', body: { name } });
  }

  switchOrganization(id: string): Promise<SwitchResponse> {
    return this.request(`/organizations/${id}/switch`, { method: 'POST' });
  }

  async listMembers(orgId: string): Promise<MembershipDto[]> {
    const res = await this.request<{ data: MembershipDto[] }>(`/organizations/${orgId}/members`);
    return res.data;
  }

  updateMemberRole(orgId: string, membershipId: string, roleId: string): Promise<MembershipDto> {
    return this.request(`/organizations/${orgId}/members/${membershipId}/role`, {
      method: 'PATCH',
      body: { role_id: roleId },
    });
  }

  removeMember(orgId: string, membershipId: string): Promise<void> {
    return this.request(`/organizations/${orgId}/members/${membershipId}`, { method: 'DELETE' });
  }

  async listInvitations(orgId: string): Promise<InvitationDto[]> {
    const res = await this.request<{ data: InvitationDto[] }>(`/organizations/${orgId}/invitations`);
    return res.data;
  }

  createInvitation(orgId: string, email: string, roleId: string): Promise<InvitationDto> {
    return this.request(`/organizations/${orgId}/invitations`, {
      method: 'POST',
      body: { email, role_id: roleId },
    });
  }

  cancelInvitation(orgId: string, invitationId: string): Promise<void> {
    return this.request(`/organizations/${orgId}/invitations/${invitationId}`, { method: 'DELETE' });
  }

  acceptInvitation(invitationId: string): Promise<void> {
    return this.request(`/invitations/${invitationId}/accept`, { method: 'POST' });
  }

  async listRoles(): Promise<RoleDto[]> {
    const res = await this.request<{ data: RoleDto[] }>('/roles');
    return res.data;
  }

  listAudit(perPage = 25): Promise<Paginated<AuditLogDto>> {
    return this.request(`/audit?per_page=${perPage}`);
  }

  listActivity(sinceId?: number): Promise<ActivityFeed> {
    const query = sinceId ? `?since=${sinceId}` : '';
    return this.request(`/activity${query}`);
  }

  subscribeActivity(handler: (item: ActivityItem) => void): () => void {
    const controller = new AbortController();
    let stopped = false;
    let reconnect: ReturnType<typeof setTimeout> | undefined;

    async function connect() {
      const headers: Record<string, string> = {
        Accept: 'text/event-stream',
        'Cache-Control': 'no-cache',
      };
      const token = session.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
      const org = session.getOrganizationId();
      if (org) headers['X-Organization'] = org;

      try {
        const response = await fetch(`${BASE}/activity/stream`, {
          headers,
          signal: controller.signal,
        });

        if (!response.ok || !response.body) {
          throw new Error(`SSE connection failed with HTTP ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (!stopped) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });

          const frames = buffer.split('\n\n');
          buffer = frames.pop() ?? '';

          for (const frame of frames) {
            let event = 'message';
            let data = '';
            for (const line of frame.split('\n')) {
              if (line.startsWith('event:')) event = line.slice(6).trim();
              else if (line.startsWith('data:')) data += line.slice(5).trim();
            }
            if (event === 'activity.created' && data) {
              try {
                handler(JSON.parse(data) as ActivityItem);
              } catch {
                // Ignore malformed frames.
              }
            }
          }
        }
      } catch (error) {
        if ((error as Error)?.name === 'AbortError') return;
      }

      if (!stopped) {
        reconnect = setTimeout(connect, 3000);
      }
    }

    void connect();

    return () => {
      stopped = true;
      controller.abort();
      if (reconnect) clearTimeout(reconnect);
    };
  }

  async listNotifications(): Promise<NotificationListDto> {
    const res = await this.request<{ data: NotificationDto[]; unread: number }>('/notifications?per_page=50');
    return { data: res.data, unread: res.unread ?? res.data.filter((n) => !n.read_at).length };
  }

  listNotificationsPage(query: NotificationQuery = {}): Promise<PaginatedNotificationList> {
    const params = new URLSearchParams();
    if (query.type) params.set('type', query.type);
    if (query.severity) params.set('severity', query.severity);
    if (query.unread !== undefined) params.set('unread', query.unread ? '1' : '0');
    if (query.page) params.set('page', String(query.page));
    if (query.perPage) params.set('per_page', String(query.perPage));
    return this.request(`/notifications?${params.toString()}`);
  }

  markNotificationRead(id: string): Promise<NotificationDto> {
    return this.request(`/notifications/${id}/read`, { method: 'POST' });
  }

  markAllNotificationsRead(): Promise<void> {
    return this.request('/notifications/read-all', { method: 'POST' });
  }

  subscribeNotifications(handler: (notification: NotificationDto) => void): () => void {
    const controller = new AbortController();
    let stopped = false;
    let reconnect: ReturnType<typeof setTimeout> | undefined;

    async function connect() {
      const headers: Record<string, string> = {
        Accept: 'text/event-stream',
        'Cache-Control': 'no-cache',
      };
      const token = session.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
      const org = session.getOrganizationId();
      if (org) headers['X-Organization'] = org;

      try {
        const response = await fetch(`${BASE}/notifications/stream`, {
          headers,
          signal: controller.signal,
        });

        if (!response.ok || !response.body) {
          throw new Error(`SSE connection failed with HTTP ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (!stopped) {
          const { done, value } = await reader.read();
          if (done) break;
          buffer += decoder.decode(value, { stream: true });

          const frames = buffer.split('\n\n');
          buffer = frames.pop() ?? '';

          for (const frame of frames) {
            let event = 'message';
            let data = '';
            for (const line of frame.split('\n')) {
              if (line.startsWith('event:')) event = line.slice(6).trim();
              else if (line.startsWith('data:')) data += line.slice(5).trim();
            }
            if (event === 'notification.created' && data) {
              try {
                handler(JSON.parse(data) as NotificationDto);
              } catch {
                // Ignore malformed frames.
              }
            }
          }
        }
      } catch (error) {
        if ((error as Error)?.name === 'AbortError') return;
      }

      if (!stopped) {
        reconnect = setTimeout(connect, 3000);
      }
    }

    void connect();

    return () => {
      stopped = true;
      controller.abort();
      if (reconnect) clearTimeout(reconnect);
    };
  }

  async listDomains(): Promise<DomainDto[]> {
    const res = await this.request<{ data: DomainDto[] }>('/domains');
    return res.data;
  }

  async listDomainProviders(): Promise<DomainProviderDto[]> {
    const res = await this.request<{ data: DomainProviderDto[] }>('/domains/providers');
    return res.data;
  }

  async searchDomains(query: string, tlds?: string[], provider?: string): Promise<DomainSearchResult[]> {
    const params = new URLSearchParams({ query });
    tlds?.forEach((tld) => params.append('tlds[]', tld));
    if (provider) params.append('provider', provider);
    const res = await this.request<{ data: DomainSearchResult[] }>(`/domains/search?${params.toString()}`);
    return res.data;
  }

  checkDomain(domain: string, provider?: string): Promise<DomainCheckResult> {
    const params = new URLSearchParams({ domain });
    if (provider) params.append('provider', provider);
    return this.request(`/domains/check?${params.toString()}`);
  }

  registerDomain(domain: string, years?: number, provider?: string): Promise<DomainDto> {
    return this.request('/domains', {
      method: 'POST',
      body: { domain, ...(years ? { years } : {}), ...(provider ? { provider } : {}) },
    });
  }

  getDomain(id: string): Promise<DomainDto> {
    return this.request(`/domains/${id}`);
  }

  renewDomain(id: string, years?: number): Promise<DomainDto> {
    return this.request(`/domains/${id}/renew`, { method: 'POST', body: years ? { years } : {} });
  }

  updateDomain(id: string, input: DomainUpdateInput): Promise<DomainDto> {
    return this.request(`/domains/${id}`, { method: 'PATCH', body: input });
  }

  async listDnsRecords(domainId: string): Promise<DnsRecordDto[]> {
    const res = await this.request<{ data: DnsRecordDto[] }>(`/domains/${domainId}/dns`);
    return res.data;
  }

  createDnsRecord(domainId: string, input: DnsRecordInput): Promise<DnsRecordDto> {
    return this.request(`/domains/${domainId}/dns/records`, { method: 'POST', body: input });
  }

  updateDnsRecord(domainId: string, recordId: string, input: DnsRecordInput): Promise<DnsRecordDto> {
    return this.request(`/domains/${domainId}/dns/records/${recordId}`, { method: 'PATCH', body: input });
  }

  deleteDnsRecord(domainId: string, recordId: string): Promise<void> {
    return this.request(`/domains/${domainId}/dns/records/${recordId}`, { method: 'DELETE' });
  }

  async listDnsHistory(domainId: string): Promise<DnsHistoryDto[]> {
    const res = await this.request<{ data: DnsHistoryDto[] }>(`/domains/${domainId}/dns/history`);
    return res.data;
  }

  async rollbackDns(domainId: string, historyId: string): Promise<DnsRecordDto[]> {
    const res = await this.request<{ data: DnsRecordDto[] }>(`/domains/${domainId}/dns/history/${historyId}/rollback`, {
      method: 'POST',
    });
    return res.data;
  }

  async exportDns(domainId: string): Promise<string> {
    const res = await this.request<{ zone_file: string }>(`/domains/${domainId}/dns/export`);
    return res.zone_file;
  }

  async importDns(domainId: string, zoneFile: string): Promise<DnsRecordDto[]> {
    const res = await this.request<{ data: DnsRecordDto[] }>(`/domains/${domainId}/dns/import`, {
      method: 'POST',
      body: { zone_file: zoneFile },
    });
    return res.data;
  }

  async applyDnsTemplate(domainId: string, template: string): Promise<DnsRecordDto[]> {
    const res = await this.request<{ data: DnsRecordDto[] }>(`/domains/${domainId}/dns/templates/${template}`, {
      method: 'POST',
    });
    return res.data;
  }

  getDnssec(domainId: string): Promise<DnssecStatus> {
    return this.request(`/domains/${domainId}/dns/dnssec`);
  }

  enableDnssec(domainId: string): Promise<DnssecStatus> {
    return this.request(`/domains/${domainId}/dns/dnssec`, { method: 'POST' });
  }

  disableDnssec(domainId: string): Promise<DnssecStatus> {
    return this.request(`/domains/${domainId}/dns/dnssec`, { method: 'DELETE' });
  }

  getDnsPropagation(domainId: string): Promise<PropagationStatusDto> {
    return this.request(`/domains/${domainId}/dns/propagation`);
  }

  checkDnsPropagation(domainId: string): Promise<PropagationStatusDto> {
    return this.request(`/domains/${domainId}/dns/propagation/check`, { method: 'POST' });
  }

  async listStorageProviders(): Promise<StorageProviderDto[]> {
    const res = await this.request<{ data: StorageProviderDto[] }>('/storage/providers');
    return res.data;
  }

  listDrive(folderId?: string): Promise<DriveListing> {
    return this.request(folderId ? `/storage/folders/${folderId}` : '/storage');
  }

  async listDriveTrash(): Promise<DriveFileDto[]> {
    const res = await this.request<{ data: DriveFileDto[] }>('/storage/trash');
    return res.data;
  }

  createFolder(parentId: string | null, name: string): Promise<DriveFolderDto> {
    return this.request('/storage/folders', {
      method: 'POST',
      body: { name, ...(parentId ? { parent_id: parentId } : {}) },
    });
  }

  renameFolder(folderId: string, name: string): Promise<DriveFolderDto> {
    return this.request(`/storage/folders/${folderId}`, { method: 'PATCH', body: { name } });
  }

  deleteFolder(folderId: string): Promise<void> {
    return this.request(`/storage/folders/${folderId}`, { method: 'DELETE' });
  }

  uploadFile(folderId: string | null, name: string, contents: string, mimeType = 'text/plain'): Promise<DriveFileDto> {
    return this.request('/storage/files', {
      method: 'POST',
      body: { name, contents: toBase64(contents), mime_type: mimeType, ...(folderId ? { folder_id: folderId } : {}) },
    });
  }

  downloadFile(fileId: string): Promise<DriveDownloadDto> {
    return this.request(`/storage/files/${fileId}/download`);
  }

  updateFile(fileId: string, input: DriveFileUpdateInput): Promise<DriveFileDto> {
    return this.request(`/storage/files/${fileId}`, {
      method: 'PATCH',
      body: {
        ...(input.name !== undefined ? { name: input.name } : {}),
        ...(input.contents !== undefined ? { contents: toBase64(input.contents) } : {}),
        ...(input.mime_type !== undefined ? { mime_type: input.mime_type } : {}),
      },
    });
  }

  trashFile(fileId: string): Promise<DriveFileDto> {
    return this.request(`/storage/files/${fileId}`, { method: 'DELETE' });
  }

  restoreFile(fileId: string): Promise<DriveFileDto> {
    return this.request(`/storage/files/${fileId}/restore`, { method: 'POST' });
  }

  deleteFile(fileId: string): Promise<void> {
    return this.request(`/storage/trash/${fileId}`, { method: 'DELETE' });
  }

  async listFileVersions(fileId: string): Promise<DriveVersionDto[]> {
    const res = await this.request<{ data: DriveVersionDto[] }>(`/storage/files/${fileId}/versions`);
    return res.data;
  }

  restoreFileVersion(fileId: string, versionId: string): Promise<DriveFileDto> {
    return this.request(`/storage/files/${fileId}/versions/${versionId}/restore`, { method: 'POST' });
  }

  getSecurityScore(): Promise<SecurityScoreDto> {
    return this.request('/security');
  }

  scanSecurity(): Promise<SecurityScoreDto> {
    return this.request('/security/scan', { method: 'POST' });
  }

  dismissSecurityFinding(id: string): Promise<SecurityFindingDto> {
    return this.request(`/security/findings/${id}/dismiss`, { method: 'POST' });
  }

  reopenSecurityFinding(id: string): Promise<SecurityFindingDto> {
    return this.request(`/security/findings/${id}/reopen`, { method: 'POST' });
  }

  async listSiteProviders(): Promise<SiteProviderDto[]> {
    const res = await this.request<{ data: SiteProviderDto[] }>('/sites/providers');
    return res.data;
  }

  async listSites(): Promise<SiteDto[]> {
    const res = await this.request<{ data: SiteDto[] }>('/sites');
    return res.data;
  }

  getSite(id: string): Promise<SiteDto> {
    return this.request(`/sites/${id}`);
  }

  createSite(input: SiteCreateInput): Promise<SiteDto> {
    return this.request('/sites', { method: 'POST', body: input });
  }

  updateSite(id: string, input: SiteUpdateInput): Promise<SiteDto> {
    return this.request(`/sites/${id}`, { method: 'PATCH', body: input });
  }

  deleteSite(id: string): Promise<void> {
    return this.request(`/sites/${id}`, { method: 'DELETE' });
  }

  async listSiteDeployments(siteId: string): Promise<SiteDeploymentDto[]> {
    const res = await this.request<{ data: SiteDeploymentDto[] }>(`/sites/${siteId}/deployments`);
    return res.data;
  }

  getSiteDeployment(siteId: string, deploymentId: string): Promise<SiteDeploymentDto> {
    return this.request(`/sites/${siteId}/deployments/${deploymentId}`);
  }

  deploySite(siteId: string): Promise<SiteDeploymentDto> {
    return this.request(`/sites/${siteId}/deployments`, { method: 'POST' });
  }

  rollbackSite(siteId: string, deploymentId: string): Promise<SiteDeploymentDto> {
    return this.request(`/sites/${siteId}/deployments/${deploymentId}/rollback`, { method: 'POST' });
  }

  async listBillingProviders(): Promise<PaymentProviderDto[]> {
    const res = await this.request<{ data: PaymentProviderDto[] }>('/billing/providers');
    return res.data;
  }

  async listBillingPlans(): Promise<BillingPlanDto[]> {
    const res = await this.request<{ data: BillingPlanDto[] }>('/billing/plans');
    return res.data;
  }

  async getSubscription(): Promise<SubscriptionDto | null> {
    const res = await this.request<{ data: SubscriptionDto | null }>('/billing/subscription');
    return res.data;
  }

  async listInvoices(): Promise<InvoiceDto[]> {
    const res = await this.request<{ data: InvoiceDto[] }>('/billing/invoices');
    return res.data;
  }

  subscribeToPlan(plan: string, provider?: string, coupon?: string): Promise<BillingSubscribeResponse> {
    return this.request('/billing/subscribe', {
      method: 'POST',
      body: { plan, ...(provider ? { provider } : {}), ...(coupon ? { coupon } : {}) },
    });
  }

  cancelSubscription(id: string): Promise<SubscriptionDto> {
    return this.request(`/billing/subscriptions/${id}/cancel`, { method: 'POST' });
  }

  async validateCoupon(code: string): Promise<CouponDto> {
    const res = await this.request<{ data: CouponDto }>('/billing/coupons/validate', {
      method: 'POST',
      body: { code },
    });
    return res.data;
  }

  changePlan(plan: string): Promise<SubscriptionDto> {
    return this.request('/billing/change-plan', { method: 'POST', body: { plan } });
  }

  async getCredits(): Promise<CreditSummaryDto> {
    const res = await this.request<{ data: CreditSummaryDto }>('/billing/credits');
    return res.data;
  }

  async addCredits(amount: number, reason: string): Promise<CreditEntryDto> {
    const res = await this.request<{ data: CreditEntryDto }>('/billing/credits', {
      method: 'POST',
      body: { amount, reason },
    });
    return res.data;
  }
}
