import { PoolConnection, RowDataPacket } from 'mysql2/promise';
import { getTablePrefix } from '../connection';
import { WpPostRow, WpPostUpdateData } from '@/lib/types';

/**
 * Retrieves a post by ID from the wp_posts table.
 * Returns null if not found.
 */
export async function getPostById(
  conn: PoolConnection,
  id: number
): Promise<WpPostRow | null> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<(WpPostRow & RowDataPacket)[]>(
    `SELECT * FROM ${prefix}posts WHERE ID = ?`,
    [id]
  );
  return rows[0] ?? null;
}

/**
 * Updates a post in the database with only the provided fields.
 * Always sets post_modified and post_modified_gmt to the current time.
 *
 * Mirrors the behavior of wp_update_post() which merges new fields
 * over existing ones and delegates to wp_insert_post().
 */
export async function updatePost(
  conn: PoolConnection,
  id: number,
  data: WpPostUpdateData
): Promise<boolean> {
  const prefix = getTablePrefix();

  // Build SET clause dynamically from only the provided fields
  const setClauses: string[] = [];
  const params: unknown[] = [];

  const fieldMap: Record<string, keyof WpPostUpdateData> = {
    post_title: 'post_title',
    post_content: 'post_content',
    post_excerpt: 'post_excerpt',
    post_status: 'post_status',
    post_date: 'post_date',
    post_date_gmt: 'post_date_gmt',
    post_name: 'post_name',
    post_author: 'post_author',
    post_password: 'post_password',
    post_parent: 'post_parent',
    menu_order: 'menu_order',
    comment_status: 'comment_status',
    ping_status: 'ping_status',
  };

  for (const [column, key] of Object.entries(fieldMap)) {
    if (data[key] !== undefined) {
      setClauses.push(`${column} = ?`);
      params.push(data[key]);
    }
  }

  // Always update modification timestamps
  setClauses.push('post_modified = NOW()');
  setClauses.push('post_modified_gmt = UTC_TIMESTAMP()');

  if (setClauses.length === 2) {
    // Only timestamp updates, nothing else changed — still valid
    // This can happen if a request only modifies related data (terms, meta, etc.)
    // but doesn't change any core post fields.
  }

  params.push(id);

  const sql = `UPDATE ${prefix}posts SET ${setClauses.join(', ')} WHERE ID = ?`;
  const [result] = await conn.execute(sql, params);
  const header = result as { affectedRows: number };
  return header.affectedRows > 0;
}

/**
 * Checks if a post slug already exists for a given post type,
 * excluding the current post ID.
 *
 * Mirrors the uniqueness check in wp_unique_post_slug().
 */
export async function checkSlugExists(
  conn: PoolConnection,
  slug: string,
  postType: string,
  excludePostId: number,
  postParent: number
): Promise<boolean> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT ID FROM ${prefix}posts
     WHERE post_name = ? AND post_type = ? AND ID != ? AND post_parent = ?
     LIMIT 1`,
    [slug, postType, excludePostId, postParent]
  );
  return rows.length > 0;
}

/**
 * Checks if a post exists and is of type 'attachment'.
 * Used to validate featured media IDs.
 */
export async function isValidAttachment(
  conn: PoolConnection,
  attachmentId: number
): Promise<boolean> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT ID FROM ${prefix}posts
     WHERE ID = ? AND post_type = 'attachment'
     LIMIT 1`,
    [attachmentId]
  );
  return rows.length > 0;
}
