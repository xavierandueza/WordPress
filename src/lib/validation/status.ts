import { AuthenticatedUser } from '@/lib/types';
import { WpError } from '@/lib/errors/wp-error';

/**
 * Validates and normalizes a post status parameter.
 *
 * Direct port of WP_REST_Posts_Controller::handle_status_param()
 * from class-wp-rest-posts-controller.php lines 1534-1567.
 *
 * Status validation rules:
 * - 'draft' and 'pending' are always allowed
 * - 'private' requires the publish_posts capability
 * - 'publish' and 'future' require the publish_posts capability
 * - Unknown statuses fall back to 'draft'
 */
export function validateAndNormalizeStatus(
  postStatus: string,
  user: AuthenticatedUser
): string | WpError {
  switch (postStatus) {
    case 'draft':
    case 'pending':
      return postStatus;

    case 'private':
      if (!user.allcaps['publish_posts']) {
        return new WpError(
          'rest_cannot_publish',
          'Sorry, you are not allowed to create private posts in this post type.',
          { status: user.allcaps['edit_posts'] ? 403 : 401 }
        );
      }
      return postStatus;

    case 'publish':
    case 'future':
      if (!user.allcaps['publish_posts']) {
        return new WpError(
          'rest_cannot_publish',
          'Sorry, you are not allowed to publish posts in this post type.',
          { status: user.allcaps['edit_posts'] ? 403 : 401 }
        );
      }
      return postStatus;

    default:
      // Unknown status defaults to draft, matching PHP behavior
      return 'draft';
  }
}
