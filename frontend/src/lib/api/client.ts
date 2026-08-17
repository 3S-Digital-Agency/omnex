import type {
  ActivityFeed,
  ActivityItem,
  AuditLogDto,
  AuthSession,
  BillingPlanDto,
  BillingSubscribeResponse,
  CouponAdminDto,
  CouponCreateInput,
  CouponDto,
  CrossDeviceApproveInput,
  CrossDeviceStartDto,
  CouponRedemptionDto,
  CouponUpdateInput,
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
  DriveUsageHistoryDto,
  DriveVersionDto,
  InvitationDto,
  BillingCostBreakdownDto,
  InvoiceDto,
  LandingPageDto,
  LandingPageInput,
  LoginInput,
  LoginResponse,
  MeResponse,
  MembershipDto,
  MfaConfirmResponse,
  MfaSetupResponse,
  PasswordlessResponse,
  NotificationDto,
  NotificationListDto,
  NotificationQuery,
  OrganizationDto,
  PaginatedNotificationList,
  Paginated,
  PaymentProviderDto,
  PropagationStatusDto,
  RegisterInput,
  RoleDto,
  SecurityFindingDto,
  SecurityHistoryDto,
  SecurityScoreDto,
  SecuritySettingsDto,
  SecuritySettingsInput,
  SessionDto,
  SslCheckDto,
  CloudProviderDto,
  CloudProviderVerifyDto,
  ContactLeadDto,
  ContactLeadInput,
  ServerCreateInput,
  ServerDto,
  ServerMetricsDto,
  ServerOperationDto,
  ServerSnapshotDto,
  ServerUpdateInput,
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
  AuthenticatorDto,
  PasskeyCredentialDto,
  PasskeyRegisterOptionsDto,
  PasskeyRequestOptionsDto,
  SecurityLevel,
  StorageProviderDto,
  SubscriptionDto,
  SwitchResponse,
  UpdateProfileInput,
  UserDto,
  VerifyMfaInput,
} from './types';

export class ApiError extends Error {
  status: number;
  detail?: string;
  fieldErrors?: Record<string, string[]>;

  constructor(status: number, message: string, detail?: string, fieldErrors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.detail = detail;
    this.fieldErrors = fieldErrors;
  }
}

export interface ApiClient {
  register(input: RegisterInput): Promise<AuthSession>;
  login(input: LoginInput): Promise<LoginResponse>;
  verifyMfa(input: VerifyMfaInput): Promise<AuthSession>;
  me(): Promise<MeResponse>;
  updateProfile(input: UpdateProfileInput): Promise<UserDto>;
  logout(): Promise<void>;

  socialProviders(): Promise<SocialProviderDto[]>;
  socialRedirect(provider: string, link?: boolean): Promise<SocialRedirectResponse>;
  completeSocial(code: string): Promise<AuthSession>;
  listSocialAccounts(): Promise<SocialAccountDto[]>;
  unlinkSocial(provider: string): Promise<void>;

  /** Server-issued WebAuthn assertion options for a passkey sign-in. */
  passkeyRequestOptions(): Promise<PasskeyRequestOptionsDto>;
  /** Verify a WebAuthn assertion and complete the passkey sign-in. `null` = sandbox fallback. */
  verifyPasskey(credential: PasskeyCredentialDto | null, device?: { device_id: string; platform?: string }): Promise<PasswordlessResponse>;

  /** Start a cross-device pairing (PC ↔ phone QR flow). */
  startCrossDevice(): Promise<CrossDeviceStartDto>;
  /** Approve the pairing from the phone (signed assertion or sandbox). */
  approveCrossDevice(input: CrossDeviceApproveInput): Promise<PasswordlessResponse>;
  /** Confirm a brand-new device with the 6-digit code e-mailed to the owner. */
  verifyDevice(verification_token: string, code: string): Promise<AuthSession>;

  /** List the registered authenticators (YubiKey, passkeys, biometrics). */
  listAuthenticators(): Promise<AuthenticatorDto[]>;
  /** Issue a WebAuthn registration challenge for a new authenticator. */
  passkeyRegisterOptions(): Promise<PasskeyRegisterOptionsDto>;
  /** Store a newly created credential. */
  registerPasskey(input: {
    registration_token: string;
    credential: PasskeyCredentialDto;
    name: string;
    transport?: string;
  }): Promise<AuthenticatorDto>;
  /** Revoke an authenticator (lost key, replaced device…). */
  revokeAuthenticator(id: string): Promise<void>;
  /** Update the adaptive security level (standard / enhanced / critical). */
  updateSecurityLevel(level: SecurityLevel): Promise<SecurityLevel>;

  setupMfa(): Promise<MfaSetupResponse>;
  confirmMfa(code: string): Promise<MfaConfirmResponse>;
  disableMfa(password: string): Promise<void>;

