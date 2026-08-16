import { useEffect } from 'react';

const BASE_URL = 'https://omnex.cloud';

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
