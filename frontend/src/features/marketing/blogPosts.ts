/**
 * OMNEX Blog / content hub — typed bilingual content.
 *
 * Posts follow the same static-content pattern as `servicePages.ts`: shared
 * metadata (slug, category, dates, tags, author) plus full `en` / `fr`
 * content per post. Rendering lives in `BlogPage.tsx` (index) and
 * `BlogPostPage.tsx` (article), with Article JSON-LD, hreflang and canonical
 * URLs handled by the shared marketing SEO helpers.
 */

export type BlogCategory = 'guide' | 'news' | 'case';

export interface BlogSection {
  heading?: string;
  paragraphs: string[];
  list?: string[];
}

export interface BlogPostContent {
  title: string;
  metaTitle: string;
  metaDescription: string;
  excerpt: string;
  intro: string;
  sections: BlogSection[];
}

export interface BlogPost {
  slug: string;
  category: BlogCategory;
  tags: string[];
  /** ISO date — newest first on the hub. */
  date: string;
  author: { name: string; role: string };
  featured?: boolean;
  en: BlogPostContent;
  fr: BlogPostContent;
}

export const blogPosts: BlogPost[] = [
  {
    slug: 'sovereign-cloud-explained',
    category: 'guide',
    tags: ['cloud', 'sovereignty', 'provider-agnostic'],
    date: '2026-08-10',
    author: { name: 'OMNEX Engineering', role: 'OMNEX' },
    featured: true,
    en: {
      title: 'Sovereign cloud, explained: why your infrastructure should be provider-agnostic',
      metaTitle: 'Sovereign Cloud Explained — OMNEX Blog',
      metaDescription:
        'What sovereign cloud really means, why provider lock-in is a risk, and how a provider-agnostic control plane keeps your infrastructure yours.',
      excerpt:
        'Sovereign cloud is not about where the server sits — it is about who holds the keys. Here is what it really means and how to design for it.',
      intro:
        'Every year another incident reminds us that “the cloud” is not a place; it is a set of contracts. Sovereign cloud is the discipline of keeping those contracts on your side of the table.',
      sections: [
        {
          heading: 'What sovereignty really means',
          paragraphs: [
            'Sovereignty is the ability to decide where your data lives, who can read it under which law, and which provider delivers which service. It does not mean refusing public clouds — it means owning the abstraction layer so that no single vendor becomes the system of record.',
            'The practical test is simple: if your provider raises prices, changes terms or shuts down, how many days of work does it take to leave?',
          ],
          list: [
            'Can you export every object your system stores?',
            'Can you re-provision your compute elsewhere without rewriting code?',
            'Is your DNS zone portable (BIND format, DNSSEC)?',
          ],
        },
        {
          heading: 'Provider-agnostic by architecture',
          paragraphs: [
            'Provider interfaces — registrars, compute, object storage, payments — turn a vendor into a detail. A deployment that runs on Hetzner today can run on DigitalOcean tomorrow, because the platform speaks to a contract, not to a specific endpoint.',
            'That is what OMNEX was built around: every provider sits behind a clean interface, and switching is a configuration change, not a rewrite.',
          ],
        },
        {
          heading: 'Where to start',
          paragraphs: [
            'Begin with the system of record: identify where your irreversible state lives (storage, DNS, billing) and make sure each one can be exported and re-imported. Then automate a portable provisioning story. Sovereignty is a property of the design, not a purchase.',
          ],
        },
      ],
    },
    fr: {
      title: 'Le cloud souverain expliqué : rendre son infrastructure indépendante du fournisseur',
      metaTitle: 'Le cloud souverain expliqué — Blog OMNEX',
      metaDescription:
        'Ce que le cloud souverain signifie vraiment, pourquoi le verrouillage fournisseur est un risque, et comment une architecture indépendante garde votre infrastructure à vous.',
      excerpt:
        'Le cloud souverain n’est pas une question d’emplacement — c’est une question de clés. Voici ce que cela signifie vraiment.',
      intro:
        'Chaque année, une panne le rappelle : « le cloud » n’est pas un lieu, c’est un ensemble de contrats. Le cloud souverain est la discipline qui garde ces contrats de votre côté de la table.',
      sections: [
        {
          heading: 'Ce que la souveraineté signifie vraiment',
          paragraphs: [
            'La souveraineté, c’est la capacité de décider où vivent vos données, qui peut les lire sous quelle loi, et quel fournisseur exécute quel service. Cela ne veut pas dire fuir les clouds publics : cela veut dire posséder l’abstraction pour qu’aucun fournisseur ne devienne le système de référence.',
            'Le test est simple : si votre fournisseur augmente ses prix, change ses conditions ou ferme, combien de jours de travail faut-il pour partir ?',
          ],
          list: [
            'Pouvez-vous exporter chaque objet que votre système stocke ?',
            'Pouvez-vous reprovisionner votre environnement ailleurs sans réécrire le code ?',
            'Votre zone DNS est-elle portable (fichier BIND, DNSSEC) ?',
          ],
        },
        {
          heading: 'Indépendant par architecture',
          paragraphs: [
            'Les interfaces de fournisseur — registraires, calcul, stockage objet, paiements — transforment un vendeur en détail. Un environnement qui tourne chez Hetzner devient portable chez DigitalOcean, sans réécriture : la plateforme parle à un contrat, pas à un point de terminaison particulier.',
            'C’est exactement sur ce principe qu’OMNEX construit : chaque fournisseur derrière une interface propre, le changement de fournisseur devient une configuration, pas une réécriture.',
          ],
        },
        {
          heading: 'Par où commencer',
          paragraphs: [
            'Commencez par le système de référence : identifiez où vit votre état irréversible (stockage, DNS, paiements) et assurez-vous de pouvoir exporter et réimporter chacun. Automatisez ensuite un provisionnement portable. La souveraineté est une propriété de la conception, pas un achat.',
          ],
        },
      ],
    },
  },
  {
    slug: 'web-authn-passkeys-guide',
    category: 'guide',
    tags: ['webauthn', 'passkeys', 'mfa', 'security'],
    date: '2026-08-02',
    author: { name: 'OMNEX Engineering', role: 'OMNEX' },
    en: {
      title: 'Passkeys and WebAuthn in 2026: the practical guide for infrastructure teams',
      metaTitle: 'Passkeys & WebAuthn Guide — OMNEX Blog',
      metaDescription:
        'How WebAuthn works under the hood, why passkeys kill phishing, cross-device sign-in with QR codes (Face ID, fingerprint) and how to deploy them.',
      excerpt:
        'Passkeys are not a feature — they are a security control that removes passwords from the attack surface. Here is how they work.',
      intro:
        'FIDO2 and WebAuthn have quietly become the strongest widely available authentication standard. For infrastructure teams, they turn “the user remembers a secret” into “the platform verifies a cryptographic signature”.',
      sections: [
        {
          heading: 'What happens when you sign in with a passkey',
          paragraphs: [
            'The browser generates a fresh key pair inside your authenticator — a security key, Windows Hello, or your phone. The private key never leaves the device; the service stores only the public key.',
            'On sign-in, the authenticator signs a fresh challenge. The service verifies the signature against the public key it holds. There is no password to leak, no secret travelling over the network.',
          ],
        },
        {
          heading: 'Why “phishing-resistant” actually means something',
          paragraphs: [
            'Because the signature is bound to the origin and a single-use challenge, a credential minted for omnex.cloud cannot be replayed on a lookalike domain. Classic credential-phishing has no lever here.',
          ],
          list: [
            'Enrolment verifies a real attestation (packed / fido-u2f / none) over authData and the challenge',
            'Sign-in verifies the ES256/RS256 signature plus a strictly increasing sign counter (anti-replay)',
            'Cross-device: QR code + 8-character pairing code — approve on the phone with Face ID or fingerprint',
          ],
        },
        {
          heading: 'Rolling it out without breaking anyone',
          paragraphs: [
            'Run parallel: keep passwords one more release, add passkeys, then make MFA mandatory and finally require a passkey for critical actions. Unknown-device detection — a single-use e-mailed code when a new phone signs in — closes the last gap.',
          ],
        },
      ],
    },
    fr: {
      title: 'Passkeys et WebAuthn en 2026 : le guide des équipes d’infrastructure',
      metaTitle: 'Guide Passkeys et WebAuthn — Blog OMNEX',
      metaDescription:
        'Comment WebAuthn fonctionne, pourquoi les passkeys éliminent le phishing, la connexion inter-appareils par QR code (Face ID, empreinte) et comment les déployer.',
      excerpt:
        'Les passkeys ne sont pas une fonctionnalité : ce sont des contrôles de sécurité qui retirent les mots de passe de la surface d’attaque.',
      intro:
        'FIDO2 et WebAuthn sont discrètement devenus le standard d’authentification le plus robuste largement disponible. Pour les équipes d’infrastructure, ils transforment « l’utilisateur retient un secret » en « la plateforme vérifie une signature cryptographique ».',
      sections: [
        {
          heading: 'Ce qui se passe à la connexion par passkey',
          paragraphs: [
            'Le navigateur génère une nouvelle paire de clés dans votre authentificateur — clé de sécurité, Windows Hello ou votre téléphone. La clé privée ne quitte jamais l’appareil ; la plateforme ne stocke que la clé publique.',
            'À la connexion, l’authentificateur signe un défi frais. La plateforme vérifie la signature avec la clé publique qu’elle détient. Plus de mot de passe à voler, plus de secret qui transite sur le réseau.',
          ],
        },
        {
          heading: 'Pourquoi « résistant au phishing » a du sens',
          paragraphs: [
            'Parce que la signature est liée à l’origine et à un défi à usage unique, une signature obtenue pour omnex.cloud ne peut pas être rejouée sur un domaine ambigu. Le credential stuffing classique n’a aucune prise ici.',
          ],
          list: [
            'Enregistrement : vérification d’une vraie attestation (packed / fido-u2f / none)',
            'Connexion : vérification de la signature ES256/RS256 et d’un compteur strictement croissant (anti-rejeu)',
            'Inter-appareils : QR code + code d’appairage à 8 caractères — approbation au téléphone par Face ID ou empreinte',
          ],
        },
        {
          heading: 'Déployer sans rien casser',
          paragraphs: [
            'Commencez en parallèle : gardez les mots de passe une édition, ajoutez les passkeys, rendez ensuite la MFA obligatoire, puis exigez une passkey pour la validation. Et la détection d’appareil inconnu — code à usage unique reçu par e-mail lorsqu’un nouveau téléphone se connecte — ferme la boucle.',
          ],
        },
      ],
    },
  },
  {
    slug: 'dns-dnssec-beginners',
    category: 'guide',
    tags: ['dns', 'dnssec', 'domains'],
    date: '2026-07-21',
    author: { name: 'OMNEX Engineering', role: 'OMNEX' },
    en: {
      title: 'DNS, propagation and DNSSEC for absolute beginners',
      metaTitle: 'DNS and DNSSEC for Beginners — OMNEX Blog',
      metaDescription:
        'What DNS records actually do, why “propagation” is mostly a TTL story, how DNSSEC works, and the fastest path to a hardened zone.',
      excerpt:
        'DNS is the phonebook of the internet — and most of what you know about “propagation” is wrong. A beginner-friendly tour with concrete steps.',
      intro:
        'You changed a DNS record and now the internet does not see it. Before blaming propagation, let us look at what is actually happening — and what a DNSSEC-signed zone looks like today.',
      sections: [
        {
          heading: 'Records, resolvers and TTLs',
          paragraphs: [
            'A zone is a list of records — A, AAAA, CNAME, MX, TXT, NS, SRV, CAA. When you edit a record, your nameservers serve the new value immediately; the delay you feel is the TTL: every resolver caches the old answer for the TTL duration.',
            'For a fast switch, lower the TTL to 60 s before your change window, then raise it back afterwards.',
          ],
          list: [
            'A / AAAA → where IPv4 / IPv6 points',
            'CNAME → alias to another hostname (never at the root, never with MX)',
            'MX → mail routing, validated for hostname match',
            'SPF / DKIM / DMARC → mail authentication and deliverability',
          ],
        },
        {
          heading: 'DNSSEC in three sentences',
          paragraphs: [
            'DNSSEC cryptographically signs your zone: a resolver can prove that the answers it received come from the authoritative server and were not forged or reordered on the way. The DS record published at the registry chains your zone to the root of trust.',
            'The signing itself takes minutes; what takes longer is the DS record propagating through the registry.',
          ],
        },
        {
          heading: 'The beginner checklist',
          paragraphs: [],
          list: [
            'Set a sane TTL (300 s is fine for most zones)',
            'Add SPF, DKIM and DMARC as soon as any mail exists',
            'Enable DNSSEC before an incident forces you to',
            'Verify with dig +short, not with “did it propagate yet” feelings',
          ],
        },
      ],
    },
    fr: {
      title: 'DNS, propagation et DNSSEC pour les grands débutants',
      metaTitle: 'DNS et DNSSEC pour débutants — Blog OMNEX',
      metaDescription:
        'Ce que font vraiment les enregistrements DNS, pourquoi la « propagation » est surtout une histoire de TTL, comment DNSSEC fonctionne et le chemin le plus court vers une zone protégée.',
      excerpt:
        'Le DNS est l’annuaire d’Internet — et la plupart de ce qu’on croit de la « propagation » est faux. Un tour d’horizon à hauteur de débutant.',
      intro:
        'Vous avez modifié un enregistrement DNS et Internet ne le voit pas. Avant d’accuser la propagation, regardons ce qui se passe réellement — et à quoi ressemble une zone signée DNSSEC.',
      sections: [
        {
          heading: 'Enregistrements, résolveurs et TTL',
          paragraphs: [
            'Une zone est une liste d’enregistrements — A, AAAA, CNAME, MX, TXT, NS, SRV, CAA. Quand vous modifiez un enregistrement, vos serveurs de noms servent aussitôt la nouvelle valeur ; le délai ressenti, c’est le TTL : chaque résolveur met l’ancienne réponse en cache pendant toute la durée du TTL.',
            'Pour une bascule rapide, abaissez le TTL à 60 s avant de changer, puis remontez-le nettement après coup.',
          ],
        },
        {
          heading: 'DNSSEC en trois phrases',
          paragraphs: [
            'DNSSEC signe cryptographiquement votre zone : un résolveur peut prouver que les réponses reçues proviennent bien du serveur de référence et n’ont été ni falsifiées ni réordonnées. L’enregistrement DS publié à l’registraire rattache votre zone à la racine de confiance.',
            'La signature elle-même prend quelques minutes ; c’est la propagation d’un DS publié qui prend un peu plus de temps.',
          ],
        },
        {
          heading: 'La checklist du débutant',
          paragraphs: [],
          list: [
            'Fixez un TTL décent (300 s conviennent pour la plupart des zones)',
            'Ajoutez SPF, DKIM et DMARC dès qu’un courriel existe',
            'Activez DNSSEC avant qu’un incident ne vous y force',
            'Vérifiez avec ; jamais « est-ce que ça propage »',
          ],
        },
      ],
    },
  },
  {
    slug: 'from-five-consoles-to-one',
    category: 'case',
    tags: ['case-study', 'serveurs-du-peuple', 'migration'],
    date: '2026-07-12',
    author: { name: 'Alain Lauzon', role: 'Serveurs du Peuple' },
    en: {
      title: 'Case study: from five consoles to one control plane at Serveurs du Peuple',
      metaTitle: 'Case study: Serveurs du Peuple moves to OMNEX — OMNEX Blog',
      metaDescription:
        'How a cooperative running dozens of domains, servers and sites replaced five consoles with one control plane — and what changed operationally.',
      excerpt:
        'Dozens of domains, servers and sites were spread across five consoles. Ending the sprawl became a control-plane story, not a consolidation project.',
      intro:
        'Serveurs du Peuple is a cooperative that runs its digital infrastructure with the same ethos as its name: open, shared, independent. The operations puzzle was real — dozens of domains, servers and sites, each living in a different console.',
      sections: [
        {
          heading: 'The problem: five consoles, one team',
          paragraphs: [
            'Registrar, DNS, hosting panels, object storage and payment admin lived in different places with different logins. Every change meant context-switching; every incident meant a search through five systems to locate the records involved.',
          ],
        },
        {
          heading: 'One control plane, unchanged workflows',
          paragraphs: [
            'OMNEX brought the estate together behind one foundation: a single identity (passkey or cross-device sign-in), a single RBAC, an immutable audit log and one API for the entire fleet. The team kept its daily habits — but stayed in the same cockpit and found everything in the same history stream.',
          ],
        },
        {
          heading: 'What changed operationally',
          paragraphs: [],
          list: [
            'Incident triage time roughly was cut to a fraction (single search instead of five consoles)',
            'Security posture surfaced centrally — MFA coverage, DNSSEC status, expiring domains',
            'Provider risk reduced: registrars and compute stay swappable behind interfaces',
          ],
        },
      ],
    },
    fr: {
      title: 'Étude de cas : de cinq consoles à un seul plan de contrôle chez les Serveurs du Peuple',
      metaTitle: 'Étude de cas : les Serveurs du Peuple adoptent OMNEX — Blog OMNEX',
      metaDescription:
        'Comment une coopérative pilotant des dizaines de domaines, serveurs et sites a remplacé cinq consoles par un seul plan de contrôle — et ce que ça a changé.',
      excerpt:
        'Des dizaines de domaines, de serveurs et de sites répartis sur cinq consoles. La consolidation est devenue une affaire de plan de contrôle, pas un projet de réorganisation.',
      intro:            'Les Serveurs du Peuple est une coopérative qui pilote son infrastructure numérique avec les mêmes valeurs que son nom : ouverture, autonomie, indépendance. Et le casse-tête était réel : des dizaines de domaines, de serveurs et de sites, chacun dans une console différente.',
      sections: [
        {
          heading: 'Le problème : cinq consoles, une équipe',
          paragraphs: [
            'Le registraire, le DNS, les serveurs, le stockage objet et la facturation vivaient dans des espaces différents, avec des identifiants différents. Chaque changement cumulait les changements dans cinq interfaces ; chaque incident obligeait à une chasse dans cinq systèmes.',
          ],
        },
        {
          heading: 'Un seul plan de contrôle, les mêmes automatismes',
          paragraphs: [
            'OMNEX réunit le parc derrière un seul socle : une identité (connexion sans mot de passe), un seul RBAC, un journal d’audit immuable et une API unique pour tout le parc. Les équipes ont gardé leurs automatismes quotidiens — mais dans le même plan de contrôle, avec le même historique.',
          ],
        },
        {
          heading: 'Ce qui a changé sur le terrain',
          paragraphs: [],
          list: [
            'Le temps de diagnostic d’un incident a été divisé par deux ou trois (un seul plan de jeu)',
            'Le niveau de sécurité est remonté au niveau du score : MFA, DNSSEC, expirations',
            'Le risque de vendeur a baissé : registraires et calculs restent remplaçables derrière les interfaces',
          ],
        },
      ],
    },
  },
  {
    slug: 'zero-trust-security-checklist',
    category: 'guide',
    tags: ['security', 'zero-trust', 'mfa'],
    date: '2026-06-28',
    author: { name: 'OMNEX Engineering', role: 'OMNEX' },
    en: {
      title: 'The zero-trust checklist for small infrastructure teams',
      metaTitle: 'Zero-Trust Checklist — OMNEX Blog',
      metaDescription:
        'A practical, ordered way to harden your estate: MFA everywhere, least privilege, encrypted secrets, immutable audit, monitoring and remediation.',
      excerpt:
        'Zero trust sounds ambitious for a five-person team. It is actually a checklist — and you can tick most boxes this week.',
      intro:
        'Zero trust is not about a architecture; it is a series of small, ordered decisions that make an attacker pay for everything. Here is the version that fits a small team.',
      sections: [
        {
          heading: 'Authenticate, then authenticate again',
          paragraphs: [
            'Turn on MFA for every human, then move to passkeys for admins and for remote sign-in. Every session is a stamped, revocable device; a new device gets detected and verified before it is trusted.',
          ],
        },
        {
          heading: 'Least privilege, by default',
          paragraphs: [
            'Roles and permissions replace “everyone is admin”. Audit who can destroy things, who can export secrets, and who can approve payments. An immutable audit log makes the question answerable.',
          ],
        },
        {
          heading: 'Secrets, backups and expiry',
          paragraphs: [
            'Secrets live in an encrypted vault behind a one-time-use password; keys are scoped per environment. The expiring is no longer a question: the system tells you what expires when.',
          ],
        },
        {
          heading: 'The checklist',
          paragraphs: [],
          list: [
            'MFA enforced for all members, passkeys for privileged',
            'Roles scoped to least privilege, audit log on every mutation',
            'Vault with encryption, per-environment secrets',
            'DNSEC, scheduled snapshots, renewals monitored — not mailed',
          ],
        },
      ],
    },
    fr: {
      title: 'La checklist zero-trust pour les petites équipes d’infrastructure',
      metaTitle: 'Checklist Zero-Trust — Blog OMNEX',
      metaDescription:
        'Un chemin ordonné et concret pour durcir votre équipe : MFA, moindre privilège, secrets chiffrés, audit immuable, remédication.',
      excerpt:
        'Le modèle zéro confiance fait peur pour une équipe de cinq personnes. C’est une checklist — et la plupart des cases se cochent cette semaine.',
      intro:
        'Le modèle zéro confiance n’est pas une question de réseau ; c’est une suite de petites décisions ordonnées qui obligent un attaquant à tout payer. Voici la version qui tient dans une petite équipe.',
      sections: [
        {
          heading: 'S’authentifier, puis s’authentifier encore',
          paragraphs: [
            'Activez la MFA pour chaque humain, puis basculez les comptes administrateur sur une passkey. Chaque jour est un appareil tamponné, révocable qui est déconnecté. Un nouvel appareil est détecté et vérifié avant d’être reconnu.',
          ],
        },
        {
          heading: 'Le moindre privilège, par défaut',
          paragraphs: [
            'Les rôles et permissions remplacent « tout le monde est admin ». Auditez qui peut supprimer, qui peut exporter des secrets, qui peut changer les paramètres. Le journal d’audit rend chaque décision traçable.',
          ],
        },
        {
          heading: 'Secrets, sauvegardes et expirations',
          paragraphs: [
            'Les secrets vivent dans leur coffre chiffré derrière une phrase à usage unique ; chaque environnement ne reçoit que les clés dont il a besoin. Et rien n’expire en silence : le plan de contrôle vous liste tout ce qui a une date — certificats, domaines, sauvegardes.',
          ],
        },
        {
          heading: 'La checklist',
          paragraphs: [],
          list: [
            'MFA exigée pour tous les membres, passkey pour les comptes administratifs',
            'Rôles au moindre privilège, audit sur chaque mutation',
            'Coffre chiffré pour les secrets, un secret par service',
            'DNSSEC, instantanés planifiés, renouvellements suivis — pas en dernier recours',
          ],
        },
      ],
    },
  },
  {
    slug: 'omnex-public-preview',
    category: 'news',
    tags: ['omnex', 'launch', 'announcement'],
    date: '2026-06-14',
    author: { name: 'OMNEX Team', role: 'OMNEX' },
    en: {
      title: 'OMNEX enters public preview: one control plane for your whole infrastructure',
      metaTitle: 'OMNEX Public Preview Announcement — OMNEX Blog',
      metaDescription:
        'Domaines, DNS, sites, cloud, stockage, sécurité et facturation réunis derrière une seule interface, une seule identité, un seul modèle de sécurité et une API.',
      excerpt:
        'Today OMNEX opens to the public: domains, DNS, sites, cloud, storage, security and billing — one account, one security model, one API.',
      intro:
        'We kept the logo black and white; the product is everything in between. OMNEX opens today not as a dashboard of dashboards, but as a single control plane.',
      sections: [
        {
          heading: 'What is in the preview',
          paragraphs: [
            'Provision servers as from Hetzner, DigitalOcean or a custom gateway; manage domains and real DNS records (DNSSEC included) behind a registrars abstraction; deploy sites from Git with instant rollback; store files on S3-compatible providers; measure the estate with a live Security Score; and bill it through one subscription engine.',
          ],
        },
        {
          heading: 'No password-first locks',
          paragraphs: [
            'Every sign-in path is designed for a phishing-resistant world: passkeys and WebAuthn on the devices you own, cross-device QR sign-in with your phone’s biometrics, and unknown-device detection that e-mails a one-time code before a new device is trusted.',
          ],
        },
        {
          heading: 'An API that speaks for the whole estate',
          paragraphs: [
            'Every object — domain, record, server, site, file, invoice, finding — is a resource of the same graph and the same audit stream. Automate from one place, not six.',
          ],
        },
      ],
    },
    fr: {
      title: 'OMNEX entre en avant-première publique : un seul plan de bout en bout',
      metaTitle: 'Annonce de l’avant-première publique OMNEX — Blog OMNEX',
      metaDescription:
        'Domaines, DNS, sites, cloud, stockage, sécurité et facturation réunis dans une seule interface, une seule identité, un seul modèle de sécurité.',
      excerpt:
        'OMNEX s’ouvre aujourd’hui : domaines, DNS, sites, cloud, stockage, sécurité et facturation — un seul compte, un seul modèle de sécurité, une API.',
      intro:
        'Nous avons gardé le logo noir et blanc ; le produit, lui, est tout ce qui se trouve entre les deux. OMNEX n’est pas un tableau de bord de tableaux de bord : c’est un plan de contrôle unique.',
      sections: [
        {
          heading: 'Ce que contient l’avant-première',
          paragraphs: [
            'Provisionnez des serveurs chez Hetzner, DigitalOcean ou via votre passerelle ; gérez des domaines et des enregistristes DNS réels (DNSSEC sauté) ; communiquez des sites Web depuis Git avec retour ; stockz des fichiers sur des fournisseurs compatibles S3 ; mettez en score de sécurité souverain et facturez avec un même moteur d’édition.',
          ],
        },
        {
          heading: 'Des connexions sans mot de passe, par défaut',
          paragraphs: [
            'Tous les chemins d’accès sont pensés pour un monde résistant au phishing : passkeys et WebAuthn sur les appareils que vous possédez, connexion inter-appareils par QR code avec la biométrie de votre téléphone, et détection d’appareil inconnu qui envoie un code à usage unique avant d’accorder sa confiance à un nouvel appareil.',
          ],
        },
        {
          heading: 'Une API qui parle au parc entier',
          paragraphs: [
            'Chaque objet — domaine, enregistrement DNS, serveur, site, clé, facture — est une ressource de la même API et du même flux d’audit. Automatisez depuis un seul endroit, pas six.',
          ],
        },
      ],
    },
  },
];

