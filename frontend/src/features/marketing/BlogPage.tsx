import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CalendarDays, Clock } from 'lucide-react';
import { useI18n, type TranslateFn } from '../../lib/i18n';
import { Button } from '../../components/ui/Button';
import { Badge } from '../../components/ui/Badge';
import { track } from '../../lib/analytics';
import { cn } from '../../lib/utils';
import {
  blogCategories,
  estimateReadMinutes,
  formatBlogDate,
  postsByCategory,
  type BlogCategory,
  type BlogPost,
} from './blogPosts';
import { useDocumentMeta } from './useDocumentMeta';
import { blogListJsonLd, useHreflang, useJsonLd } from './seo';

const CATEGORY_ORDER: (BlogCategory | 'all')[] = ['all', 'guide', 'news', 'case'];

export function BlogPage() {
  const { locale, t } = useI18n();
  const [category, setCategory] = useState<BlogCategory | 'all'>('all');

  const posts = postsByCategory(category);
  const isFr = locale === 'fr';

  useDocumentMeta(t('marketing.blog.metaTitle'), t('marketing.blog.metaDescription'), '/blog');
  useHreflang('/blog', locale);
  useJsonLd(
    'blog-list',
    blogListJsonLd(
      posts.map((post) => ({
        slug: post.slug,
        title: isFr ? post.fr.title : post.en.title,
        date: post.date,
      })),
    ),
  );

  return (
    <div>
      {/* Hero */}
      <section className="relative overflow-hidden border-b border-white/5">
        <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,255,255,0.06),transparent_60%)]" />
        <div className="relative mx-auto max-w-7xl px-4 py-16 text-center sm:px-6 sm:py-20 lg:px-8">
          <Badge tone="brand" className="mb-6">
            OMNEX · {t('marketing.blog.badge')}
          </Badge>
          <h1 className="mx-auto max-w-3xl text-4xl font-bold tracking-tight text-white sm:text-5xl">
            {t('marketing.blog.title')}
          </h1>
          <p className="mx-auto mt-5 max-w-2xl text-lg leading-relaxed text-zinc-400">
            {t('marketing.blog.subtitle')}
          </p>

          {/* Category filter */}
          <div
            className="mt-9 inline-flex flex-wrap items-center justify-center gap-1 rounded-xl border border-white/10 bg-white/5 p-1"
            role="group"
            aria-label={t('marketing.blog.filters')}
          >
            {CATEGORY_ORDER.map((id) => {
              const cat = blogCategories.find((c) => c.id === id);
              if (!cat) return null;
              const active = category === id;
              return (
                <button
                  key={id}
                  type="button"
                  onClick={() => setCategory(id)}
                  aria-pressed={active}
                  className={cn(
                    'rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors',
                    active ? 'bg-white text-black' : 'text-zinc-400 hover:text-white',
                  )}
                >
                  {t(cat.label)}
                </button>
              );
            })}
          </div>
        </div>
      </section>

      {/* Content */}
      <section className="mx-auto max-w-7xl px-4 py-14 sm:px-6 lg:px-8">
        {posts.length === 0 && (
          <div className="py-20 text-center">
            <p className="text-lg text-zinc-400">{t('marketing.blog.empty')}</p>
          </div>
        )}

        {posts.length > 0 && (
          <div className="space-y-8">
            {category === 'all' && (
              <article className="group overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br from-[#16161a] to-[#101014]">
                <Link to={`/blog/${posts[0].slug}`} className="grid md:grid-cols-2">
                  <div className="flex flex-col justify-center p-8 sm:p-10">
                    <PostMeta post={posts[0]} locale={locale} t={t} />
                    <h2 className="mt-4 text-2xl font-bold leading-tight text-white sm:text-3xl">
                      {(isFr ? posts[0].fr : posts[0].en).title}
                    </h2>
                    <p className="mt-4 text-base leading-relaxed text-zinc-400">
                      {(isFr ? posts[0].fr : posts[0].en).excerpt}
                    </p>
                    <span className="mt-6 inline-flex items-center gap-2 text-sm font-semibold text-brand-200">
                      {t('marketing.blog.readMore')}
                      <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                    </span>
                  </div>
                  <div className="relative min-h-[220px] overflow-hidden border-t border-white/5 md:border-l md:border-t-0">
                    <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,rgba(255,255,255,0.08),transparent_70%)]" />
                    <div className="relative flex h-full flex-col items-center justify-center p-10 text-center">
                      <img src="/logo.png" alt="OMNEX" className="h-16 w-auto opacity-90" />
                      <p className="mt-4 text-xs uppercase tracking-[0.2em] text-zinc-500">
                        OMNEX · {(isFr ? posts[0].fr : posts[0].en).metaTitle}
                      </p>
                    </div>
                  </div>
                </Link>
              </article>
            )}

            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {posts.map((post, index) =>
                category === 'all' && index === 0 ? null : (
                  <PostCard key={post.slug} post={post} locale={locale} t={t} isFr={isFr} />
                ),
              )}
            </div>
          </div>
        )}
      </section>

      {/* Final CTA */}
      <section className="border-t border-white/5 bg-gradient-to-b from-[#121214] to-[#0a0a0c]">
        <div className="mx-auto max-w-4xl px-4 py-20 text-center sm:px-6 lg:px-8">
          <h2 className="text-3xl font-bold tracking-tight text-white sm:text-4xl">
            {t('marketing.ctaBand.title')}
          </h2>
          <p className="mx-auto mt-4 max-w-xl text-lg text-zinc-400">{t('marketing.ctaBand.subtitle')}</p>
          <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <Link to="/login" onClick={() => track('cta_clicked', { cta: 'blog_cta' })}>
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

function PostMeta({
  post,
  locale,
  t,
}: {
  post: BlogPost;
  locale: string;
  t: TranslateFn;
}) {
  const isFr = locale === 'fr';
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-500">
      <span className="font-semibold uppercase tracking-wider text-brand-400">
        {t(`marketing.blog.category.${post.category}`)}
      </span>
      <span className="h-1 w-1 rounded-full bg-zinc-600" aria-hidden="true" />
      <span className="inline-flex items-center gap-1">
        <CalendarDays className="h-3.5 w-3.5" aria-hidden="true" />
        {formatBlogDate(post.date, isFr ? 'fr' : 'en')}
      </span>
      <span className="inline-flex items-center gap-1">
        <Clock className="h-3.5 w-3.5" aria-hidden="true" />
        {t('marketing.blog.readTime', { minutes: estimateReadMinutes(post, locale) })}
      </span>
    </div>
  );
}

/** Compact card for the grid — every scrap is a link, nothing is an impasse. */
function PostCard({
  post,
  locale,
  t,
  isFr,
}: {
  post: BlogPost;
  locale: string;
  t: TranslateFn;
  isFr: boolean;
}) {
  const content = isFr ? post.fr : post.en;
  return (
    <Link
      to={`/blog/${post.slug}`}
      className="group flex flex-col rounded-2xl border border-white/5 bg-[#121214] p-6 transition-colors hover:border-white/15 hover:bg-[#16161a]"
    >
      <PostMeta post={post} locale={locale} t={t} />
      <h3 className="mt-3 text-lg font-semibold leading-snug text-white group-hover:text-brand-200">
        {content.title}
      </h3>
      <p className="mt-3 line-clamp-3 text-sm leading-relaxed text-zinc-400">{content.excerpt}</p>
      <span className="mt-auto inline-flex items-center gap-2 pt-5 text-sm font-medium text-brand-400 transition-colors group-hover:text-brand-300">
        {t('marketing.blog.readMore')}
        <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
      </span>
    </Link>
  );
}