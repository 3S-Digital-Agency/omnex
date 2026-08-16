import { MockApiClient } from '../mock';

describe('MockApiClient', () => {
  it('logs in with the seeded demo account', async () => {
    const api = new MockApiClient();
    const res = await api.login({ email: 'demo@omnex.dev', password: 'password' });
    expect('token' in res).toBe(true);
  });

  it('rejects wrong credentials', async () => {
    const api = new MockApiClient();
    await expect(api.login({ email: 'demo@omnex.dev', password: 'nope' })).rejects.toThrow();
  });

  it('injects the user locale in the login response', async () => {
    const api = new MockApiClient();
    const res = await api.login({ email: 'demo@omnex.dev', password: 'password' });
    expect('token' in res).toBe(true);
    if ('token' in res) expect(res.user.locale).toBeNull();
  });

  it('returns a null locale for a freshly registered user', async () => {
    const api = new MockApiClient();
    const res = await api.register({
      name: 'Locale Tester',
      email: 'locale-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });
    expect(res.user.locale).toBeNull();
  });

  it('scopes member listings to an organization', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.dev', password: 'password' });
    const members = await api.listMembers('org-omnex-hq');
    expect(members.length).toBeGreaterThan(0);
    expect(members.every((m) => m.organization?.id === 'org-omnex-hq')).toBe(true);
  });

  it('requires MFA verification when enabled', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.dev', password: 'password' });
    await api.setupMfa();
    await api.confirmMfa('123456');

    await api.logout();
    const res = await api.login({ email: 'demo@omnex.dev', password: 'password' });
    expect('mfa_required' in res && res.mfa_required).toBe(true);
  });

  it('searches and registers a domain', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Domain Tester',
      email: 'domain-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const results = await api.searchDomains('my-unique-brand', ['com']);
    expect(results).toHaveLength(1);

    const domain = await api.registerDomain('my-unique-brand.com');
    expect(domain.name).toBe('my-unique-brand.com');

    const domains = await api.listDomains();
    expect(domains.map((d) => d.name)).toContain('my-unique-brand.com');
  });

  it('lists domain providers and threads the selected registrar', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Provider Tester',
      email: 'provider-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const providers = await api.listDomainProviders();
    expect(providers.map((p) => p.name)).toEqual(['sandbox', 'namecheap', 'ovh', 'custom']);
    expect(providers.find((p) => p.name === 'sandbox')?.configured).toBe(true);
    expect(providers.find((p) => p.name === 'ovh')?.configured).toBe(false);

    const results = await api.searchDomains('provider-brand', ['com'], 'sandbox');
    expect(results[0]?.provider).toBe('sandbox');

    const domain = await api.registerDomain('provider-brand.com', 1, 'sandbox');
    expect(domain.provider).toBe('sandbox');
  });

  it('rejects a registration against an unconfigured registrar', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Provider Reject Tester',
      email: 'provider-reject@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    await expect(api.registerDomain('reject-brand.com', 1, 'ovh')).rejects.toMatchObject({ status: 422 });
  });

  it('lists, uploads, versions and trashes a file', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Drive Tester',
      email: 'drive-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    expect((await api.listDrive()).files).toHaveLength(0);

    const folder = await api.createFolder(null, 'Projects');
    const file = await api.uploadFile(folder.id, 'notes.txt', 'hello');
    expect(file.version).toBe(1);
    expect(file.size).toBe(5);

    const listing = await api.listDrive(folder.id);
    expect(listing.files.map((f) => f.name)).toContain('notes.txt');

    const updated = await api.updateFile(file.id, { contents: 'hello v2' });
    expect(updated.version).toBe(2);
    expect((await api.listFileVersions(file.id))).toHaveLength(2);

    const trashed = await api.trashFile(file.id);
    expect(trashed.status).toBe('trashed');
    expect((await api.listDriveTrash())).toHaveLength(1);

    expect((await api.restoreFile(file.id)).status).toBe('active');

    await api.trashFile(file.id);
    await api.deleteFile(file.id);
    expect((await api.listDriveTrash())).toHaveLength(0);
  });

  it('restores an older version of a file', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Drive Version Tester',
      email: 'drive-version-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const file = await api.uploadFile(null, 'doc.txt', 'one');
    await api.updateFile(file.id, { contents: 'two' });

    const versions = await api.listFileVersions(file.id);
    const v1 = versions.find((v) => v.version === 1);
    expect(v1).toBeDefined();

    const restored = await api.restoreFileVersion(file.id, v1!.id);
    expect(restored.version).toBe(3);
    expect(restored.size).toBe(3);
  });

  it('creates and rolls back a DNS record', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'DNS Tester',
      email: 'dns-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const record = await api.createDnsRecord('dom-omnex-dev', {
      type: 'A',
      name: 'staging',
      content: '203.0.113.10',
    });
    expect(record.name).toBe('staging');

    const history = await api.listDnsHistory('dom-omnex-dev');
    const created = history.find((h) => h.action === 'created');
    expect(created).toBeDefined();

    const records = await api.rollbackDns('dom-omnex-dev', created!.id);
    expect(records.some((r) => r.name === 'staging')).toBe(false);
  });

  it('links and unlinks a social account', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.dev', password: 'password' });

    await api.socialRedirect('google', true);
    let accounts = await api.listSocialAccounts();
    expect(accounts.map((a) => a.provider)).toContain('google');

    await api.unlinkSocial('google');
    accounts = await api.listSocialAccounts();
    expect(accounts.map((a) => a.provider)).not.toContain('google');
  });

  it('completes a social login for a new user', async () => {
    const api = new MockApiClient();
    const res = await api.completeSocial('mock:apple:social-user@example.com');
    expect('token' in res).toBe(true);
    expect(res.user.email).toBe('social-user@example.com');
  });

  it('enables and disables DNSSEC on a zone', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Dnssec Tester',
      email: 'dnssec@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const enabled = await api.enableDnssec('dom-omnex-dev');
    expect(enabled.enabled).toBe(true);
    expect(enabled.status).toBe('active');
    expect(enabled.ds_records).toHaveLength(1);
    expect(enabled.ds_records[0].algorithm).toBe(13);

    const disabled = await api.disableDnssec('dom-omnex-dev');
    expect(disabled.enabled).toBe(false);
    expect(disabled.status).toBe('unsigned');
    expect(disabled.ds_records).toHaveLength(0);
  });

  it('runs a per-nameserver propagation check', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Prop Tester',
      email: 'prop@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const empty = await api.getDnsPropagation('dom-omnex-dev');
    expect(empty.summary.total).toBe(0);

    const status = await api.checkDnsPropagation('dom-omnex-dev');
    expect(status.summary.total).toBeGreaterThan(0);
    expect(status.nameservers.length).toBeGreaterThan(0);
    expect(status.data.every((c) => ['synced', 'pending', 'outdated'].includes(c.status))).toBe(true);
    expect(status.data[0].nameserver).toBeTruthy();

    const persisted = await api.getDnsPropagation('dom-omnex-dev');
    expect(persisted.summary.total).toBe(status.summary.total);
  });
});
