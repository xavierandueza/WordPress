import { NextResponse } from 'next/server';

/**
 * WordPress-compatible error class that produces REST API error responses
 * matching the format from WP_REST_Server::error_to_response().
 *
 * Response format:
 * {
 *   "code": "rest_cannot_edit",
 *   "message": "Sorry, you are not allowed to edit this post.",
 *   "data": { "status": 403 }
 * }
 */
export class WpError {
  constructor(
    public readonly code: string,
    public readonly message: string,
    public readonly data: { status: number; [key: string]: unknown } = {
      status: 500,
    }
  ) {}

  /**
   * Converts this error into a NextResponse with the appropriate
   * HTTP status code and JSON body.
   */
  toResponse(): NextResponse {
    return NextResponse.json(
      {
        code: this.code,
        message: this.message,
        data: this.data,
      },
      { status: this.data.status }
    );
  }
}

/**
 * Type guard to check if a value is a WpError instance.
 */
export function isWpError(value: unknown): value is WpError {
  return value instanceof WpError;
}

/**
 * Returns the HTTP status code for authorization failures.
 * Mirrors rest_authorization_required_code() in PHP which returns
 * 401 for unauthenticated users and 403 for authenticated but unauthorized.
 */
export function restAuthorizationRequiredCode(
  isAuthenticated: boolean
): number {
  return isAuthenticated ? 403 : 401;
}
