import { Link, Navigate, useParams } from 'react-router-dom';
import { ArrowLeft, ArrowRight, CalendarDays, Clock, Tag } from 'lucide-react';
import { useI18n } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { track } from '../../lib/analytics';
import {
  estimateReadMinutes,
  formatBlogDate,
  postBySlug,
  postsByCategory,
} from './blogPosts';
import { useDocumentMeta } from './useDocumentMeta';
import {
  articleJsonLd,
  blogBreadcrumbJsonLd,
  useHreflang,
  useJsonLd,
} from './seo';

export function BlogPostPage() {
  const { slug } = useParams<{ slug: string }>();
  const { locale, t } = useI18n();
  const post = slug ? postBySlug(slug) : undefined;
  const isFr = locale === 'fr';

  if (!post) return <Navigate to="/blog" replace />;

  const content = isFr ? post.fr : post.en;

  useDocumentMeta(content.metaTitle, content.metaDescription, `/blog/${post.slug}`);
  useHreflang(`/blog/${post.slug}`, locale);
  useJsonLd(
    'article',
    articleJsonLd({
      slug: post.slug,
      title: content.title,
      description: content.metaDescription,
      datePublished: post.date,
      authorName: post.author.name,
      authorRole: post.author.role,
      tags: post.tags,
      locale,
    }),
  );
  useJsonLd('blog-breadcrumb', blogBreadcrumbJsonLd(post.slug, content.title));

  const related = postsByCategory(post.category)
    .filter((other) => other.slug !== post.slug)
    .slice(0, 3);

  return (
    <div>
      {/* Article header */}
      <section className="relative overflow-hidden border-b border-white/5">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.06),transparent_60%)]" />
        <div className="relative mx-auto max-w-3xl px-4 py-16 sm:px-6 sm:py-20 lg:px-8">
          <Link
            to="/blog"
            className="inline-flex items-center gap-2 text-sm text-zinc-400 transition-colors hover:text-white"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            {t('marketing.blog.back')}
          </Link>

          <div className="mt-8 flex flex-wrap items-center gap-3">
            <Badge tone="brand">{t(`marketing.blog.category.${post.category}`)}</Badge>
            <span className="inline-flex items-center gap-1.5 text-xs text-zinc-500">
              <CalendarDays className="h-3.5 w-3.5" aria-hidden="true" />
              {formatBlogDate(post.date, isFr ? 'fr' : 'en', { month: 'long' })}
            </span>
            <span className="inline-flex items-center gap-1.5 text-xs text-zinc-500">
              <Clock className="h-3.5 w-3.5" aria-hidden="true" />
              {t('marketing.blog.readTime', { minutes: estimateReadMinutes(post, locale) })}
            </span>
          </div>

          <h1 className="mt-5 text-3xl font-bold leading-tight tracking-tight text-white sm:text-4xl">
            {content.title}
          </h1>

          <div className="mt-6 flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-white/10 text-sm font-semibold text-white">
              {post.author.name.charAt(0)}
            </div>
            <div className="text-sm">
              <p className="font-medium text-white">{post.author.name}</p>
              <p className="text-zinc-500">
                {t('marketing.blog.by')} {post.author.role}
              </p>
            </div>
          </div>
        </div>
      </section>

      {/* Body */}
      <article className="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <p className="text-xl leading-relaxed text-zinc-300">{content.intro}</p>

        <div className="mt-10 space-y-10">
          {content.sections.map((section, index) => (
            <section key={section.heading ?? index}>
              {section.heading ? (
                <h2 className="text-2xl font-bold tracking-tight text-white">
                  {section.heading}
                </h2>
              ) : null}
              <div className="mt-4 space-y-4">
                {section.paragraphs.map((paragraph, i) => (
                  <p key={i} className="leading-relaxed text-zinc-400">
                    {paragraph}
                  </p>
                ))}
              </div>
              {section.list && section.list.length > 0 ? (
                <ul className="mt-4 space-y-2.5">
                  {section.list.map((item) => (
                    <li key={item} className="flex items-start gap-2.5 text-sm text-zinc-300">
                      <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-400" aria-hidden="true" />
                      {item}
                    </li>
                  ))}
                </ul>
              ) : null}
            </section>
          ))}
        </div>

        {/* Tags */}
        <div className="mt-10 flex flex-wrap items-center gap-2 border-t border-white/5 pt-6">
          <Tag className="h-4 w-4 text-zinc-500" aria-hidden="true" />
          {post.tags.map((tag) => (
            <span
              key={tag}
              className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs text-zinc-400"
            >
              #{tag}
            </span>
          ))}
        </div>
      </article>

      {/* Related posts */}
      {related.length > 0 ? (
        <section className="border-t border-white/5 bg-[#0d0d10]">
          <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
            <h2 className="text-2xl font-bold tracking-tight text-white">
              {t('marketing.blog.related')}
            </h2>
            <div className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {related.map((other) => (
                <Link
                  key={other.slug}
                  to={`/blog/${other.slug}`}
                  className="group rounded-2xl border border-white/5 bg-[#121214] p-6 transition-colors hover:border-white/15"
                >
                  <span className="text-xs font-semibold uppercase tracking-wider text-brand-400">
                    {t(`marketing.blog.category.${other.category}`)}
                  </span>
                  <h3 className="mt-3 text-base font-semibold leading-snug text-white group-hover:text-brand-200">
                    {(isFr ? other.fr : other.en).title}
                  </h3>
                  <span className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-brand-400">
                    {t('marketing.blog.readMore')}
                    <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                  </span>
                </Link>
              ))}
            </div>
          </div>
        </section>
      ) : null}

      {/* Final CTA */}
      <section className="border-t border-white/5 bg-gradient-to-b from-[#121214] to-[#0a0a0c]">
        <div className="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {t('marketing.ctaBand.title')}
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-lg text-zinc-400">{t('marketing.ctaBand.subtitle')}</p>
          <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Link to="/login" onClick={() => track('cta_clicked', { cta: 'blog_post_cta', slug: post.slug })}>
              <Button size="lg">
                {t('marketing.getStarted')}
                <ArrowRight className="h-5 w-5" />
              </Button>
            </Link>
            <Link to="/contact" onClick={() => track('demo_requested', { source: 'blog' })}>
              <Button variant="outline" size="lg">
                {t('marketing.cta.demo')}
              </Button>
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}