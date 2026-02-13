import { PoolConnection } from 'mysql2/promise';
import { AuthenticatedUser, WpPostResponse, WpPostUpdateData } from '@/lib/types';
import { PostUpdateInput, extractRawValue } from '@/lib/validation/post-update-schema';
import { validateAndNormalizeStatus } from '@/lib/validation/status';
import { restGetDateWithGmt } from '@/lib/validation/date';
import { uniquePostSlug } from '@/lib/validation/slug';
import { WpError, isWpError } from '@/lib/errors/wp-error';
import { checkUpdatePermission, userCan } from '@/lib/auth/capabilities';
import { getPostById, updatePost, isValidAttachment } from '@/lib/db/queries/posts';
import { updatePostMeta, deletePostMeta } from '@/lib/db/queries/postmeta';
import {
  setObjectTerms,
  termExists,
  getPostFormatTermId,
} from '@/lib/db/queries/terms';
import {
  getStickyPosts,
  setStickyPosts,
  isSticky,
} from '@/lib/db/queries/options';
import { userExists } from '@/lib/db/queries/users';
import { getOption } from '@/lib/db/queries/options';
import { buildPostResponse } from './post-response.service';

/**
 * Orchestrates the full post update flow within a database transaction.
 *
 * This is the TypeScript equivalent of:
 * - WP_REST_Posts_Controller::update_item_permissions_check() (lines 890-931)
 * - WP_REST_Posts_Controller::update_item() (lines 941-1052)
 * - WP_REST_Posts_Controller::prepare_item_for_database() (lines 1282-1497, update path)
 *
 * @param conn - Database connection (within a transaction)
 * @param postId - The post ID to update
 * @param body - Validated request body
 * @param user - Authenticated user
 * @returns Updated post response or error
 */
export async function handlePostUpdate(
  conn: PoolConnection,
  postId: number,
  body: PostUpdateInput,
  user: AuthenticatedUser
): Promise<WpPostResponse | WpError> {
  // -----------------------------------------------------------------------
  // Step 1: Validate post exists (mirrors get_post check at line 942)
  // -----------------------------------------------------------------------
  const existingPost = await getPostById(conn, postId);
  if (!existingPost) {
    return new WpError('rest_post_invalid_id', 'Invalid post ID.', {
      status: 404,
    });
  }

  // Only handle 'post' type through this endpoint
  if (existingPost.post_type !== 'post') {
    return new WpError('rest_post_invalid_id', 'Invalid post ID.', {
      status: 404,
    });
  }

  // -----------------------------------------------------------------------
  // Step 2: Permission checks (mirrors update_item_permissions_check, lines 890-931)
  // -----------------------------------------------------------------------
  const permError = await checkPermissions(conn, existingPost, body, user);
  if (permError) {
    return permError;
  }

  // -----------------------------------------------------------------------
  // Step 3: Snapshot post_before (line 947)
  // -----------------------------------------------------------------------
  const postBefore = { ...existingPost };

  // -----------------------------------------------------------------------
  // Step 4: Prepare data for database (mirrors prepare_item_for_database, lines 1282-1497)
  // -----------------------------------------------------------------------
  const prepareResult = await prepareItemForDatabase(
    conn,
    body,
    existingPost,
    user
  );
  if (isWpError(prepareResult)) {
    return prepareResult;
  }

  const updateData = prepareResult;

  // -----------------------------------------------------------------------
  // Step 5: Handle slug uniqueness for draft/pending (lines 960-974)
  // -----------------------------------------------------------------------
  const effectiveStatus = updateData.post_status || existingPost.post_status;
  if (
    updateData.post_name &&
    ['draft', 'pending'].includes(effectiveStatus)
  ) {
    updateData.post_name = await uniquePostSlug(
      conn,
      updateData.post_name,
      postId,
      'publish', // wp_unique_post_slug uses 'publish' status for draft/pending
      existingPost.post_type,
      updateData.post_parent ?? existingPost.post_parent
    );
  }

  // -----------------------------------------------------------------------
  // Step 6: Update wp_posts (line 977)
  // -----------------------------------------------------------------------
  const updateSuccess = await updatePost(conn, postId, updateData);
  if (!updateSuccess) {
    return new WpError(
      'db_update_error',
      'Could not update post in the database.',
      { status: 500 }
    );
  }

  // -----------------------------------------------------------------------
  // Step 7: Post-update operations (lines 988-1034)
  // -----------------------------------------------------------------------

  // 7a: Handle post format (lines 995-997)
  if (body.format !== undefined) {
    await handlePostFormat(conn, postId, body.format);
  }

  // 7b: Handle featured media (lines 999-1001)
  if (body.featured_media !== undefined) {
    const mediaResult = await handleFeaturedMedia(
      conn,
      body.featured_media,
      postId
    );
    if (isWpError(mediaResult)) {
      return mediaResult;
    }
  }

  // 7c: Handle sticky (lines 1003-1009)
  if (body.sticky !== undefined) {
    await handleStickyStatus(conn, postId, body.sticky);
  }

  // 7d: Handle template (lines 1011-1013)
  if (body.template !== undefined) {
    await updatePostMeta(conn, postId, '_wp_page_template', body.template);
  }

  // 7e: Handle taxonomy terms (lines 1015-1019)
  const termsError = await handleTerms(conn, postId, body);
  if (isWpError(termsError)) {
    return termsError;
  }

  // 7f: Handle post meta (lines 1021-1027)
  if (body.meta) {
    for (const [key, value] of Object.entries(body.meta)) {
      if (value !== null && value !== undefined) {
        await updatePostMeta(conn, postId, key, String(value));
      }
    }
  }

  // -----------------------------------------------------------------------
  // Step 8: Build response (lines 1049-1051)
  // -----------------------------------------------------------------------
  const updatedPost = await getPostById(conn, postId);
  if (!updatedPost) {
    return new WpError(
      'rest_post_invalid_id',
      'Post not found after update.',
      { status: 500 }
    );
  }

  // Read GMT offset for date formatting
  const gmtOffsetStr = await getOption(conn, 'gmt_offset');
  const gmtOffset = gmtOffsetStr ? parseFloat(gmtOffsetStr) : 0;

  const siteUrl =
    (await getOption(conn, 'siteurl')) || 'http://localhost';

  return buildPostResponse(conn, updatedPost, 'edit', siteUrl, gmtOffset);
}

