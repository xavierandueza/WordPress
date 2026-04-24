import { PoolConnection, RowDataPacket } from 'mysql2/promise';
import { getTablePrefix } from '../connection';

/**
 * Retrieves a single meta value for a post.
 */
export async function getPostMeta(
  conn: PoolConnection,
  postId: number,
  metaKey: string
): Promise<string | null> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT meta_value FROM ${prefix}postmeta
     WHERE post_id = ? AND meta_key = ?
     LIMIT 1`,
    [postId, metaKey]
  );
  return rows[0]?.meta_value ?? null;
}

/**
 * Retrieves all meta values for a post as key-value pairs.
 * For duplicate keys, returns the first value.
 */
export async function getAllPostMeta(
  conn: PoolConnection,
  postId: number
): Promise<Record<string, string>> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT meta_key, meta_value FROM ${prefix}postmeta
     WHERE post_id = ?`,
    [postId]
  );
  const meta: Record<string, string> = {};
  for (const row of rows) {
    if (!(row.meta_key in meta)) {
      meta[row.meta_key] = row.meta_value;
    }
  }
  return meta;
}

/**
 * Updates a meta value for a post. If the meta key doesn't exist,
 * inserts a new row. If it exists, updates the first matching row.
 *
 * Mirrors WordPress update_post_meta() behavior.
 */
export async function updatePostMeta(
  conn: PoolConnection,
  postId: number,
  metaKey: string,
  metaValue: string
): Promise<void> {
  const prefix = getTablePrefix();

  // Check if meta key already exists for this post
  const [existing] = await conn.execute<RowDataPacket[]>(
    `SELECT meta_id FROM ${prefix}postmeta
     WHERE post_id = ? AND meta_key = ?
     LIMIT 1`,
    [postId, metaKey]
  );

  if (existing.length > 0) {
    await conn.execute(
      `UPDATE ${prefix}postmeta SET meta_value = ?
       WHERE meta_id = ?`,
      [metaValue, existing[0].meta_id]
    );
  } else {
    await conn.execute(
      `INSERT INTO ${prefix}postmeta (post_id, meta_key, meta_value)
       VALUES (?, ?, ?)`,
      [postId, metaKey, metaValue]
    );
  }
}

/**
 * Deletes all instances of a meta key for a post.
 * Mirrors WordPress delete_post_meta() behavior.
 */
export async function deletePostMeta(
  conn: PoolConnection,
  postId: number,
  metaKey: string
): Promise<void> {
  const prefix = getTablePrefix();
  await conn.execute(
    `DELETE FROM ${prefix}postmeta
     WHERE post_id = ? AND meta_key = ?`,
    [postId, metaKey]
  );
}
