import { useEffect } from 'react';

const BASE_URL = 'https://omnex.cloud';

/**
 * Inject EN/FR hreflang alternates for a public page. The marketing site
 * serves both languages from the same URL (content is chosen by the visitor's
 * locale), so the alternates point at that URL with `x-default` as fallback.
 * Also keeps <html lang> in sync with the active locale.
 */
export function useHreflang(path: string, locale: string) {
  useEffect(() => {
    const url = `${BASE_URL}${path}`;

    for (const lang of ['en', 'fr', 'x-default'] as const) {
      const id = `hreflang-${lang}`;
      let el = document.getElementById(id) as HTMLLinkElement | null;
      if (!el) {
        el = document.createElement('link');
        el.id = id;
        el.setAttribute('rel', 'alternate');
        el.setAttribute('hreflang', lang);
        document.head.appendChild(el);
      }
      el.setAttribute('href', url);
    }

    document.documentElement.lang = locale === 'fr' ? 'fr' : 'en';
  }, [path, locale]);
}

function upsertJsonLd(id: string, data: Record<string, unknown>) {
  let el = document.getElementById(id) as HTMLScriptElement | null;
  if (!el) {
    el = document.createElement('script');
    el.id = id;
    el.setAttribute('type', 'application/ld+json');
    document.head.appendChild(el);
  }
  el.textContent = JSON.stringify(data);
}

export function useJsonLd(id: string, data: Record<string, unknown>) {
  useEffect(() => {
    upsertJsonLd(`jsonld-${id}`, data);
  }, [id, JSON.stringify(data)]);
}

/** Organization — site-wide, on the homepage. */
export function organizationJsonLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: 'OMNEX',
    url: BASE_URL,
    logo: `${BASE_URL}/logo.png`,
    description: 'Cloud Infrastructure, Simplified — a single control plane for domains, DNS, websites, cloud servers, storage, email, security and billing.',
    sameAs: [],
  };
}

/** WebSite + potential search action — homepage. */
export function webSiteJsonLd() {
  return {
    '@context': 'https://schema.org',
    '@type': 'WebSite',
    name: 'OMNEX',
    url: BASE_URL,
    description: 'Cloud Infrastructure, Simplified.',
    potentialAction: {
      '@type': 'SearchAction',
      target: { '@type': 'EntryPoint', urlTemplate: `${BASE_URL}/?q={search_term_string}` },
      'query-input': 'required name=search_term_string',
    },
  };
}

/** FAQPage from the translated FAQ items. */
export function faqJsonLd(items: { question: string; answer: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: items.map((item) => ({
      '@type': 'Question',
      name: item.question,
      acceptedAnswer: { '@type': 'Answer', text: item.answer },
    })),
  };
}

/** Service / Product for a service page. */
export function serviceJsonLd(serviceId: string, name: string, description: string) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Service',
    name: `${name} — OMNEX`,
    url: `${BASE_URL}/marketing/${serviceId}`,
    provider: { '@type': 'Organization', name: 'OMNEX', url: BASE_URL },
    description,
    areaServed: 'Worldwide',
    audience: { '@type': 'Audience', audienceType: 'Businesses and infrastructure teams' },
  };
}

/** Campaign landing page — Product + Offer for SEO. */
export function campaignJsonLd(slug: string, title: string, description: string) {
  return {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: title,
    url: `${BASE_URL}/landing/${slug}`,
    description,
    brand: { '@type': 'Brand', name: 'OMNEX' },
    offers: {
      '@type': 'Offer',
      url: `${BASE_URL}/landing/${slug}`,
      availability: 'https://schema.org/InStock',
      priceCurrency: 'USD',
    },
  };
}

/** BreadcrumbList for campaign pages. */
export function campaignBreadcrumbJsonLd(slug: string, title: string) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Home', item: BASE_URL },
      { '@type': 'ListItem', position: 2, name: 'Campaign', item: `${BASE_URL}/landing/${slug}` },
      { '@type': 'ListItem', position: 3, name: title, item: `${BASE_URL}/landing/${slug}` },
    ],
  };
}

/** BreadcrumbList for service pages. */
export function breadcrumbJsonLd(serviceId: string, serviceName: string) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Home', item: BASE_URL },
      { '@type': 'ListItem', position: 2, name: 'Services', item: `${BASE_URL}/#services` },
      { '@type': 'ListItem', position: 3, name: serviceName, item: `${BASE_URL}/marketing/${serviceId}` },
    ],
  };
}

/** Blog index — ItemList with the newest posts for the hub page. */
export function blogListJsonLd(posts: { slug: string; title: string; date: string }[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: 'OMNEX Blog',
    numberOfItems: posts.length,
    itemListElement: posts.map((post, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      url: `${BASE_URL}/blog/${post.slug}`,
      name: post.title,
    })),
  };
}

/** Article — structured data for a blog post (rich results + knowledge graph). */
export function articleJsonLd(opts: {
  slug: string;
  title: string;
  description: string;
  datePublished: string;
  dateModified?: string;
  authorName: string;
  authorRole?: string;
  tags: string[];
  locale: string;
}) {
  const { slug, title, description, datePublished, dateModified, authorName, authorRole, tags, locale } = opts;
  return {
    '@context': 'https://schema.org',
    '@type': 'Article',
    headline: title,
    description,
    url: `${BASE_URL}/blog/${slug}`,
    mainEntityOfPage: `${BASE_URL}/blog/${slug}`,
    datePublished,
    ...(dateModified ? { dateModified } : {}),
    inLanguage: locale === 'fr' ? 'fr-CA' : 'en-US',
    image: `${BASE_URL}/logo.png`,
    keywords: tags.join(', '),
    author: {
      '@type': 'Person',
      name: authorName,
      ...(authorRole ? { jobTitle: authorRole } : {}),
    },
    publisher: {
      '@type': 'Organization',
      name: 'OMNEX',
      url: BASE_URL,
      logo: { '@type': 'ImageObject', url: `${BASE_URL}/logo.png` },
    },
  };
}

/** BreadcrumbList for blog article pages. */
export function blogBreadcrumbJsonLd(slug: string, title: string) {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'Home', item: BASE_URL },
      { '@type': 'ListItem', position: 2, name: 'Blog', item: `${BASE_URL}/blog` },
      { '@type': 'ListItem', position: 3, name: title, item: `${BASE_URL}/blog/${slug}` },
    ],
  };
}
