import { AuthenticatedUser, WpPostRow, POST_TYPE_CAPS } from '@/lib/types';

/**
 * Maps a meta capability to the primitive capabilities required.
 *
 * This is a TypeScript port of the edit_post case from map_meta_cap()
 * in wp-includes/capabilities.php (lines 188-286).
 *
 * For the 'edit_post' meta capability, the required primitive caps depend on:
 * - Whether the user is the post author
 * - The current post status (published, private, draft, etc.)
 */
export function mapMetaCap(
  cap: string,
  user: AuthenticatedUser,
  post: WpPostRow
): string[] {
  const caps: string[] = [];
  const postTypeCaps = POST_TYPE_CAPS;

  switch (cap) {
    case 'edit_post': {
      if (post.post_author === user.id) {
        // Author editing their own post
        if (
          post.post_status === 'publish' ||
          post.post_status === 'future'
        ) {
          caps.push(postTypeCaps.edit_published_posts);
        } else if (post.post_status === 'trash') {
          // For trash, check based on the pre-trash status
          // Simplified: require edit_posts for own trashed posts
          caps.push(postTypeCaps.edit_posts);
        } else {
          caps.push(postTypeCaps.edit_posts);
        }
      } else {
        // Non-author editing someone else's post
        caps.push(postTypeCaps.edit_others_posts);
        if (
          post.post_status === 'publish' ||
          post.post_status === 'future'
        ) {
          caps.push(postTypeCaps.edit_published_posts);
        } else if (post.post_status === 'private') {
          caps.push(postTypeCaps.edit_private_posts);
        }
      }
      break;
    }

    default:
      // For non-mapped capabilities, return the cap itself
      caps.push(cap);
      break;
  }

  return caps;
}

/**
 * Checks whether a user has a given capability, with optional
 * object-level checks for meta capabilities like 'edit_post'.
 *
 * Mirrors current_user_can() in WordPress.
 *
 * For primitive capabilities (e.g., 'edit_posts', 'publish_posts'),
 * checks directly against the user's allcaps.
 *
 * For meta capabilities (e.g., 'edit_post'), maps to the required
 * primitive capabilities and checks all of them.
 */
export function userCan(
  user: AuthenticatedUser,
  cap: string,
  post?: WpPostRow
): boolean {
  // Meta capabilities that require an object context
  const metaCaps = ['edit_post', 'delete_post', 'read_post'];

  if (metaCaps.includes(cap) && post) {
    const requiredCaps = mapMetaCap(cap, user, post);
    return requiredCaps.every((c) => user.allcaps[c] === true);
  }

  // Primitive capability check
  return user.allcaps[cap] === true;
}

/**
 * Checks if a post type is allowed in the REST API.
 * Mirrors check_is_post_type_allowed() - for the 'post' type
 * this always returns true since posts have show_in_rest = true.
 */
export function checkIsPostTypeAllowed(postType: string): boolean {
  // In WordPress, the 'post' and 'page' types have show_in_rest = true.
  // Attachment also has show_in_rest = true.
  const allowedTypes = ['post', 'page', 'attachment'];
  return allowedTypes.includes(postType);
}

/**
 * Checks if a user can edit a specific post.
 * Mirrors check_update_permission() from the posts controller.
 */
export function checkUpdatePermission(
  user: AuthenticatedUser,
  post: WpPostRow
): boolean {
  if (!checkIsPostTypeAllowed(post.post_type)) {
    return false;
  }
  return userCan(user, 'edit_post', post);
}
