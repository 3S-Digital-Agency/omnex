import type { LucideIcon } from 'lucide-react';
import {
  Activity,
  CreditCard,
  Globe,
  HardDrive,
  LayoutTemplate,
  Server,
  ShieldCheck,
} from 'lucide-react';

export interface ModuleDefinition {
  id: string;
  name: string;
  path: string;
  icon: LucideIcon;
  tagline: string;
  description: string;
  phase: string;
  capabilities: string[];
  live: boolean;
}

export const activityModule: ModuleDefinition = {
  id: 'activity',
  name: 'Activity',
  path: '/activity',
  icon: Activity,
  tagline: 'Live event stream',
  description: 'Everything happening in your organization, in real time.',
  phase: 'Phase 2',
  capabilities: [],
  live: true,
};

export const modules: ModuleDefinition[] = [
  activityModule,
  {
    id: 'domains',
    name: 'Domains',
    path: '/domains',
    icon: Globe,
    tagline: 'OMNEX Domain Engine',
    description: 'Search, register, renew and transfer domains — with DNS, history and rollback.',
    phase: 'Phase 3',
    capabilities: ['Domain search & registration', 'DNS records, templates & rollback', 'Renewals & auto-renew', 'Privacy, locking & nameservers'],
    live: true,
  },
  {
    id: 'sites',
    name: 'Sites',
    path: '/sites',
    icon: LayoutTemplate,
    tagline: 'OMNEX Sites',
    description: 'Deploy sites from Git with staging, preview and rollback.',
    phase: 'Phase 5',
    capabilities: ['Git deployment', 'Staging & production', 'Environment variables', 'SSL, logs, rollback'],
    live: true,
  },
  {
    id: 'cloud',
    name: 'Cloud',
    path: '/cloud',
    icon: Server,
    tagline: 'OMNEX Cloud',
    description: 'VPS, containers, databases and networking — provider-agnostic.',
    phase: 'Phase 8',
    capabilities: ['VPS provisioning', 'SSH keys & firewall', 'Snapshots & backups', 'Metrics'],
    live: false,
  },
  {
    id: 'storage',
    name: 'Storage',
    path: '/storage',
    icon: HardDrive,
    tagline: 'OMNEX Drive',
    description: 'Your own Cloud Storage on S3-compatible providers.',
    phase: 'Phase 4',
    capabilities: ['Upload & download', 'Folders & sharing', 'Versioning & trash', 'Search & previews'],
    live: true,
  },
  {
    id: 'security',
    name: 'Security',
    path: '/security',
    icon: ShieldCheck,
    tagline: 'OMNEX Security',
    description: 'Security Score, findings and remediation for your whole estate.',
    phase: 'Phase 7',
    capabilities: ['Security Score', 'MFA enforcement', 'SSL & vulnerability monitoring', 'Remediation actions'],
    live: true,
  },
  {
    id: 'billing',
    name: 'Billing',
    path: '/billing',
    icon: CreditCard,
    tagline: 'OMNEX Billing',
    description: 'Plans, subscriptions, invoices and payments — provider-agnostic.',
    phase: 'Phase 6',
    capabilities: ['Plans & subscriptions', 'Invoices & taxes', 'Coupons & credits', 'Stripe (sandbox first)'],
    live: true,
  },
];

export function moduleById(id: string): ModuleDefinition {
  return modules.find((module) => module.id === id) ?? modules[0];
}
