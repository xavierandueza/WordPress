import { z } from 'zod';

/**
 * Zod validation schema for the POST /sites/{site}/posts/{postId} request body.
 *
 * Matches the WordPress REST API schema from
 * WP_REST_Posts_Controller::get_item_schema() and
 * get_endpoint_args_for_item_schema(WP_REST_Server::EDITABLE).
 *
 * All fields are optional since this is a PATCH-style update — only
 * provided fields are modified.
 */
export const postUpdateSchema = z
  .object({
    // Title can be a plain string or an object with a 'raw' property
    title: z
      .union([z.string(), z.object({ raw: z.string() })])
      .optional(),

    // Content can be a plain string or an object with a 'raw' property
    content: z
      .union([z.string(), z.object({ raw: z.string() })])
      .optional(),

    // Excerpt can be a plain string or an object with a 'raw' property
    excerpt: z
      .union([z.string(), z.object({ raw: z.string() })])
      .optional(),

    // Post status - validated further by handle_status_param equivalent
    status: z
      .enum(['publish', 'future', 'draft', 'pending', 'private', 'trash'])
      .optional(),

    // Date in the site's timezone (ISO 8601 format or null to reset)
    date: z.string().nullable().optional(),

    // Date in GMT (ISO 8601 format or null to reset)
    date_gmt: z.string().nullable().optional(),

    // Post slug (URL-friendly name)
    slug: z.string().optional(),

    // Author user ID
    author: z.number().int().positive().optional(),

    // Post password (empty string to remove password protection)
    password: z.string().optional(),

    // Featured media (attachment ID, 0 to remove)
    featured_media: z.number().int().min(0).optional(),

    // Whether the post is sticky (pinned to top)
    sticky: z.boolean().optional(),

    // Page template filename
    template: z.string().optional(),

    // Post format
    format: z
      .enum([
        'standard',
        'aside',
        'chat',
        'gallery',
        'link',
        'image',
        'quote',
        'status',
        'video',
        'audio',
      ])
      .optional(),

    // Comment status
    comment_status: z.enum(['open', 'closed']).optional(),

    // Ping status
    ping_status: z.enum(['open', 'closed']).optional(),

    // Parent post ID (for hierarchical post types; 0 for no parent)
    parent: z.number().int().min(0).optional(),

    // Menu order for page-attributes
    menu_order: z.number().int().optional(),

    // Category term IDs
    categories: z.array(z.number().int().positive()).optional(),

    // Tag term IDs
    tags: z.array(z.number().int().positive()).optional(),

    // Custom meta fields
    meta: z.record(z.unknown()).optional(),
  })
  .strict();

export type PostUpdateInput = z.infer<typeof postUpdateSchema>;

/**
 * Extracts the raw string value from a title/content/excerpt field
 * that can be either a string or an object with a 'raw' property.
 *
 * This mirrors the PHP logic in prepare_item_for_database():
 *   if ( is_string( $request['title'] ) ) {
 *       $prepared_post->post_title = $request['title'];
 *   } elseif ( ! empty( $request['title']['raw'] ) ) {
 *       $prepared_post->post_title = $request['title']['raw'];
 *   }
 */
export function extractRawValue(
  field: string | { raw: string } | undefined
): string | undefined {
  if (field === undefined) return undefined;
  if (typeof field === 'string') return field;
  if (typeof field === 'object' && 'raw' in field) return field.raw;
  return undefined;
}