  listOrganizations(): Promise<MembershipDto[]>;
  createOrganization(name: string): Promise<OrganizationDto>;
  switchOrganization(id: string): Promise<SwitchResponse>;

  listMembers(orgId: string): Promise<MembershipDto[]>;
  updateMemberRole(orgId: string, membershipId: string, roleId: string): Promise<MembershipDto>;
  removeMember(orgId: string, membershipId: string): Promise<void>;

  listInvitations(orgId: string): Promise<InvitationDto[]>;
  createInvitation(orgId: string, email: string, roleId: string): Promise<InvitationDto>;
  cancelInvitation(orgId: string, invitationId: string): Promise<void>;
  acceptInvitation(invitationId: string): Promise<void>;

  listRoles(): Promise<RoleDto[]>;
  listAudit(perPage?: number): Promise<Paginated<AuditLogDto>>;
  listActivity(sinceId?: number): Promise<ActivityFeed>;
  /** Subscribe to real-time activity events; returns an unsubscribe fn. */
  subscribeActivity(handler: (item: ActivityItem) => void): () => void;
  listNotifications(): Promise<NotificationListDto>;
  listNotificationsPage(query?: NotificationQuery): Promise<PaginatedNotificationList>;
  markNotificationRead(id: string): Promise<NotificationDto>;
  markAllNotificationsRead(): Promise<void>;
  /** Subscribe to real-time notification events; returns an unsubscribe fn. */
  subscribeNotifications(handler: (notification: NotificationDto) => void): () => void;

  listDomains(): Promise<DomainDto[]>;
  listDomainProviders(): Promise<DomainProviderDto[]>;
  searchDomains(query: string, tlds?: string[], provider?: string): Promise<DomainSearchResult[]>;
  checkDomain(domain: string, provider?: string): Promise<DomainCheckResult>;
  registerDomain(domain: string, years?: number, provider?: string): Promise<DomainDto>;
  getDomain(id: string): Promise<DomainDto>;
  renewDomain(id: string, years?: number): Promise<DomainDto>;
  updateDomain(id: string, input: DomainUpdateInput): Promise<DomainDto>;

  listDnsRecords(domainId: string): Promise<DnsRecordDto[]>;
  createDnsRecord(domainId: string, input: DnsRecordInput): Promise<DnsRecordDto>;
  updateDnsRecord(domainId: string, recordId: string, input: DnsRecordInput): Promise<DnsRecordDto>;
  deleteDnsRecord(domainId: string, recordId: string): Promise<void>;
  listDnsHistory(domainId: string): Promise<DnsHistoryDto[]>;
  rollbackDns(domainId: string, historyId: string): Promise<DnsRecordDto[]>;
  exportDns(domainId: string): Promise<string>;
  importDns(domainId: string, zoneFile: string): Promise<DnsRecordDto[]>;
  applyDnsTemplate(domainId: string, template: string): Promise<DnsRecordDto[]>;

  getDnssec(domainId: string): Promise<DnssecStatus>;
  enableDnssec(domainId: string): Promise<DnssecStatus>;
  disableDnssec(domainId: string): Promise<DnssecStatus>;

  getDnsPropagation(domainId: string): Promise<PropagationStatusDto>;
  checkDnsPropagation(domainId: string): Promise<PropagationStatusDto>;

  listStorageProviders(): Promise<StorageProviderDto[]>;
  listDrive(folderId?: string): Promise<DriveListing>;
  getDriveUsageHistory(): Promise<DriveUsageHistoryDto>;
  listDriveTrash(): Promise<DriveFileDto[]>;
  createFolder(parentId: string | null, name: string): Promise<DriveFolderDto>;
  renameFolder(folderId: string, name: string): Promise<DriveFolderDto>;
  deleteFolder(folderId: string): Promise<void>;
  uploadFile(folderId: string | null, name: string, contents: string, mimeType?: string): Promise<DriveFileDto>;
  downloadFile(fileId: string): Promise<DriveDownloadDto>;
  updateFile(fileId: string, input: DriveFileUpdateInput): Promise<DriveFileDto>;
  trashFile(fileId: string): Promise<DriveFileDto>;
  restoreFile(fileId: string): Promise<DriveFileDto>;
  deleteFile(fileId: string): Promise<void>;
  listFileVersions(fileId: string): Promise<DriveVersionDto[]>;
  restoreFileVersion(fileId: string, versionId: string): Promise<DriveFileDto>;

