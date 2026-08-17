import { useEffect, useState } from 'react';
import { Navigate, useParams } from 'react-router-dom';
import { api } from '../../lib/api';
import type { LandingPageDto, LandingPageSection } from '../../lib/api/types';
import { useI18n } from '../../lib/i18n';
import { FullPageLoader } from '../../components/ui/Spinner';
import { LandingPageView } from './LandingPageView';
import { useDocumentMeta } from './useDocumentMeta';
import {
  campaignBreadcrumbJsonLd,
  campaignJsonLd,
  useHreflang,
  useJsonLd,
} from './seo';

/** Public campaign page served from the landing page CMS. */
export function LandingPageRoute() {
  const { slug } = useParams<{ slug: string }>();
  const { locale } = useI18n();
  const [page, setPage] = useState<LandingPageDto | null>(null);
  const [missing, setMissing] = useState(false);

  useEffect(() => {
    if (!slug) return;
    let cancelled = false;
    setPage(null);
    setMissing(false);
    api
      .getLandingPage(slug)
      .then((loaded) => {
        if (!cancelled) setPage(loaded);
      })
      .catch(() => {
        if (!cancelled) setMissing(true);
      });
    return () => {
      cancelled = true;
    };
  }, [slug]);

  const content: LandingPageSection[] = page ? (locale === 'fr' ? page.content_fr : page.content_en) : [];
  const hero = content.find((s) => s.kind === 'hero') as
    | Extract<LandingPageSection, { kind: 'hero' }>
    | undefined;
  const title = hero?.title ?? (page ? `OMNEX — ${page.slug}` : 'OMNEX');
  const description = hero?.subtitle ?? '';

  useDocumentMeta(title, description, page ? `/landing/${page.slug}` : undefined);
  useHreflang(page ? `/landing/${page.slug}` : '/', locale);
  useJsonLd('campaign', page ? campaignJsonLd(page.slug, title, description) : {});
  useJsonLd('campaign-breadcrumb', page ? campaignBreadcrumbJsonLd(page.slug, title) : {});

  if (!slug || missing) return <Navigate to="/" replace />;
  if (!page) return <FullPageLoader />;

  return <LandingPageView sections={content} slug={page.slug} />;
}
