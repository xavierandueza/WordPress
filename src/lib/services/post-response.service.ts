import { PoolConnection } from 'mysql2/promise';
import { WpPostRow, WpPostResponse } from '@/lib/types';
import { mysqlToRfc3339 } from '@/lib/validation/date';
import { getPostMeta, getAllPostMeta } from '@/lib/db/queries/postmeta';
import { getObjectTerms } from '@/lib/db/queries/terms';
import { isSticky } from '@/lib/db/queries/options';

/**
 * Builds a REST API response for a post.
 *
 * TypeScript port of WP_REST_Posts_Controller::prepare_item_for_response()
 * from class-wp-rest-posts-controller.php lines 1850-2148.
 *
 * In the 'edit' context (which is always used for update responses),
 * all fields including raw values are included.
 *
 * Note: 'rendered' fields for title, content, and excerpt return the raw
 * values since PHP filters (the_title, the_content, the_excerpt) are not
 * available in the TypeScript context. This is a known limitation of the
 * migration — full rendering would require a PHP bridge or headless renderer.
 *
 * @param conn - Database connection
 * @param post - The post database row
 * @param context - Response context ('view', 'edit', or 'embed')
 * @param siteUrl - The WordPress site URL for building permalinks
 * @param gmtOffset - GMT offset in hours for date conversions
 */
export async function buildPostResponse(
  conn: PoolConnection,
  post: WpPostRow,
  context: 'view' | 'edit' | 'embed',
  siteUrl: string,
  gmtOffset: number
): Promise<WpPostResponse> {
  // Date fields — convert MySQL format to RFC 3339 (lines 1873-1916)
  const date = mysqlToRfc3339(post.post_date);
  const dateGmt = prepareDateGmt(post.post_date_gmt, post.post_date, gmtOffset);
  const modified = mysqlToRfc3339(post.post_modified);
  const modifiedGmt = prepareDateGmt(
    post.post_modified_gmt,
    post.post_modified,
    gmtOffset
  );

  // Featured media from _thumbnail_id postmeta (line 2026)
  const thumbnailId = await getPostMeta(conn, post.ID, '_thumbnail_id');
  const featuredMedia = thumbnailId ? parseInt(thumbnailId, 10) : 0;

  // Template from _wp_page_template postmeta (lines 2049-2056)
  const template =
    (await getPostMeta(conn, post.ID, '_wp_page_template')) || '';

  // Sticky status from options (line 2046)
  const sticky = await isSticky(conn, post.ID);

  // Post format from post_format taxonomy (lines 2058-2065)
  const format = await getPostFormat(conn, post.ID);

  // Taxonomy terms — categories and tags (lines 2071-2080)
  const categoryTerms = await getObjectTerms(conn, post.ID, 'category');
  const tagTerms = await getObjectTerms(conn, post.ID, 'post_tag');
  const categories = categoryTerms.map((t) => t.term_id);
  const tags = tagTerms.map((t) => t.term_id);

  // Custom meta (line 2068)
  const allMeta = await getAllPostMeta(conn, post.ID);
  // Filter out internal WordPress meta keys (prefixed with _)
  const publicMeta: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(allMeta)) {
    if (!key.startsWith('_')) {
      publicMeta[key] = value;
    }
  }

  // Build permalink (line 1935)
  const link = buildPermalink(siteUrl, post);

  // Content block version (line 2978 equivalent)
  const blockVersion = countBlockVersion(post.post_content);

  // Whether content/excerpt are password-protected
  const isProtected = !!post.post_password;

  const response: WpPostResponse = {
    id: post.ID,
    date,
    date_gmt: dateGmt,
    guid: {
      rendered: post.guid,
      raw: post.guid,
    },
    modified: modified || '',
    modified_gmt: modifiedGmt || '',
    slug: post.post_name,
    status: post.post_status,
    type: post.post_type,
    link,
    title: {
      raw: post.post_title,
      rendered: post.post_title,
    },
    content: {
      raw: post.post_content,
      rendered: isProtected ? '' : post.post_content,
      protected: isProtected,
      block_version: blockVersion,
    },
    excerpt: {
      raw: post.post_excerpt,
      rendered: isProtected ? '' : post.post_excerpt,
      protected: isProtected,
    },
    author: post.post_author,
    featured_media: featuredMedia,
    comment_status: post.comment_status,
    ping_status: post.ping_status,
    sticky,
    template,
    format,
    meta: publicMeta,
    categories,
    tags,
  };

  // Include password only in edit context (line 1918-1920)
  if (context === 'edit') {
    response.password = post.post_password;
  }

  // Permalink template and generated slug for edit context (lines 2082-2101)
  if (context === 'edit') {
    response.permalink_template = buildPermalinkTemplate(siteUrl, post);
    response.generated_slug = post.post_name;
  }

  return response;
}

