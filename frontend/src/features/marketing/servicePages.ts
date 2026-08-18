import type { LucideIcon } from 'lucide-react';
import { CreditCard, Globe, HardDrive, LayoutTemplate, Server, ShieldCheck } from 'lucide-react';

export interface ServiceContent {
  metaTitle: string;
  metaDescription: string;
  heroTitle: string;
  intro: string;
  features: { title: string; desc: string }[];
  caps: string[];
  ctaLabel: string;
}

export interface ServiceDefinition {
  id: string;
  icon: LucideIcon;
  en: ServiceContent;
  fr: ServiceContent;
}

export const services: ServiceDefinition[] = [
  {
    id: 'domains',
    icon: Globe,
    en: {
      metaTitle: 'Domains & DNS Management — OMNEX',
      metaDescription:
        'Search, register, renew and transfer domains with managed DNS, DNSSEC, templates and rollback — all from one control plane.',
      heroTitle: 'Domains & DNS, managed end to end',
      intro:
        'Register domains and run your DNS from a single control plane. Every domain provisions a validated, versioned DNS zone automatically — with DNSSEC, templates and instant rollback.',
      features: [
        { title: 'Register in seconds', desc: 'Search availability across TLDs, register with privacy protection and get a DNS zone provisioned automatically.' },
        { title: 'Powerful managed DNS', desc: 'A, AAAA, CNAME, MX, TXT, NS, SRV and CAA records with validation, templates, immutable history and rollback.' },
        { title: 'DNSSEC & propagation', desc: 'Sign your zones, publish DS records and monitor propagation across nameservers in real time.' },
      ],
      caps: ['Domain search & registration', 'DNS records, templates & rollback', 'Renewals & auto-renew', 'Privacy, locking & nameservers', 'Cloudflare-managed DNS & CDN proxy'],
      ctaLabel: 'Register your first domain',
    },
    fr: {
      metaTitle: 'Domaines & gestion DNS — OMNEX',
      metaDescription:
        'Recherchez, enregistrez, renouvelez et transférez des domaines avec DNS géré, DNSSEC, modèles et restauration — depuis un seul plan de contrôle.',
      heroTitle: 'Domaines & DNS, gérés de bout en bout',
      intro:
        'Enregistrez des domaines et gérez votre DNS depuis un seul plan de contrôle. Chaque domaine provisionne automatiquement une zone DNS validée et versionnée — avec DNSSEC, modèles et restauration instantanée.',
      features: [
        { title: 'Enregistrement en quelques secondes', desc: 'Vérifiez la disponibilité sur de nombreux TLD, enregistrez avec protection de la vie privée et obtenez une zone DNS provisionnée automatiquement.' },
        { title: 'DNS géré puissant', desc: 'Enregistrements A, AAAA, CNAME, MX, TXT, NS, SRV et CAA avec validation, modèles, historique immuable et restauration.' },
        { title: 'DNSSEC & propagation', desc: 'Signez vos zones, publiez les enregistrements DS et surveillez la propagation entre serveurs de noms en temps réel.' },
      ],
      caps: ['Recherche et enregistrement de domaines', 'Enregistrements DNS, modèles et restauration', 'Renouvellements et renouvellement auto', 'Confidentialité, verrouillage et serveurs de noms', 'DNS géré Cloudflare et proxy CDN'],
      ctaLabel: 'Enregistrer votre premier domaine',
    },
  },
  {
    id: 'cloud',
    icon: Server,
    en: {
      metaTitle: 'Cloud Servers (VPS) — OMNEX',
      metaDescription:
        'Provision and manage VPS servers on Hetzner, DigitalOcean or your own provider — SSH keys, snapshots, live metrics and alerts in one place.',
      heroTitle: 'Cloud servers, provider-agnostic',
      intro:
        'Provision and manage VPS servers on Hetzner, DigitalOcean or a custom gateway — all behind one interface. SSH keys, snapshots, live metrics and threshold alerts, without vendor lock-in.',
      features: [
        { title: 'Provider-agnostic VPS', desc: 'Provision on Hetzner, DigitalOcean or a custom gateway. Switch providers without rewriting your setup.' },
        { title: 'SSH keys & encrypted vault', desc: 'Manage reusable SSH keys, generate pairs, and seal private keys in an encrypted vault with a passphrase.' },
        { title: 'Snapshots & live metrics', desc: 'Scheduled backups with retention, live CPU/RAM/disk metrics and threshold alerts straight to your notifications.' },
      ],
      caps: ['VPS provisioning', 'SSH keys & firewall', 'Snapshots & backups', 'Live metrics & alerts'],
      ctaLabel: 'Provision a server',
    },
    fr: {
      metaTitle: 'Serveurs cloud (VPS) — OMNEX',
      metaDescription:
        'Provisionnez et gérez des serveurs VPS chez Hetzner, DigitalOcean ou votre propre fournisseur — clés SSH, instantanés, métriques en direct et alertes au même endroit.',
      heroTitle: 'Serveurs cloud, indépendants du fournisseur',
      intro:
        'Provisionnez et gérez des serveurs VPS chez Hetzner, DigitalOcean ou une passerelle personnalisée — derrière une seule interface. Clés SSH, instantanés, métriques en direct et alertes de seuil, sans verrouillage fournisseur.',
      features: [
        { title: 'VPS indépendant du fournisseur', desc: 'Provisionnez chez Hetzner, DigitalOcean ou une passerelle personnalisée. Changez de fournisseur sans réécrire votre configuration.' },
        { title: 'Clés SSH & coffre chiffré', desc: 'Gérez des clés SSH réutilisables, générez des paires et scellez les clés privées dans un coffre chiffré avec mot de passe.' },
        { title: 'Instantanés & métriques en direct', desc: 'Sauvegardes planifiées avec rétention, métriques CPU/RAM/disque en direct et alertes de seuil dans vos notifications.' },
      ],
      caps: ['Provisionnement VPS', 'Clés SSH et pare-feu', 'Instantanés et sauvegardes', 'Métriques et alertes en direct'],
      ctaLabel: 'Provisionner un serveur',
    },
  },
  {
    id: 'sites',
    icon: LayoutTemplate,
    en: {
      metaTitle: 'Websites & Deployments — OMNEX',
      metaDescription:
        'Deploy sites from Git with staging, preview, environment variables, SSL, logs and automatic rollback — in a few clicks.',
      heroTitle: 'Websites, deployed from Git',
      intro:
        'Deploy static and dynamic sites from any Git repository. Staging, preview and production environments, encrypted secrets, build logs and automatic rollback when a deploy fails.',
      features: [
        { title: 'Git-powered deploys', desc: 'Connect a repository and ship to staging or production in a few clicks.' },
        { title: 'Rollback by default', desc: 'Every deploy is reversible. A failed build automatically rolls back to the last live release.' },
        { title: 'Secrets that stay secret', desc: 'Environment variables are encrypted at rest and never returned by the API.' },
      ],
      caps: ['Git deployment', 'Staging & production', 'Environment variables (encrypted)', 'SSL, logs & rollback', 'Cloudflare Pages hosting (projects & deploys)'],
      ctaLabel: 'Deploy your first site',
    },
    fr: {
      metaTitle: 'Sites web & déploiements — OMNEX',
      metaDescription:
        'Déployez des sites depuis Git avec staging, aperçu, variables d’environnement, SSL, journaux et restauration automatique — en quelques clics.',
      heroTitle: 'Sites web, déployés depuis Git',
      intro:
        'Déployez des sites statiques et dynamiques depuis n’importe quel dépôt Git. Environnements de staging, aperçu et production, secrets chiffrés, journaux de build et restauration automatique en cas d’échec.',
      features: [
        { title: 'Déploiements pilotés par Git', desc: 'Connectez un dépôt et publiez en staging ou en production en quelques clics.' },
        { title: 'Restauration par défaut', desc: 'Chaque déploiement est réversible. Un build en échec restaure automatiquement la dernière version en ligne.' },
        { title: 'Des secrets qui restent secrets', desc: 'Les variables d’environnement sont chiffrées au repos et jamais renvoyées par l’API.' },
      ],
      caps: ['Déploiement Git', 'Staging et production', 'Variables d’environnement (chiffrées)', 'SSL, journaux et restauration', 'Hébergement Cloudflare Pages (projets et déploiements)'],
      ctaLabel: 'Déployer votre premier site',
    },
  },
  {
    id: 'storage',
    icon: HardDrive,
    en: {
      metaTitle: 'Cloud Storage (OMNEX Drive) — OMNEX',
      metaDescription:
        'Your own S3-compatible cloud storage: upload, share, version, trash and search — sovereign and provider-agnostic.',
      heroTitle: 'Cloud storage you actually own',
      intro:
        'OMNEX Drive is your own S3-compatible cloud storage. Upload, share, version and search your files — while keeping full control over where they live.',
      features: [
        { title: 'Sovereign storage', desc: 'OMNEX owns its storage abstraction — no third-party engine becomes the system of record.' },
        { title: 'Versions & trash', desc: 'Every file keeps its history and can be restored from the trash at any time.' },
        { title: 'S3-compatible', desc: 'Plug in S3, R2, OVH or MinIO providers behind a single interface.' },
      ],
      caps: ['Upload & download', 'Folders & sharing', 'Versioning & trash', 'Search & previews'],
      ctaLabel: 'Create your Drive',
    },
    fr: {
      metaTitle: 'Stockage cloud (OMNEX Drive) — OMNEX',
      metaDescription:
        'Votre propre stockage cloud compatible S3 : envoi, partage, versions, corbeille et recherche — souverain et indépendant du fournisseur.',
      heroTitle: 'Un stockage cloud que vous possédez vraiment',
      intro:
        'OMNEX Drive est votre propre stockage cloud compatible S3. Envoyez, partagez, versionnez et recherchez vos fichiers — tout en gardant le contrôle total sur leur emplacement.',
      features: [
        { title: 'Stockage souverain', desc: 'OMNEX possède son abstraction de stockage — aucun moteur tiers ne devient le système de référence.' },
        { title: 'Versions et corbeille', desc: 'Chaque fichier conserve son historique et peut être restauré depuis la corbeille à tout moment.' },
        { title: 'Compatible S3', desc: 'Branchez des fournisseurs S3, R2, OVH ou MinIO derrière une interface unique.' },
      ],
      caps: ['Envoi et téléchargement', 'Dossiers et partage', 'Versions et corbeille', 'Recherche et aperçus'],
      ctaLabel: 'Créer votre Drive',
    },
  },
  {
    id: 'security',
    icon: ShieldCheck,
    en: {
      metaTitle: 'Security Center — OMNEX',
      metaDescription:
        'A live Security Score, findings with remediation, MFA, RBAC and an immutable audit log across your whole digital estate.',
      heroTitle: 'Security that measures itself',
      intro:
        'A live Security Score across your entire estate, with findings that explain impact and exact remediation steps. MFA, RBAC with least privilege and an immutable audit log — by default.',
      features: [
        { title: 'Live Security Score', desc: 'A single number that measures your infrastructure and drops the moment something is wrong.' },
        { title: 'Actionable findings', desc: 'Each finding explains its impact and the exact remediation step to close it.' },
        { title: 'Zero-trust by default', desc: 'Multi-tenant isolation, RBAC with least privilege, MFA and an immutable audit trail.' },
      ],
      caps: ['Security Score', 'MFA enforcement', 'SSL & vulnerability monitoring', 'Remediation actions'],
      ctaLabel: 'Scan your estate',
    },
    fr: {
      metaTitle: 'Centre de sécurité — OMNEX',
      metaDescription:
        'Un Score de sécurité en direct, des constats avec remédiation, MFA, RBAC et un journal d’audit immuable sur tout votre parc numérique.',
      heroTitle: 'Une sécurité qui se mesure elle-même',
      intro:
        'Un Score de sécurité en direct sur tout votre parc, avec des constats qui expliquent l’impact et les étapes exactes de remédiation. MFA, RBAC au moindre privilège et journal d’audit immuable — par défaut.',
      features: [
        { title: 'Score de sécurité en direct', desc: 'Un nombre unique qui mesure votre infrastructure et baisse dès qu’un problème apparaît.' },
        { title: 'Constats actionnables', desc: 'Chaque constat explique son impact et l’étape exacte de remédiation pour le clôturer.' },
        { title: 'Zéro confiance par défaut', desc: 'Isolation multi-locataires, RBAC au moindre privilège, MFA et piste d’audit immuable.' },
      ],
      caps: ['Score de sécurité', 'Application de la MFA', 'Surveillance SSL et des vulnérabilités', 'Actions de remédiation'],
      ctaLabel: 'Analyser votre parc',
    },
  },
  {
    id: 'billing',
    icon: CreditCard,
    en: {
      metaTitle: 'Billing & Subscriptions — OMNEX',
      metaDescription:
        'Plans, subscriptions, invoices, coupons and credits — Stripe-powered, provider-agnostic, with automatic renewals.',
      heroTitle: 'Billing that runs itself',
      intro:
        'Plans, subscriptions, invoices, coupons and credits — powered by Stripe behind a clean provider interface, with automatic renewals and proration when you change plans.',
      features: [
        { title: 'One checkout, everywhere', desc: 'Subscribe, upgrade or downgrade with proration and automatic renewals.' },
        { title: 'Coupons & credits', desc: 'Create promotion codes and manage a credit ledger applied automatically to invoices.' },
        { title: 'Stripe-first', desc: 'Hosted Checkout Sessions with HMAC-verified webhooks for reliable payment state.' },
      ],
      caps: ['Plans & subscriptions', 'Invoices & taxes', 'Coupons & credits', 'Stripe (sandbox first)'],
      ctaLabel: 'Explore the plans',
    },
    fr: {
      metaTitle: 'Facturation & abonnements — OMNEX',
      metaDescription:
        'Offres, abonnements, factures, coupons et crédits — propulsés par Stripe, indépendants du fournisseur, avec renouvellements automatiques.',
      heroTitle: 'Une facturation qui tourne toute seule',
      intro:
        'Offres, abonnements, factures, coupons et crédits — propulsés par Stripe derrière une interface de fournisseur propre, avec renouvellements automatiques et prorata lors d’un changement d’offre.',
      features: [
        { title: 'Un seul checkout, partout', desc: 'Abonnez-vous, montez ou descendez en gamme avec prorata et renouvellements automatiques.' },
        { title: 'Coupons et crédits', desc: 'Créez des codes promotionnels et gérez un registre de crédits appliqué automatiquement aux factures.' },
        { title: 'Stripe d’abord', desc: 'Sessions de paiement hébergées avec webhooks vérifiés HMAC pour un état de paiement fiable.' },
      ],
      caps: ['Offres et abonnements', 'Factures et taxes', 'Coupons et crédits', 'Stripe (sandbox d’abord)'],
      ctaLabel: 'Découvrir les offres',
    },
  },
];

export function serviceById(id: string): ServiceDefinition | undefined {
  return services.find((service) => service.id === id);
}