/** Format an ISO date (YYYY-MM-DD) without timezone drift. */
export function formatBlogDate(date: string, locale: string, opts: Intl.DateTimeFormatOptions = {}): string {
  const [year, month, day] = date.split('-').map(Number);
  const d = new Date(year, (month ?? 1) - 1, day ?? 1);
  return d.toLocaleDateString(locale === 'fr' ? 'fr-CA' : 'en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    ...opts,
  });
}

export const blogCategories: { id: BlogCategory | 'all'; label: string }[] = [
  { id: 'all', label: 'marketing.blog.all' },
  { id: 'guide', label: 'marketing.blog.category.guide' },
  { id: 'news', label: 'marketing.blog.category.news' },
  { id: 'case', label: 'marketing.blog.category.case' },
];

/** Rough reading time in minutes (200 wpm). */
export function estimateReadMinutes(post: BlogPost, locale: string): number {
  const content = locale === 'fr' ? post.fr : post.en;
  const words = (
    content.intro +
    ' ' +
    content.sections.map((s) => s.heading + ' ' + s.paragraphs.join(' ') + ' ' + (s.list ?? []).join(' ')).join(' ')
  ).split(/\s+/).filter(Boolean).length;
  return Math.max(3, Math.round(words / 200));
}

export function postBySlug(slug: string): BlogPost | undefined {
  return blogPosts.find((post) => post.slug === slug);
}

export function postsByCategory(category: BlogCategory | 'all'): BlogPost[] {
  return category === 'all'
    ? [...blogPosts].sort((a, b) => b.date.localeCompare(a.date))
    : blogPosts
        .filter((post) => post.category === category)
        .sort((a, b) => b.date.localeCompare(a.date));
}

export function relatedPosts(post: BlogPost, limit = 3): BlogPost[] {
  return blogPosts
    .filter((other) => other.slug !== post.slug)
    .map((other) => ({
      other,
      score:
        (other.category === post.category ? 2 : 0) +
        other.tags.filter((tag) => post.tags.includes(tag)).length,
    }))
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map((entry) => entry.other);
}