/**
 * Prepares the GMT date field, handling the '0000-00-00 00:00:00' case.
 *
 * For drafts, post_date_gmt may not be set (lines 1878-1890).
 * In this case, the value is computed from post_date with timezone offset.
 */
function prepareDateGmt(
  dateGmt: string,
  dateLocal: string,
  gmtOffset: number
): string | null {
  if (dateGmt === '0000-00-00 00:00:00') {
    // Compute from local date
    const localDate = new Date(dateLocal.replace(' ', 'T'));
    if (isNaN(localDate.getTime())) return null;
    const utc = new Date(localDate.getTime() - gmtOffset * 3600000);
    const y = utc.getUTCFullYear();
    const m = String(utc.getUTCMonth() + 1).padStart(2, '0');
    const d = String(utc.getUTCDate()).padStart(2, '0');
    const h = String(utc.getUTCHours()).padStart(2, '0');
    const min = String(utc.getUTCMinutes()).padStart(2, '0');
    const s = String(utc.getUTCSeconds()).padStart(2, '0');
    return `${y}-${m}-${d}T${h}:${min}:${s}`;
  }
  return mysqlToRfc3339(dateGmt);
}

/**
 * Retrieves the post format from the post_format taxonomy.
 * Returns 'standard' if no format is assigned (lines 2058-2065).
 */
async function getPostFormat(
  conn: PoolConnection,
  postId: number
): Promise<string> {
  const terms = await getObjectTerms(conn, postId, 'post_format');
  if (terms.length > 0) {
    // Format term slugs are 'post-format-{name}', strip the prefix
    const slug = terms[0].slug;
    if (slug.startsWith('post-format-')) {
      return slug.slice('post-format-'.length);
    }
    return slug;
  }
  return 'standard';
}

/**
 * Builds a permalink for a post from the site URL and post data.
 * Simplified version — uses the /?p={id} permalink for non-published
 * posts and a pretty permalink for published posts.
 */
function buildPermalink(siteUrl: string, post: WpPostRow): string {
  const baseUrl = siteUrl.replace(/\/$/, '');

  if (
    post.post_status === 'publish' &&
    post.post_name
  ) {
    // Pretty permalink format
    const year = post.post_date.slice(0, 4);
    const month = post.post_date.slice(5, 7);
    const day = post.post_date.slice(8, 10);
    return `${baseUrl}/${year}/${month}/${day}/${post.post_name}/`;
  }

  // Default permalink format for non-published posts
  return `${baseUrl}/?p=${post.ID}`;
}

/**
 * Builds a permalink template string with a %postname% placeholder.
 * Used for the edit context (lines 2082-2101).
 */
function buildPermalinkTemplate(siteUrl: string, post: WpPostRow): string {
  const baseUrl = siteUrl.replace(/\/$/, '');
  const year = post.post_date.slice(0, 4);
  const month = post.post_date.slice(5, 7);
  const day = post.post_date.slice(8, 10);
  return `${baseUrl}/${year}/${month}/${day}/%postname%/`;
}

/**
 * Counts the block version of post content.
 * Returns 1 if the content contains block delimiters (<!-- wp: -->),
 * 0 otherwise. Mirrors block_version() from PHP.
 */
function countBlockVersion(content: string): number {
  return /<!--\s+wp:/.test(content) ? 1 : 0;
}
