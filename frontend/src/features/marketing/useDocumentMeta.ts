import { useEffect } from 'react';

function setMeta(selector: string, attr: string, value: string) {
  let el = document.querySelector<HTMLMetaElement>(selector);
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, value);
    document.head.appendChild(el);
  } else {
    el.setAttribute('content', value);
  }
}

function setLink(rel: string, href: string) {
  let el = document.querySelector<HTMLLinkElement>(`link[rel="${rel}"]`);
  if (!el) {
    el = document.createElement('link');
    el.setAttribute('rel', rel);
    document.head.appendChild(el);
  }
  el.setAttribute('href', href);
}

const BASE_URL = 'https://omnex.cloud';

export function useDocumentMeta(title: string, description: string, canonicalPath?: string) {
  useEffect(() => {
    const previousTitle = document.title;
    document.title = title;

    setMeta('meta[name="description"]', 'name', 'description');
    document.querySelector('meta[name="description"]')?.setAttribute('content', description);

    setMeta('meta[property="og:title"]', 'property', 'og:title');
    document.querySelector('meta[property="og:title"]')?.setAttribute('content', title);

    setMeta('meta[property="og:description"]', 'property', 'og:description');
    document.querySelector('meta[property="og:description"]')?.setAttribute('content', description);

    setMeta('meta[property="og:url"]', 'property', 'og:url');
    document.querySelector('meta[property="og:url"]')?.setAttribute(
      'content',
      `${BASE_URL}${canonicalPath ?? '/'}`,
    );

    setMeta('meta[property="og:image"]', 'property', 'og:image');
    document.querySelector('meta[property="og:image"]')?.setAttribute('content', `${BASE_URL}/logo.png`);

    setMeta('meta[name="twitter:title"]', 'name', 'twitter:title');
    document.querySelector('meta[name="twitter:title"]')?.setAttribute('content', title);

    setMeta('meta[name="twitter:description"]', 'name', 'twitter:description');
    document.querySelector('meta[name="twitter:description"]')?.setAttribute('content', description);

    setMeta('meta[name="twitter:image"]', 'name', 'twitter:image');
    document.querySelector('meta[name="twitter:image"]')?.setAttribute('content', `${BASE_URL}/logo.png`);

    if (canonicalPath) {
      setLink('canonical', `${BASE_URL}${canonicalPath}`);
    }

    return () => {
      document.title = previousTitle;
    };
  }, [title, description, canonicalPath]);
}