  getSecurityScore(): Promise<SecurityScoreDto>;
  scanSecurity(): Promise<SecurityScoreDto>;
  dismissSecurityFinding(id: string): Promise<SecurityFindingDto>;
  reopenSecurityFinding(id: string): Promise<SecurityFindingDto>;
  getSecuritySettings(): Promise<SecuritySettingsDto>;
  updateSecuritySettings(input: SecuritySettingsInput): Promise<SecuritySettingsDto>;
  getSecurityHistory(): Promise<SecurityHistoryDto>;
  listSslChecks(): Promise<SslCheckDto[]>;
  listSessions(): Promise<SessionDto[]>;
  revokeSession(id: string): Promise<void>;
  revokeOtherSessions(): Promise<void>;

  listBillingProviders(): Promise<PaymentProviderDto[]>;
  listBillingPlans(): Promise<BillingPlanDto[]>;
  getSubscription(): Promise<SubscriptionDto | null>;
  listInvoices(): Promise<InvoiceDto[]>;
  getBillingCostBreakdown(): Promise<BillingCostBreakdownDto>;
  subscribeToPlan(plan: string, provider?: string, coupon?: string): Promise<BillingSubscribeResponse>;
  cancelSubscription(id: string): Promise<SubscriptionDto>;
  validateCoupon(code: string): Promise<CouponDto>;
  changePlan(plan: string): Promise<SubscriptionDto>;
  getCredits(): Promise<CreditSummaryDto>;
  addCredits(amount: number, reason: string): Promise<CreditEntryDto>;
  listCoupons(): Promise<CouponAdminDto[]>;
  createCoupon(input: CouponCreateInput): Promise<CouponAdminDto>;
  updateCoupon(id: string, input: CouponUpdateInput): Promise<CouponAdminDto>;
  listCouponRedemptions(id: string): Promise<CouponRedemptionDto[]>;

  listSiteProviders(): Promise<SiteProviderDto[]>;
  listSites(): Promise<SiteDto[]>;
  getSite(id: string): Promise<SiteDto>;
  createSite(input: SiteCreateInput): Promise<SiteDto>;
  updateSite(id: string, input: SiteUpdateInput): Promise<SiteDto>;
  deleteSite(id: string): Promise<void>;
  listSiteDeployments(siteId: string): Promise<SiteDeploymentDto[]>;
  getSiteDeployment(siteId: string, deploymentId: string): Promise<SiteDeploymentDto>;
  deploySite(siteId: string): Promise<SiteDeploymentDto>;
  rollbackSite(siteId: string, deploymentId: string): Promise<SiteDeploymentDto>;

  submitContactLead(input: ContactLeadInput): Promise<ContactLeadDto>;

  getLandingPage(slug: string): Promise<LandingPageDto>;
  listLandingPages(): Promise<LandingPageDto[]>;
  createLandingPage(input: LandingPageInput): Promise<LandingPageDto>;
  updateLandingPage(id: string, input: LandingPageInput): Promise<LandingPageDto>;
  deleteLandingPage(id: string): Promise<void>;

  listCloudProviders(): Promise<CloudProviderDto[]>;
  verifyCloudProviders(provider?: string): Promise<CloudProviderVerifyDto[]>;
  listSshKeys(): Promise<SshKeyDto[]>;
  createSshKey(input: SshKeyCreateInput): Promise<SshKeyDto>;
  generateSshKey(input: SshKeyGenerateInput): Promise<SshKeyGenerateResponse>;
  unlockSshKey(id: string, vaultPassword: string): Promise<SshKeyUnlockResponse>;
  updateSshKey(id: string, input: SshKeyUpdateInput): Promise<SshKeyDto>;
  deleteSshKey(id: string): Promise<void>;
  installServerSshKey(serverId: string, sshKeyId: string): Promise<SshKeyInstallResponse>;
  listServers(): Promise<ServerDto[]>;
  getServer(id: string): Promise<ServerDto>;
  createServer(input: ServerCreateInput): Promise<ServerDto>;
  updateServer(id: string, input: ServerUpdateInput): Promise<ServerDto>;
  deleteServer(id: string): Promise<void>;
  listServerOperations(serverId: string): Promise<ServerOperationDto[]>;
  listServerSnapshots(serverId: string): Promise<ServerSnapshotDto[]>;
  createServerSnapshot(serverId: string, label?: string): Promise<ServerSnapshotDto>;
  deleteServerSnapshot(serverId: string, snapshotId: string): Promise<void>;
  listServerMetricsHistory(serverId: string, limit?: number): Promise<ServerMetricsDto[]>;
  subscribeServerMetrics(serverId: string, handler: (metrics: ServerMetricsDto) => void): () => void;
  startServer(serverId: string): Promise<ServerOperationDto>;
  stopServer(serverId: string): Promise<ServerOperationDto>;
  rebootServer(serverId: string): Promise<ServerOperationDto>;
  rebuildServer(serverId: string, image: string): Promise<ServerOperationDto>;
}