/**
 * Permission checks matching update_item_permissions_check() (lines 890-931).
 */
async function checkPermissions(
  conn: PoolConnection,
  post: { ID: number; post_type: string; post_author: number; post_status: string; post_name: string; post_title: string; post_content: string; post_excerpt: string; post_password: string; post_date: string; post_date_gmt: string; post_modified: string; post_modified_gmt: string; post_parent: number; guid: string; menu_order: number; comment_status: string; ping_status: string; to_ping: string; pinged: string; post_content_filtered: string; post_mime_type: string; comment_count: number },
  body: PostUpdateInput,
  user: AuthenticatedUser
): Promise<WpError | null> {
  // Check edit_post capability (line 898)
  if (!checkUpdatePermission(user, post)) {
    return new WpError(
      'rest_cannot_edit',
      'Sorry, you are not allowed to edit this post.',
      { status: 403 }
    );
  }

  // Check author change permission (line 906)
  if (
    body.author !== undefined &&
    body.author !== user.id &&
    !user.allcaps['edit_others_posts']
  ) {
    return new WpError(
      'rest_cannot_edit_others',
      'Sorry, you are not allowed to update posts as this user.',
      { status: 403 }
    );
  }

  // Check sticky permission (line 914)
  if (
    body.sticky &&
    !user.allcaps['edit_others_posts'] &&
    !user.allcaps['publish_posts']
  ) {
    return new WpError(
      'rest_cannot_assign_sticky',
      'Sorry, you are not allowed to make posts sticky.',
      { status: 403 }
    );
  }

  // Check term assignment permissions (line 922)
  const canAssignTerms = await checkAssignTermsPermission(conn, body, user);
  if (!canAssignTerms) {
    return new WpError(
      'rest_cannot_assign_term',
      'Sorry, you are not allowed to assign the provided terms.',
      { status: 403 }
    );
  }

  return null;
}

