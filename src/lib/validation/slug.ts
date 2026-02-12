import { PoolConnection } from 'mysql2/promise';
import { checkSlugExists } from '@/lib/db/queries/posts';

/**
 * Sanitizes a string for use as a URL slug.
 *
 * Simplified port of sanitize_title_with_dashes() from
 * wp-includes/formatting.php.
 *
 * Performs the following transformations:
 * 1. Strips HTML tags
 * 2. Converts to lowercase
 * 3. Removes accents (basic ASCII transliteration)
 * 4. Replaces non-alphanumeric characters with hyphens
 * 5. Collapses multiple hyphens into one
 * 6. Trims leading/trailing hyphens
 */
export function sanitizeTitle(title: string): string {
  let slug = title;

  // Strip HTML tags
  slug = slug.replace(/<[^>]*>/g, '');

  // Convert to lowercase
  slug = slug.toLowerCase();

  // Replace accented characters with ASCII equivalents
  slug = slug.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  // Replace non-alphanumeric characters (except hyphens) with hyphens
  slug = slug.replace(/[^a-z0-9-]/g, '-');

  // Collapse multiple hyphens into one
  slug = slug.replace(/-+/g, '-');

  // Trim leading and trailing hyphens
  slug = slug.replace(/^-+|-+$/g, '');

  return slug;
}

/**
 * Generates a unique post slug by appending a numeric suffix if needed.
 *
 * Port of wp_unique_post_slug() from wp-includes/post.php lines 5460+.
 *
 * WordPress ensures slug uniqueness per post_type + post_parent combination.
 * For draft/pending posts, the status is temporarily treated as 'publish'
 * to ensure uniqueness against published posts.
 *
 * @param conn - Database connection
 * @param desiredSlug - The slug to make unique
 * @param postId - The post being updated (excluded from uniqueness check)
 * @param postStatus - The effective status for uniqueness ('publish' for draft/pending)
 * @param postType - The post type
 * @param postParent - The parent post ID (0 for top-level)
 * @returns A unique slug, possibly with a '-2', '-3', etc. suffix
 */
export async function uniquePostSlug(
  conn: PoolConnection,
  desiredSlug: string,
  postId: number,
  postStatus: string,
  postType: string,
  postParent: number
): Promise<string> {
  let slug = desiredSlug;
  let suffix = 2;

  while (await checkSlugExists(conn, slug, postType, postId, postParent)) {
    slug = `${desiredSlug}-${suffix}`;
    suffix++;
  }

  return slug;
}
