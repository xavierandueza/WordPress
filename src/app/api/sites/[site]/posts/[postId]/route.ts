import { NextRequest, NextResponse } from 'next/server';
import { withTransaction } from '@/lib/db/connection';
import { authenticateRequest } from '@/lib/auth/authenticate';
import { postUpdateSchema } from '@/lib/validation/post-update-schema';
import { handlePostUpdate } from '@/lib/services/post-update.service';
import { WpError, isWpError } from '@/lib/errors/wp-error';

interface RouteParams {
  params: Promise<{
    site: string;
    postId: string;
  }>;
}

/**
 * POST /api/sites/[site]/posts/[postId]
 *
 * Updates an existing post. This is the TypeScript replacement for the
 * PHP endpoint at POST /wp/v2/posts/{id} (WP_REST_Server::EDITABLE).
 *
 * The original PHP implementation lived in:
 * - WP_REST_Posts_Controller::update_item() (lines 941-1052)
 * - WP_REST_Posts_Controller::update_item_permissions_check() (lines 890-931)
 *
 * Authentication: HTTP Basic Auth with WordPress Application Passwords.
 * Authorization: Requires 'edit_post' capability for the target post.
 *
 * Request body follows the WordPress REST API post schema — all fields
 * are optional (PATCH semantics).
 *
 * Response format matches the WordPress REST API post response shape.
 */
async function handleUpdate(
  request: NextRequest,
  { params }: RouteParams
): Promise<NextResponse> {
  const { postId: postIdStr } = await params;
  const postId = parseInt(postIdStr, 10);

  // Validate post ID is a positive integer
  if (isNaN(postId) || postId <= 0) {
    return new WpError('rest_post_invalid_id', 'Invalid post ID.', {
      status: 404,
    }).toResponse();
  }

  // Parse request body
  let rawBody: unknown;
  try {
    rawBody = await request.json();
  } catch {
    return new WpError(
      'rest_invalid_json',
      'Invalid JSON body.',
      { status: 400 }
    ).toResponse();
  }

  // Validate request body against the post update schema
  const parseResult = postUpdateSchema.safeParse(rawBody);
  if (!parseResult.success) {
    const firstIssue = parseResult.error.issues[0];
    return new WpError(
      'rest_invalid_param',
      `Invalid parameter: ${firstIssue.path.join('.')} - ${firstIssue.message}`,
      { status: 400 }
    ).toResponse();
  }
  const body = parseResult.data;

  // Execute the update within a transaction
  try {
    const result = await withTransaction(async (conn) => {
      // Authenticate the request
      const authHeader = request.headers.get('authorization');
      const user = await authenticateRequest(authHeader, conn);
      if (!user) {
        return new WpError(
          'rest_not_logged_in',
          'You are not currently logged in.',
          { status: 401 }
        );
      }

      // Perform the update
      return handlePostUpdate(conn, postId, body, user);
    });

    if (isWpError(result)) {
      return result.toResponse();
    }

    return NextResponse.json(result, { status: 200 });
  } catch (err) {
    const message =
      err instanceof Error ? err.message : 'An unexpected error occurred.';
    return new WpError('rest_internal_error', message, {
      status: 500,
    }).toResponse();
  }
}

// Export the handler for all editable HTTP methods.
// WordPress WP_REST_Server::EDITABLE maps to POST, PUT, and PATCH.
export const POST = handleUpdate;
export const PUT = handleUpdate;
export const PATCH = handleUpdate;