/**
 * Checks if the user can assign all provided terms.
 * Mirrors check_assign_terms_permission() (lines 1695-1717).
 *
 * In WordPress, this checks current_user_can('assign_term', $term_id)
 * for each provided term. We simplify by checking the assign_terms
 * capability for the relevant taxonomy.
 */
async function checkAssignTermsPermission(
  conn: PoolConnection,
  body: PostUpdateInput,
  user: AuthenticatedUser
): Promise<boolean> {
  // Check category assignments
  if (body.categories && body.categories.length > 0) {
    if (!user.allcaps['assign_categories'] && !user.allcaps['manage_categories']) {
      // Fall back to edit_posts as a reasonable default
      if (!user.allcaps['edit_posts']) {
        return false;
      }
    }
    for (const termId of body.categories) {
      const exists = await termExists(conn, termId, 'category');
      if (!exists) continue; // Invalid terms are rejected later
    }
  }

  // Check tag assignments
  if (body.tags && body.tags.length > 0) {
    if (!user.allcaps['assign_post_tags'] && !user.allcaps['manage_post_tags']) {
      if (!user.allcaps['edit_posts']) {
        return false;
      }
    }
  }

  return true;
}

/**
 * Prepares post data for the database update.
 * Mirrors the update path of prepare_item_for_database() (lines 1282-1497).
 */
async function prepareItemForDatabase(
  conn: PoolConnection,
  body: PostUpdateInput,
  existingPost: { ID: number; post_type: string; post_status: string; post_parent: number; post_author: number; post_password: string },
  user: AuthenticatedUser
): Promise<WpPostUpdateData | WpError> {
  const data: WpPostUpdateData = {};

  // Post title (lines 1300-1306)
  const title = extractRawValue(body.title);
  if (title !== undefined) {
    data.post_title = title;
  }

  // Post content (lines 1309-1315)
  const content = extractRawValue(body.content);
  if (content !== undefined) {
    data.post_content = content;
  }

  // Post excerpt (lines 1318-1324)
  const excerpt = extractRawValue(body.excerpt);
  if (excerpt !== undefined) {
    data.post_excerpt = excerpt;
  }

  // Post status (lines 1338-1350)
  if (body.status !== undefined && body.status !== existingPost.post_status) {
    const statusResult = validateAndNormalizeStatus(body.status, user);
    if (isWpError(statusResult)) {
      return statusResult;
    }
    data.post_status = statusResult;
  }

  // Post date (lines 1353-1381)
  const gmtOffsetStr = await getOption(conn, 'gmt_offset');
  const gmtOffset = gmtOffsetStr ? parseFloat(gmtOffsetStr) : 0;

  if (body.date !== undefined) {
    if (body.date === null) {
      // Null resets to default (lines 1375-1381)
      data.post_date = '0000-00-00 00:00:00';
      data.post_date_gmt = '0000-00-00 00:00:00';
    } else {
      const dateData = restGetDateWithGmt(body.date, false, gmtOffset);
      if (dateData) {
        data.post_date = dateData[0];
        data.post_date_gmt = dateData[1];
      }
    }
  } else if (body.date_gmt !== undefined) {
    if (body.date_gmt === null) {
      data.post_date = '0000-00-00 00:00:00';
      data.post_date_gmt = '0000-00-00 00:00:00';
    } else {
      const dateData = restGetDateWithGmt(body.date_gmt, true, gmtOffset);
      if (dateData) {
        data.post_date = dateData[0];
        data.post_date_gmt = dateData[1];
      }
    }
  }

  // Post slug (lines 1384-1386)
  if (body.slug !== undefined) {
    data.post_name = body.slug;
  }

  // Author (lines 1389-1405)
  if (body.author !== undefined) {
    const authorId = body.author;
    if (authorId !== user.id) {
      const authorExistsResult = await userExists(conn, authorId);
      if (!authorExistsResult) {
        return new WpError('rest_invalid_author', 'Invalid author ID.', {
          status: 400,
        });
      }
    }
    data.post_author = authorId;
  }

  // Post password (lines 1408-1428)
  if (body.password !== undefined) {
    data.post_password = body.password;

    // Validate password/sticky mutual exclusion
    if (body.password !== '') {
      if (body.sticky) {
        return new WpError(
          'rest_invalid_field',
          'A post can not be sticky and have a password.',
          { status: 400 }
        );
      }
      const currentlySticky = await isSticky(conn, existingPost.ID);
      if (currentlySticky) {
        return new WpError(
          'rest_invalid_field',
          'A sticky post can not be password protected.',
          { status: 400 }
        );
      }
    }
  }

  // Validate sticky with existing password (lines 1430-1438)
  if (body.sticky) {
    if (existingPost.post_password) {
      return new WpError(
        'rest_invalid_field',
        'A password protected post can not be set to sticky.',
        { status: 400 }
      );
    }
  }

  // Parent (lines 1441-1457)
  if (body.parent !== undefined) {
    if (body.parent === 0) {
      data.post_parent = 0;
    } else {
      const parentPost = await getPostById(conn, body.parent);
      if (!parentPost) {
        return new WpError(
          'rest_post_invalid_id',
          'Invalid post parent ID.',
          { status: 400 }
        );
      }
      data.post_parent = parentPost.ID;
    }
  }

  // Menu order (lines 1460-1462)
  if (body.menu_order !== undefined) {
    data.menu_order = body.menu_order;
  }

  // Comment status (lines 1465-1467)
  if (body.comment_status !== undefined) {
    data.comment_status = body.comment_status;
  }

  // Ping status (lines 1470-1472)
  if (body.ping_status !== undefined) {
    data.ping_status = body.ping_status;
  }

  return data;
}

