import { PoolConnection, RowDataPacket } from 'mysql2/promise';
import { serialize, unserialize } from 'php-serialize';
import { getTablePrefix } from '../connection';

/**
 * Retrieves an option value from the wp_options table.
 */
export async function getOption(
  conn: PoolConnection,
  name: string
): Promise<string | null> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT option_value FROM ${prefix}options WHERE option_name = ? LIMIT 1`,
    [name]
  );
  return rows[0]?.option_value ?? null;
}

/**
 * Updates an option value in the wp_options table.
 * Creates the option if it doesn't exist.
 */
export async function updateOption(
  conn: PoolConnection,
  name: string,
  value: string
): Promise<void> {
  const prefix = getTablePrefix();
  await conn.execute(
    `INSERT INTO ${prefix}options (option_name, option_value, autoload)
     VALUES (?, ?, 'yes')
     ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)`,
    [name, value]
  );
}

/**
 * Retrieves the list of sticky post IDs from the sticky_posts option.
 * WordPress stores this as a PHP-serialized array.
 */
export async function getStickyPosts(
  conn: PoolConnection
): Promise<number[]> {
  const raw = await getOption(conn, 'sticky_posts');
  if (!raw) return [];

  try {
    const unserialized = unserialize(raw);
    if (Array.isArray(unserialized)) {
      return unserialized.map(Number).filter((n) => !isNaN(n) && n > 0);
    }
    // PHP serialized arrays may deserialize as objects with numeric keys
    if (typeof unserialized === 'object' && unserialized !== null) {
      return Object.values(unserialized as Record<string, unknown>)
        .map(Number)
        .filter((n) => !isNaN(n) && n > 0);
    }
    return [];
  } catch {
    return [];
  }
}

/**
 * Updates the sticky_posts option with the provided post ID list.
 * Serializes as a PHP array for compatibility with the WordPress core.
 */
export async function setStickyPosts(
  conn: PoolConnection,
  postIds: number[]
): Promise<void> {
  const serialized = serialize(postIds);
  await updateOption(conn, 'sticky_posts', serialized);
}

/**
 * Checks whether a specific post is sticky.
 */
export async function isSticky(
  conn: PoolConnection,
  postId: number
): Promise<boolean> {
  const stickyPosts = await getStickyPosts(conn);
  return stickyPosts.includes(postId);
}
