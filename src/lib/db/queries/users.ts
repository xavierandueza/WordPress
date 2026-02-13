import { PoolConnection, RowDataPacket } from 'mysql2/promise';
import { unserialize } from 'php-serialize';
import { getTablePrefix } from '../connection';
import { WpUserRow } from '@/lib/types';

/**
 * Retrieves a user by ID from the wp_users table.
 */
export async function getUserById(
  conn: PoolConnection,
  id: number
): Promise<WpUserRow | null> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<(WpUserRow & RowDataPacket)[]>(
    `SELECT * FROM ${prefix}users WHERE ID = ?`,
    [id]
  );
  return rows[0] ?? null;
}

/**
 * Retrieves a user by login name.
 */
export async function getUserByLogin(
  conn: PoolConnection,
  login: string
): Promise<WpUserRow | null> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<(WpUserRow & RowDataPacket)[]>(
    `SELECT * FROM ${prefix}users WHERE user_login = ?`,
    [login]
  );
  return rows[0] ?? null;
}

/**
 * Checks whether a user with the given ID exists.
 */
export async function userExists(
  conn: PoolConnection,
  userId: number
): Promise<boolean> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT 1 FROM ${prefix}users WHERE ID = ? LIMIT 1`,
    [userId]
  );
  return rows.length > 0;
}

/**
 * Retrieves the capabilities meta for a user.
 * Stored in wp_usermeta under the key {prefix}capabilities
 * as a PHP-serialized associative array of role => true.
 *
 * Returns the raw role=>boolean mapping.
 */
export async function getUserCapabilities(
  conn: PoolConnection,
  userId: number
): Promise<Record<string, boolean>> {
  const prefix = getTablePrefix();
  const metaKey = `${prefix}capabilities`;

  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT meta_value FROM ${prefix}usermeta
     WHERE user_id = ? AND meta_key = ?
     LIMIT 1`,
    [userId, metaKey]
  );

  if (rows.length === 0 || !rows[0].meta_value) {
    return {};
  }

  try {
    const unserialized = unserialize(rows[0].meta_value);
    if (typeof unserialized === 'object' && unserialized !== null) {
      const caps: Record<string, boolean> = {};
      for (const [key, value] of Object.entries(
        unserialized as Record<string, unknown>
      )) {
        caps[key] = Boolean(value);
      }
      return caps;
    }
    return {};
  } catch {
    return {};
  }
}

/**
 * Retrieves the WordPress user roles definition from the
 * {prefix}user_roles option. This maps role names to their
 * display names and capabilities.
 */
export async function getRoleDefinitions(
  conn: PoolConnection
): Promise<Record<string, { name: string; capabilities: Record<string, boolean> }>> {
  const prefix = getTablePrefix();
  const optionKey = `${prefix}user_roles`;

  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT option_value FROM ${prefix}options
     WHERE option_name = ?
     LIMIT 1`,
    [optionKey]
  );

  if (rows.length === 0 || !rows[0].option_value) {
    return {};
  }

  try {
    const unserialized = unserialize(rows[0].option_value);
    if (typeof unserialized === 'object' && unserialized !== null) {
      return unserialized as Record<
        string,
        { name: string; capabilities: Record<string, boolean> }
      >;
    }
    return {};
  } catch {
    return {};
  }
}

/**
 * Retrieves application passwords for a user.
 * Stored in wp_usermeta under the key _application_passwords
 * as a PHP-serialized array of password entries.
 */
export async function getApplicationPasswords(
  conn: PoolConnection,
  userId: number
): Promise<Array<{ uuid: string; name: string; password: string }>> {
  const prefix = getTablePrefix();

  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT meta_value FROM ${prefix}usermeta
     WHERE user_id = ? AND meta_key = '_application_passwords'
     LIMIT 1`,
    [userId]
  );

  if (rows.length === 0 || !rows[0].meta_value) {
    return [];
  }

  try {
    const unserialized = unserialize(rows[0].meta_value);
    if (Array.isArray(unserialized)) {
      return unserialized as Array<{
        uuid: string;
        name: string;
        password: string;
      }>;
    }
    return [];
  } catch {
    return [];
  }
}
