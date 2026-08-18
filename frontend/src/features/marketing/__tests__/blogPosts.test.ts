import { describe, expect, it } from 'vitest';
import {
  blogPosts,
  estimateReadMinutes,
  postBySlug,
  postsByCategory,
  relatedPosts,
} from '../blogPosts';

describe('blog content hub', () => {
  it('has unique slugs and valid dates', () => {
    const slugs = blogPosts.map((post) => post.slug);
    expect(new Set(slugs).size).toBe(slugs.length);
    for (const post of blogPosts) {
      expect(Number.isNaN(Date.parse(post.date))).toBe(false);
      expect(post.category).toMatch(/^(guide|news|case)$/);
    }
  });

  it('provides complete en and fr content for every post', () => {
    for (const post of blogPosts) {
      for (const locale of ['en', 'fr'] as const) {
        const content = post[locale];
        expect(content.title.length).toBeGreaterThan(10);
        expect(content.metaTitle.length).toBeGreaterThan(10);
        expect(content.metaDescription.length).toBeGreaterThan(30);
        expect(content.excerpt.length).toBeGreaterThan(30);
        expect(content.intro.length).toBeGreaterThan(30);
        expect(content.sections.length).toBeGreaterThanOrEqual(2);
      }
    }
  });

  it('sorts posts newest first via postsByCategory', () => {
    const newest = postsByCategory('all');
    const dates = newest.map((post) => post.date);
    expect([...dates].sort((a, b) => b.localeCompare(a))).toEqual(dates);
  });

  it('filters by category and resolves by slug', () => {
    const guides = postsByCategory('guide');
    expect(guides.length).toBeGreaterThan(0);
    expect(guides.every((post) => post.category === 'guide')).toBe(true);
    expect(postBySlug(guides[0].slug)).toBe(guides[0]);
    expect(postBySlug('does-not-exist')).toBeUndefined();
  });

  it('returns related posts excluding the source post', () => {
    const post = postBySlug('web-authn-passkeys-guide');
    expect(post).toBeDefined();
    const related = relatedPosts(post!);
    expect(related).toHaveLength(3);
    expect(related.some((p) => p.slug === post!.slug)).toBe(false);
  });

  it('estimates a positive reading time for both locales', () => {
    for (const post of blogPosts) {
      expect(estimateReadMinutes(post, 'en')).toBeGreaterThanOrEqual(3);
      expect(estimateReadMinutes(post, 'fr')).toBeGreaterThanOrEqual(3);
    }
  });
});