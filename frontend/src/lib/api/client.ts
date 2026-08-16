import type {
  ActivityFeed,
  AuditLogDto,
  AuthSession,
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
  LoginInput,
  LoginResponse,
  MeResponse,
  MembershipDto,
  MfaConfirmResponse,
  MfaSetupResponse,
  NotificationDto,
  OrganizationDto,
  Paginated,
  PropagationStatusDto,
  RegisterInput,
  RoleDto,
  SocialAccountDto,
  SocialProviderDto,
  SocialRedirectResponse,
  StorageProviderDto,
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
  listNotifications(): Promise<NotificationDto[]>;
  markNotificationRead(id: string): Promise<void>;

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
}
