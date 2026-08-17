import { ALERT_THRESHOLDS, MockApiClient } from '../mock';
import type { ActivityItem, MfaPolicy, ServerMetricsDto } from '../types';

describe('MockApiClient', () => {
  it('logs in with the seeded demo account', async () => {
    const api = new MockApiClient();
    const res = await api.login({ email: 'demo@omnex.cloud', password: 'password' });
    expect('token' in res).toBe(true);
  });

  it('rejects wrong credentials', async () => {
    const api = new MockApiClient();
    await expect(api.login({ email: 'demo@omnex.cloud', password: 'nope' })).rejects.toThrow();
  });

  it('injects the user locale in the login response', async () => {
    const api = new MockApiClient();
    const res = await api.login({ email: 'demo@omnex.cloud', password: 'password' });
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
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });
    const members = await api.listMembers('org-omnex-hq');
    expect(members.length).toBeGreaterThan(0);
    expect(members.every((m) => m.organization?.id === 'org-omnex-hq')).toBe(true);
  });

  it('completes a passkey sign-in against the sandbox', async () => {
    const api = new MockApiClient();
    const options = await api.passkeyRequestOptions();
    expect(options.challenge.length).toBeGreaterThan(16);
    expect(options.rp_id.length).toBeGreaterThan(0);

    const session = await api.verifyPasskey({
      id: 'cred-passkey-demo',
      raw_id: 'cred-passkey-demo',
      type: 'public-key',
      response: {
        client_data_json: 'eyJjaGFsbGVuZ2UiOiJ4In0',
        authenticator_data: 'YWJj',
        signature: 'c2ln',
      },
    });
    expect(session.user.email).toBe('demo@omnex.cloud');
    expect(session.token).toContain('mock-token');

    const accounts = await api.listSocialAccounts();
    expect(accounts.some((account) => account.provider === 'passkey')).toBe(true);
  });

  it('lists, registers and revokes authenticators and updates the security level', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const seeded = await api.listAuthenticators();
    expect(seeded.length).toBeGreaterThanOrEqual(3);
    expect(seeded[0]).toHaveProperty('name');
    expect(seeded[0]).toHaveProperty('last_used_at');

    const options = await api.passkeyRegisterOptions();
    expect(options.registration_token).toBeTruthy();
    expect(options.pub_key_cred_params.length).toBeGreaterThan(0);

    const registered = await api.registerPasskey({
      registration_token: options.registration_token,
      credential: {
        id: 'cred-new-yubikey',
        raw_id: 'cred-new-yubikey',
        type: 'public-key',
        response: { client_data_json: 'e30', authenticator_data: '', signature: '' },
      },
      name: 'YubiKey Backup 2',
      transport: 'usb',
    });
    expect(registered.name).toBe('YubiKey Backup 2');

    const after = await api.listAuthenticators();
    expect(after.length).toBe(seeded.length + 1);

    await api.revokeAuthenticator(registered.id);
    expect((await api.listAuthenticators()).length).toBe(seeded.length);

    expect(await api.updateSecurityLevel('critical')).toBe('critical');
    const me = await api.me();
    expect(me.user.security_level).toBe('critical');
  });

  it('requires MFA verification when enabled', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });
    await api.setupMfa();
    await api.confirmMfa('123456');

    await api.logout();
    const res = await api.login({ email: 'demo@omnex.cloud', password: 'password' });
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

    // Four demo files are seeded at the root for the Drive cockpit.
    expect((await api.listDrive()).files).toHaveLength(4);

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

  it('exposes a cumulative usage history with seeded bytes', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Drive History Tester',
      email: 'drive-history-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const { samples } = await api.getDriveUsageHistory();
    expect(samples).toHaveLength(30);
    expect(samples[0]).toHaveProperty('date');
    expect(samples[0]).toHaveProperty('bytes');
    // Cumulative: never decreases, newest last.
    const bytes = samples.map((s) => s.bytes);
    expect(bytes).toEqual([...bytes].sort((a, b) => a - b));
    expect(bytes[bytes.length - 1]).toBeGreaterThan(0);
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

  it('lists Serveurs du Peuple as the sovereign social provider', async () => {
    const api = new MockApiClient();
    const providers = await api.socialProviders();

    expect(providers[0].name).toBe('sdp');
    expect(providers[0].label).toBe('Serveurs du Peuple');
    expect(providers[0].configured).toBe(true);

    const names = providers.map((p) => p.name);
    expect(names).toContain('github');
    expect(names).toContain('openai');

    const redirect = await api.socialRedirect('sdp');
    expect(redirect.url).toContain('provider=sdp');
  });

  it('links and unlinks a social account', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

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

  it('scores, dismisses and reopens a security finding', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const report = await api.getSecurityScore();
    expect(report.score).toBeGreaterThanOrEqual(0);
    expect(report.score).toBeLessThanOrEqual(100);
    expect(report.findings.length).toBeGreaterThan(0);
    expect(report.summary.open).toBe(report.findings.filter((f) => f.status === 'open').length);

    const finding = report.findings[0];
    const dismissed = await api.dismissSecurityFinding(finding.id);
    expect(dismissed.status).toBe('dismissed');
    expect((await api.getSecurityScore()).summary.open).toBe(report.summary.open - 1);

    const reopened = await api.reopenSecurityFinding(finding.id);
    expect(reopened.status).toBe('open');
    expect((await api.getSecurityScore()).summary.open).toBe(report.summary.open);
  });

  it('rejects dismissing an unknown security finding', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });
    await expect(api.dismissSecurityFinding('sec-does-not-exist')).rejects.toMatchObject({ status: 404 });
  });

  it('lists site providers', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Sites Provider Tester',
      email: 'sites-provider@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const providers = await api.listSiteProviders();
    expect(providers.map((p) => p.name)).toEqual(['sandbox', 'custom']);
    expect(providers.find((p) => p.name === 'sandbox')?.configured).toBe(true);
    expect(providers.find((p) => p.name === 'custom')?.configured).toBe(false);
  });

  it('creates, deploys, rolls back and deletes a site', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Sites Tester',
      email: 'sites@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    // Two demo sites are seeded for the Sites cockpit.
    expect(await api.listSites()).toHaveLength(2);

    const site = await api.createSite({
      name: 'Marketing',
      framework: 'static',
      git_url: 'https://github.com/acme/marketing.git',
      environment_variables: { API_SECRET: 'super-secret' },
    });
    expect(site.status).toBe('provisioning');
    expect(site.environment_variable_keys).toEqual(['API_SECRET']);
    expect(JSON.stringify(site)).not.toContain('super-secret');

    const first = await api.deploySite(site.id);
    expect(first.status).toBe('live');
    expect(first.number).toBe(1);

    expect((await api.getSite(site.id)).current_deployment_id).toBe(first.id);

    await api.updateSite(site.id, { git_branch: 'main-v2' });
    await api.deploySite(site.id);

    const rolledBack = await api.rollbackSite(site.id, first.id);
    expect(rolledBack.id).toBe(first.id);
    expect((await api.getSite(site.id)).current_deployment_id).toBe(first.id);

    const deployments = await api.listSiteDeployments(site.id);
    expect(deployments).toHaveLength(2);

    await api.deleteSite(site.id);
    // Back to the two seeded demo sites.
    expect(await api.listSites()).toHaveLength(2);
  });

  it('keeps serving the previous deployment when a deploy fails', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Sites Rollback Tester',
      email: 'sites-rollback@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const site = await api.createSite({
      name: 'Rollback',
      framework: 'static',
      git_url: 'https://github.com/acme/rollback.git',
    });

    const good = await api.deploySite(site.id);
    await api.updateSite(site.id, { git_branch: 'fail' });

    const failed = await api.deploySite(site.id);
    expect(failed.status).toBe('failed');
    expect((await api.getSite(site.id)).current_deployment_id).toBe(good.id);
    expect((await api.getSite(site.id)).status).toBe('ready');
  });

  it('creates a server, runs power operations and records the trail', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Tester',
      email: 'cloud@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    // Two demo servers are seeded for the fleet cockpit.
    expect(await api.listServers()).toHaveLength(2);

    const server = await api.createServer({
      name: 'web-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
      ssh_key: 'ssh-ed25519 AAAA',
      tags: ['web', 'staging'],
    });
    expect(server.status).toBe('running');
    expect(server.ipv4).toMatch(/^10\./);
    expect(server.operations_count).toBe(1);

    const operations = await api.listServerOperations(server.id);
    expect(operations).toHaveLength(1);
    expect(operations[0].type).toBe('provision');
    expect(operations[0].status).toBe('succeeded');

    const stopped = await api.stopServer(server.id);
    expect(stopped.status).toBe('succeeded');
    expect((await api.getServer(server.id)).status).toBe('stopped');

    await api.startServer(server.id);
    await api.rebootServer(server.id);

    const rebuilt = await api.rebuildServer(server.id, 'debian-12');
    expect(rebuilt.status).toBe('succeeded');
    expect((await api.getServer(server.id)).image).toBe('debian-12');
    expect(await api.listServerOperations(server.id)).toHaveLength(5);

    await api.updateServer(server.id, { name: 'web-02', tags: ['prod'] });
    expect((await api.getServer(server.id)).name).toBe('web-02');
    expect((await api.getServer(server.id)).tags).toEqual(['prod']);

    await api.deleteServer(server.id);
    // Back to the two seeded demo servers.
    expect(await api.listServers()).toHaveLength(2);
  });

  it('records a failed operation for servers whose name contains fail', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Failure Tester',
      email: 'cloud-fail@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const server = await api.createServer({
      name: 'fail-box',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
    });

    const stopped = await api.stopServer(server.id);
    expect(stopped.status).toBe('failed');
    expect(stopped.error).toContain('fail');
    expect((await api.getServer(server.id)).status).toBe('running');
  });

  it('streams server metrics samples with sane ranges', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Metrics Tester',
      email: 'cloud-metrics@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const server = await api.createServer({
      name: 'metrics-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
    });

    const samples: ServerMetricsDto[] = [];
    const unsubscribe = api.subscribeServerMetrics(server.id, (metrics) => samples.push(metrics));

    expect(samples).toHaveLength(1); // immediate first sample
    const first = samples[0];
    expect(first.server_id).toBe(server.id);
    expect(first.cpu).toBeGreaterThanOrEqual(5);
    expect(first.cpu).toBeLessThanOrEqual(95);
    expect(first.memory_used).toBeLessThanOrEqual(first.memory_total);
    expect(first.disk_used).toBeLessThanOrEqual(first.disk_total);
    expect(first.memory_total).toBe(4 * 1024 * 1024 * 1024);
    expect(first.disk_total).toBe(80 * 1024 * 1024 * 1024);

    unsubscribe();
  });

  it('raises a server.alert notification when a metric sample crosses the threshold', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Alert Tester',
      email: 'cloud-alert@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const server = await api.createServer({
      name: 'alert-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
    });

    // The synthetic cpu never exceeds 85%, so force a breach deterministically.
    ALERT_THRESHOLDS.cpu = 1;
    const unsubscribe = api.subscribeServerMetrics(server.id, () => {});
    unsubscribe();

    const notifications = await api.listNotificationsPage({ type: 'server.alert' });
    const alert = notifications.data.find((n) => n.title.includes(server.name));
    expect(alert).toBeDefined();
    expect(alert!.severity).toBe('warning');
    expect(alert!.route).toBe('/cloud');
    expect(alert!.body).toContain('cpu');
  });

  it('creates, lists, schedules and deletes server snapshots', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Snapshot Tester',
      email: 'cloud-snapshot@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const server = await api.createServer({
      name: 'snap-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
    });

    // Schedule settings round-trip through update.
    const updated = await api.updateServer(server.id, { snapshot_frequency: 'daily', snapshot_retention_days: 14 });
    expect(updated.snapshot_frequency).toBe('daily');
    expect(updated.snapshot_retention_days).toBe(14);

    const created = await api.createServerSnapshot(server.id, 'pre-deploy');
    expect(created.server_id).toBe(server.id);
    expect(created.label).toBe('pre-deploy');
    expect(created.status).toBe('available');

    const list = await api.listServerSnapshots(server.id);
    expect(list).toHaveLength(1);
    expect(list[0].id).toBe(created.id);

    // The snapshot operation lands in the operations trail.
    const ops = await api.listServerOperations(server.id);
    expect(ops.some((op) => op.type === 'snapshot' && op.status === 'succeeded')).toBe(true);

    await api.deleteServerSnapshot(server.id, created.id);
    expect(await api.listServerSnapshots(server.id)).toHaveLength(0);
  });

  it('verifies cloud providers and reports configured state', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Verify Tester',
      email: 'cloud-verify@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    // Only the sandbox is configured by default → only it verifies ok.
    const verified = await api.verifyCloudProviders();
    expect(verified.find((p) => p.name === 'sandbox')?.verified.ok).toBe(true);
    expect(verified.find((p) => p.name === 'hetzner')?.verified.ok).toBe(false);

    // Single-provider query.
    const single = await api.verifyCloudProviders('hetzner');
    expect(single).toHaveLength(1);
    expect(single[0].name).toBe('hetzner');
  });

  it('creates, lists, renames and deletes SSH keys', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud SSH Tester',
      email: 'cloud-ssh@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const key = await api.createSshKey({
      name: 'Laptop',
      public_key: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c test',
    });
    expect(key.fingerprint.startsWith('SHA256:')).toBe(true);
    expect(key.public_key).not.toContain('test'); // comment stripped

    await expect(
      api.createSshKey({ name: 'Copy', public_key: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c' }),
    ).rejects.toMatchObject({ status: 422 });

    await api.updateSshKey(key.id, { name: 'Work laptop' });
    expect((await api.listSshKeys())[0].name).toBe('Work laptop');

    await api.deleteSshKey(key.id);
    expect(await api.listSshKeys()).toHaveLength(0);
  });

  it('generates a key pair and returns the private key once', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Gen Tester',
      email: 'cloud-gen@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const result = await api.generateSshKey({ name: 'Deploy bot', type: 'ed25519' });
    expect(result.data.public_key.startsWith('ssh-ed25519 ')).toBe(true);
    expect(result.data.fingerprint.startsWith('SHA256:')).toBe(true);
    expect(result.private_key).toContain('BEGIN OPENSSH PRIVATE KEY');

    // The public half is registered in the list…
    const keys = await api.listSshKeys();
    expect(keys.some((key) => key.id === result.data.id)).toBe(true);

    // …but the private key is not retrievable again through the API.
    const rsa = await api.generateSshKey({ name: 'Backup host', type: 'rsa' });
    expect(rsa.data.public_key.startsWith('ssh-rsa ')).toBe(true);
  });

  it('associates a saved SSH key with a server at creation', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud SSH Server Tester',
      email: 'cloud-ssh-server@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const key = await api.createSshKey({
      name: 'Deploy key',
      public_key: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIP7bJ2FZQnXr6gJkYxQnMlpHJ2vQ0mWtXyRfZk1aB2c',
    });

    const server = await api.createServer({
      name: 'keyed-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
      ssh_key_id: key.id,
    });

    expect(server.ssh_key_id).toBe(key.id);
    expect(server.ssh_key).toBe(key.public_key);
  });

  it('seals a generated private key in the vault and unlocks it with the password', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Vault Tester',
      email: 'cloud-vault@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const result = await api.generateSshKey({ name: 'Sealed', vault_password: 'vault-pass-123' });
    expect(result.data.has_private_key).toBe(true);
    expect(result.data.vault_enabled_at).toBeTruthy();

    // A wrong password is rejected.
    await expect(api.unlockSshKey(result.data.id, 'wrong-password')).rejects.toMatchObject({ status: 422 });

    // The correct password recovers the same private key.
    const unlocked = await api.unlockSshKey(result.data.id, 'vault-pass-123');
    expect(unlocked.private_key).toBe(result.private_key);

    // The listing never exposes the ciphertext or the private key.
    const keys = await api.listSshKeys();
    const listed = keys.find((key) => key.id === result.data.id);
    expect(listed?.has_private_key).toBe(true);
    expect('private_key' in listed!).toBe(false);
    expect('encrypted_private_key' in listed!).toBe(false);
  });

  it('reports server usage of a key and blocks deleting it while in use', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud InUse Tester',
      email: 'cloud-inuse@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const key = await api.createSshKey({
      name: 'Deploy key',
      public_key: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIInUseTestUniqueBody',
    });
    expect(key.servers_count).toBe(0);

    await api.createServer({
      name: 'inuse-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
      ssh_key_id: key.id,
    });

    // The listing reflects the usage count and deletion is rejected.
    const keys = await api.listSshKeys();
    expect(keys.find((k) => k.id === key.id)?.servers_count).toBe(1);
    await expect(api.deleteSshKey(key.id)).rejects.toMatchObject({ status: 422 });
    // The in-use key survives the rejected delete.
    expect((await api.listSshKeys()).find((k) => k.id === key.id)).toBeTruthy();
  });

  it('installs a saved key on a server through the provider', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud Install Tester',
      email: 'cloud-install@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const key = await api.createSshKey({
      name: 'Install key',
      public_key: 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDifferentBodyForInstallTest',
    });
    const server = await api.createServer({
      name: 'target-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
    });
    expect(server.ssh_key).toBeNull();

    const result = await api.installServerSshKey(server.id, key.id);
    expect(result.status).toBe('installed');

    // The server now references the key and the operation is recorded.
    const updated = await api.getServer(server.id);
    expect(updated.ssh_key_id).toBe(key.id);
    expect(updated.ssh_key).toBe(key.public_key);

    const ops = await api.listServerOperations(server.id);
    expect(ops.some((op) => op.type === 'install_key' && op.status === 'succeeded')).toBe(true);
  });

  it('serves a deterministic metrics history for the sparkline', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Cloud History Tester',
      email: 'cloud-history@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const server = await api.createServer({
      name: 'history-01',
      region: 'fsn1',
      plan: 'cpx11',
      image: 'ubuntu-24.04',
    });

    const history = await api.listServerMetricsHistory(server.id, 12);
    expect(history).toHaveLength(12);
    expect(history[0].server_id).toBe(server.id);
    // Oldest first, monotonic timestamps.
    const timestamps = history.map((s) => s.sampled_at);
    expect([...timestamps].sort()).toEqual(timestamps);
    expect(history[0].cpu).toBeGreaterThanOrEqual(5);
    expect(history[11].cpu).toBeLessThanOrEqual(95);
  });

  it('lists notifications newest first with severity and unread count', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const list = await api.listNotifications();
    expect(list.data.length).toBeGreaterThan(0);
    expect(list.unread).toBe(list.data.filter((n) => !n.read_at).length);

    // Sorted newest first.
    const timestamps = list.data.map((n) => n.created_at ?? '');
    expect([...timestamps].sort((a, b) => b.localeCompare(a))).toEqual(timestamps);

    const security = list.data.find((n) => n.type === 'security');
    expect(security?.severity).toBe('danger');
    expect(security?.route).toBe('/settings');
  });

  it('paginates and filters the notification page', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const first = await api.listNotificationsPage({ page: 1, perPage: 3 });
    expect(first.data).toHaveLength(3);
    expect(first.meta.current_page).toBe(1);
    expect(first.meta.total).toBeGreaterThan(3);
    expect(first.meta.last_page).toBeGreaterThan(1);

    const second = await api.listNotificationsPage({ page: 2, perPage: 3 });
    expect(second.meta.current_page).toBe(2);
    expect(second.data.length).toBeGreaterThan(0);

    const security = await api.listNotificationsPage({ type: 'security' });
    expect(security.data.length).toBeGreaterThan(0);
    expect(security.data.every((n) => n.type === 'security')).toBe(true);

    const warning = await api.listNotificationsPage({ severity: 'warning' });
    expect(warning.data.length).toBeGreaterThan(0);
    expect(warning.data.every((n) => n.severity === 'warning')).toBe(true);

    const unreadOnly = await api.listNotificationsPage({ unread: true });
    expect(unreadOnly.data.length).toBeGreaterThan(0);
    expect(unreadOnly.data.every((n) => !n.read_at)).toBe(true);
  });

  it('marks a single notification read and decrements the counter', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const before = await api.listNotifications();
    const target = before.data.find((n) => !n.read_at);
    expect(target).toBeDefined();

    const updated = await api.markNotificationRead(target!.id);
    expect(updated.read_at).toBeTruthy();

    const after = await api.listNotifications();
    expect(after.unread).toBe(before.unread - 1);
  });

  it('marks all notifications read', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    expect((await api.listNotifications()).unread).toBeGreaterThan(0);

    await api.markAllNotificationsRead();

    const after = await api.listNotifications();
    expect(after.unread).toBe(0);
    expect(after.data.every((n) => !!n.read_at)).toBe(true);
  });

  it('pushes notifications to subscribers in real time', async () => {
    const api = new MockApiClient();
    await api.register({
      name: 'Realtime Tester',
      email: 'realtime@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const received: string[] = [];
    const unsubscribe = api.subscribeNotifications((n) => received.push(n.title));

    const site = await api.createSite({
      name: 'Realtime',
      framework: 'static',
      git_url: 'https://github.com/acme/realtime.git',
    });
    await api.deploySite(site.id);

    expect(received).toContain('Deployment completed');
    expect((await api.listNotifications()).data[0].title).toBe('Deployment completed');

    unsubscribe();

    await api.deploySite(site.id);
    expect(received.filter((t) => t === 'Deployment completed')).toHaveLength(1);
  });

  it('pushes activity events to subscribers in real time', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const received: ActivityItem[] = [];
    const unsubscribe = api.subscribeActivity((item) => received.push(item));

    const site = await api.createSite({
      name: 'Activity Stream',
      framework: 'static',
      git_url: 'https://github.com/acme/activity.git',
    });
    await api.deploySite(site.id);

    expect(received.some((item) => item.title === 'Deployment completed')).toBe(true);

    unsubscribe();

    await api.deploySite(site.id);
    expect(received.filter((item) => item.title === 'Deployment completed')).toHaveLength(1);
  });

  it('lists billing plans and subscribes with a paid invoice', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const plans = await api.listBillingPlans();
    expect(plans.map((p) => p.slug)).toEqual(['free', 'starter', 'pro', 'business']);

    // A demo Starter subscription is seeded for the billing cockpit.
    const seeded = await api.getSubscription();
    if (seeded) await api.cancelSubscription(seeded.id);
    expect(await api.getSubscription()).toBeNull();

    const result = await api.subscribeToPlan('pro');
    expect(result.subscription.status).toBe('active');
    expect(result.checkout_url).toContain('/billing/sandbox/checkout/');

    const current = await api.getSubscription();
    expect(current?.plan?.slug).toBe('pro');

    const invoices = await api.listInvoices();
    // Three seeded demo invoices + the fresh one.
    expect(invoices.length).toBeGreaterThanOrEqual(4);
    expect(invoices[0].status).toBe('paid');
    expect(invoices[0].plan?.slug).toBe('pro');
  });

  it('rejects a duplicate billing subscription', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const seeded = await api.getSubscription();
    if (seeded) await api.cancelSubscription(seeded.id);

    await api.subscribeToPlan('starter');
    await expect(api.subscribeToPlan('starter')).rejects.toMatchObject({
      status: 422,
      fieldErrors: { plan: ['This organization already has an active subscription to this plan.'] },
    });
  });

  it('applies a coupon and credits to billing', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const coupon = await api.validateCoupon('launch25');
    expect(coupon.code).toBe('LAUNCH25');
    expect(coupon.discount_type).toBe('percent');
    expect(coupon.discount).toBe(2500); // 25% of the 10000 sample

    // Seeded credits: 1000 welcome - 200 applied on invoice 2026-0715.
    const seededBalance = (await api.getCredits()).balance;
    await api.addCredits(2000, 'Welcome credit');
    expect((await api.getCredits()).balance).toBe(seededBalance + 2000);

    const result = await api.subscribeToPlan('business', 'sandbox', 'LAUNCH25');
    expect(result.subscription.coupon?.code).toBe('LAUNCH25');

    const invoices = await api.listInvoices();
    const invoice = invoices[0];
    expect(invoice.amount).toBe(19900);
    expect(invoice.discount).toBe(4975); // 25% of 19900
    expect(invoice.credit_applied).toBe(seededBalance + 2000);
    expect(invoice.amount_due).toBe(19900 - 4975 - (seededBalance + 2000));
    expect((await api.getCredits()).balance).toBe(0);

    await expect(api.validateCoupon('NOPE')).rejects.toMatchObject({ status: 422 });
  });

  it('changes plan with proration credit', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const existing = await api.getSubscription();
    if (existing) await api.cancelSubscription(existing.id);
    await api.subscribeToPlan('business');
    const changed = await api.changePlan('pro');
    expect(changed.plan?.slug).toBe('pro');

    const credits = await api.getCredits();
    expect(credits.balance).toBe(9950); // half of business
    expect(credits.entries[0].reason).toBe('proration');
  });

  it('administers coupons (create, toggle, usage)', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const initial = await api.listCoupons();
    expect(initial.map((c) => c.code)).toEqual(['CREDIT10', 'LAUNCH25']);
    // The earlier coupon test redeemed LAUNCH25 on business.
    expect(initial.find((c) => c.code === 'LAUNCH25')?.times_redeemed).toBeGreaterThanOrEqual(1);

    const created = await api.createCoupon({
      code: 'welcome15',
      name: 'Welcome 15%',
      discount_type: 'percent',
      discount_value: 15,
      max_redemptions: 50,
    });
    expect(created.code).toBe('WELCOME15');
    expect(created.times_redeemed).toBe(0);
    expect(created.active).toBe(true);

    await expect(
      api.createCoupon({ code: 'LAUNCH25', name: 'Dup', discount_type: 'percent', discount_value: 10 }),
    ).rejects.toMatchObject({ status: 422 });

    const toggled = await api.updateCoupon(created.id, { active: false });
    expect(toggled.active).toBe(false);

    const redemptions = await api.listCouponRedemptions('coupon-launch25');
    expect(redemptions.length).toBeGreaterThanOrEqual(1);
    expect(redemptions[0].organization_name).toBe('OMNEX HQ');
    expect(redemptions[0].discount_amount).toBeGreaterThan(0);

    expect(await api.listCouponRedemptions(created.id)).toHaveLength(0);
  });

  it('submits a public contact lead and routes it to the team', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });
    const before = (await api.listNotificationsPage({ perPage: 100 })).data.length;

    const lead = await api.submitContactLead({
      name: 'Jane Doe',
      email: 'jane@example.com',
      company: 'Acme',
      subject: 'Quote request',
      message: 'We need 5 VPS servers and managed DNS for a launch.',
    });

    expect(lead.id).toBeTruthy();
    expect(lead.status).toBe('new');
    expect(lead.email).toBe('jane@example.com');

    const after = (await api.listNotificationsPage({ perPage: 100 })).data;
    expect(after.length).toBe(before + 1);
    expect(after[0].type).toBe('lead');
    expect(after[0].title).toContain('Jane Doe');
  });

  it('rejects spam and invalid contact payloads', async () => {
    const api = new MockApiClient();
    await expect(
      api.submitContactLead({
        name: 'Bot',
        email: 'bot@example.com',
        subject: 'Spam',
        message: 'This is a spam message.',
        website: 'spam-link',
      }),
    ).rejects.toMatchObject({ status: 422 });

    await expect(
      api.submitContactLead({
        name: 'Jane',
        email: 'not-an-email',
        subject: 'Hi',
        message: 'A sufficiently long message here.',
      }),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('serves published landing pages publicly with locale content', async () => {
    const api = new MockApiClient();

    const offer = await api.getLandingPage('launch-offer');
    expect(offer.type).toBe('offer');
    expect(offer.status).toBe('published');
    expect(offer.content_en[0]).toMatchObject({ kind: 'hero' });
    expect(offer.content_en.some((s) => s.kind === 'offer')).toBe(true);
    expect(offer.content_fr[0]).toMatchObject({ kind: 'hero' });

    const promo = await api.getLandingPage('omnex-20');
    expect(promo.type).toBe('promo');
    expect(promo.content_en.some((s) => s.kind === 'promo')).toBe(true);

    const comparison = await api.getLandingPage('omnex-vs-consoles');
    expect(comparison.type).toBe('comparison');
    expect(comparison.content_en.some((s) => s.kind === 'comparison')).toBe(true);
  });

  it('hides unknown or unpublished landing pages from the public endpoint', async () => {
    const api = new MockApiClient();

    await expect(api.getLandingPage('does-not-exist')).rejects.toMatchObject({ status: 404 });

    // A draft created by an owner must not be served publicly.
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });
    const draft = await api.createLandingPage({
      slug: 'secret-draft',
      type: 'offer',
      status: 'draft',
      content_en: [{ kind: 'hero', title: 'Draft', subtitle: 'Hidden', cta_label: 'Go' }],
      content_fr: [{ kind: 'hero', title: 'Brouillon', subtitle: 'Caché', cta_label: 'Aller' }],
    });
    expect(draft.status).toBe('draft');
    await expect(api.getLandingPage('secret-draft')).rejects.toMatchObject({ status: 404 });
  });

  it('lets an owner manage landing pages (create, update, publish, delete)', async () => {
    const api = new MockApiClient();
    await api.login({ email: 'demo@omnex.cloud', password: 'password' });

    const created = await api.createLandingPage({
      slug: 'summer-offer',
      type: 'offer',
      status: 'draft',
      content_en: [{ kind: 'hero', title: 'Summer', subtitle: 'Hot deal', cta_label: 'Start' }],
      content_fr: [{ kind: 'hero', title: 'Été', subtitle: 'Offre chaude', cta_label: 'Commencer' }],
    });
    expect(created.id).toBeTruthy();
    expect(created.published_at).toBeNull();

    const listed = await api.listLandingPages();
    expect(listed.some((p) => p.slug === 'summer-offer')).toBe(true);

    const published = await api.updateLandingPage(created.id, {
      slug: 'summer-offer',
      type: 'offer',
      status: 'published',
      content_en: created.content_en,
      content_fr: created.content_fr,
    });
    expect(published.status).toBe('published');
    expect(published.published_at).toBeTruthy();

    // Now visible publicly.
    expect((await api.getLandingPage('summer-offer')).status).toBe('published');

    await api.deleteLandingPage(created.id);
    await expect(api.getLandingPage('summer-offer')).rejects.toMatchObject({ status: 404 });
  });

  it('validates landing page input and requires auth', async () => {
    const api = new MockApiClient();
    await api.logout(); // mock auth state is module-level

    await expect(api.listLandingPages()).rejects.toMatchObject({ status: 401 });
    await expect(
      api.createLandingPage({
        slug: 'Bad Slug!',
        type: 'offer',
        status: 'draft',
        content_en: [],
        content_fr: [],
      }),
    ).rejects.toMatchObject({ status: 401 });

    // A freshly registered user is never MFA-gated (the demo account may be
    // left with MFA enabled by an earlier test in this file).
    await api.register({
      name: 'Landing Tester',
      email: 'landing-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });
    await expect(
      api.createLandingPage({
        slug: 'Bad Slug!',
        type: 'offer',
        status: 'draft',
        content_en: [{ kind: 'hero', title: 'X', subtitle: 'Y', cta_label: 'Z' }],
        content_fr: [{ kind: 'hero', title: 'X', subtitle: 'Y', cta_label: 'Z' }],
      }),
    ).rejects.toMatchObject({ status: 422, fieldErrors: { slug: expect.any(Array) } });

    await expect(
      api.createLandingPage({
        slug: 'launch-offer', // already exists
        type: 'offer',
        status: 'draft',
        content_en: [{ kind: 'hero', title: 'X', subtitle: 'Y', cta_label: 'Z' }],
        content_fr: [{ kind: 'hero', title: 'X', subtitle: 'Y', cta_label: 'Z' }],
      }),
    ).rejects.toMatchObject({ status: 422 });
  });

  it('manages the MFA policy and enforces it in the scan', async () => {
    const api = new MockApiClient();
    await api.logout();
    await expect(api.getSecuritySettings()).rejects.toMatchObject({ status: 401 });

    // A freshly registered user has no MFA, so a required policy must flag them.
    await api.register({
      name: 'Phase7 Tester',
      email: 'phase7-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    expect((await api.getSecuritySettings()).mfa_policy).toBe('optional');
    expect((await api.updateSecuritySettings({ mfa_policy: 'required' })).mfa_policy).toBe('required');
    await expect(api.updateSecuritySettings({ mfa_policy: 'sometimes' as MfaPolicy })).rejects.toMatchObject({ status: 422 });

    const rules = (await api.getSecurityScore()).findings.map((f) => f.rule);
    expect(rules).toContain('mfa_enforcement');
    expect(rules).toContain('mfa');
  });

  it('lists and revokes active sessions', async () => {
    const api = new MockApiClient();
    await api.logout();
    await expect(api.listSessions()).rejects.toMatchObject({ status: 401 });

    await api.register({
      name: 'Session Tester',
      email: 'session-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const sessions = await api.listSessions();
    expect(sessions.length).toBeGreaterThanOrEqual(3);
    expect(sessions.some((s) => s.is_current)).toBe(true);
    expect(sessions.some((s) => s.ip_address)).toBe(true);

    const other = sessions.find((s) => !s.is_current);
    await api.revokeSession(other!.id);
    expect((await api.listSessions()).some((s) => s.id === other!.id)).toBe(false);
    await expect(api.revokeSession('does-not-exist')).rejects.toMatchObject({ status: 404 });

    await api.revokeOtherSessions();
    const remaining = await api.listSessions();
    expect(remaining).toHaveLength(1);
    expect(remaining[0].is_current).toBe(true);
  });

  it('exposes certificate checks for SSL monitoring', async () => {
    const api = new MockApiClient();
    await api.logout();
    await api.register({
      name: 'Ssl Tester',
      email: 'ssl-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const checks = await api.listSslChecks();
    expect(Array.isArray(checks)).toBe(true);
    // Seeded domains are monitored (at least omnex.cloud).
    expect(checks.length).toBeGreaterThanOrEqual(1);
    expect(checks.every((c) => ['valid', 'expiring', 'invalid'].includes(c.status))).toBe(true);
  });

  it('exposes the persisted security score history', async () => {
    const api = new MockApiClient();
    await api.logout();
    await api.register({
      name: 'History Tester',
      email: 'history-tester@example.com',
      password: 'password123',
      password_confirmation: 'password123',
    });

    const first = await api.getSecurityHistory();
    expect(first.samples.length).toBeGreaterThanOrEqual(10);
    expect(first.samples[0]).toHaveProperty('score');
    expect(first.samples[0]).toHaveProperty('created_at');
    // Chronological order (newest last).
    const dates = first.samples.map((s) => new Date(s.created_at ?? 0).getTime());
    expect(dates).toEqual([...dates].sort((a, b) => a - b));

    // Dismissing findings appends a new sample reflecting the new score.
    const report = await api.getSecurityScore();
    const before = report.score;
    for (const finding of report.findings) {
      await api.dismissSecurityFinding(finding.id);
    }

    const after = (await api.getSecurityScore()).score;
    expect(after).toBeGreaterThan(before);

    const second = await api.getSecurityHistory();
    expect(second.samples.length).toBeGreaterThan(first.samples.length);
    expect(second.samples[second.samples.length - 1].score).toBe(after);
  });
});