/**
 * Sets the post format by updating terms in the 'post_format' taxonomy.
 * Mirrors set_post_format() from wp-includes/post-formats.php.
 */
async function handlePostFormat(
  conn: PoolConnection,
  postId: number,
  format: string
): Promise<void> {
  if (format === 'standard' || format === '') {
    // 'standard' means no format term; clear any existing assignment
    await setObjectTerms(conn, postId, [], 'post_format');
    return;
  }

  const termId = await getPostFormatTermId(conn, format);
  if (termId) {
    await setObjectTerms(conn, postId, [termId], 'post_format');
  }
}

/**
 * Handles featured media (thumbnail) updates.
 * Mirrors handle_featured_media() from lines 1578-1595.
 */
async function handleFeaturedMedia(
  conn: PoolConnection,
  featuredMedia: number,
  postId: number
): Promise<true | WpError> {
  if (featuredMedia > 0) {
    // Validate the attachment exists
    const isValid = await isValidAttachment(conn, featuredMedia);
    if (!isValid) {
      return new WpError(
        'rest_invalid_featured_media',
        'Invalid featured media ID.',
        { status: 400 }
      );
    }
    await updatePostMeta(conn, postId, '_thumbnail_id', String(featuredMedia));
    return true;
  } else {
    // featured_media === 0 means remove the thumbnail
    await deletePostMeta(conn, postId, '_thumbnail_id');
    return true;
  }
}

/**
 * Handles sticky post status updates.
 * Mirrors stick_post() / unstick_post() from wp-includes/post.php.
 */
async function handleStickyStatus(
  conn: PoolConnection,
  postId: number,
  sticky: boolean
): Promise<void> {
  const stickyPosts = await getStickyPosts(conn);

  if (sticky) {
    if (!stickyPosts.includes(postId)) {
      stickyPosts.push(postId);
      await setStickyPosts(conn, stickyPosts);
    }
  } else {
    const index = stickyPosts.indexOf(postId);
    if (index >= 0) {
      stickyPosts.splice(index, 1);
      await setStickyPosts(conn, stickyPosts);
    }
  }
}

/**
 * Handles taxonomy term updates (categories and tags).
 * Mirrors handle_terms() from lines 1667-1685.
 */
async function handleTerms(
  conn: PoolConnection,
  postId: number,
  body: PostUpdateInput
): Promise<null | WpError> {
  if (body.categories !== undefined) {
    await setObjectTerms(conn, postId, body.categories, 'category');
  }

  if (body.tags !== undefined) {
    await setObjectTerms(conn, postId, body.tags, 'post_tag');
  }

  return null;
}
