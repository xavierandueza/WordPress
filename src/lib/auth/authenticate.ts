import { PoolConnection } from 'mysql2/promise';
import { compare } from 'bcryptjs';
import { AuthenticatedUser } from '@/lib/types';
import { getUserByLogin } from '@/lib/db/queries/users';
import {
  getUserCapabilities,
  getRoleDefinitions,
  getApplicationPasswords,
} from '@/lib/db/queries/users';

/**
 * Authenticates an incoming request using WordPress Application Passwords.
 *
 * Application Passwords use HTTP Basic Auth where:
 * - Username is the WordPress user_login
 * - Password is a space-separated application password (spaces are stripped)
 *
 * The hashed passwords are stored in wp_usermeta under '_application_passwords'
 * as a PHP-serialized array.
 *
 * Returns an AuthenticatedUser on success, or null if authentication fails.
 */
export async function authenticateRequest(
  authHeader: string | null,
  conn: PoolConnection
): Promise<AuthenticatedUser | null> {
  if (!authHeader || !authHeader.startsWith('Basic ')) {
    return null;
  }

  const base64Credentials = authHeader.slice(6);
  let decoded: string;
  try {
    decoded = Buffer.from(base64Credentials, 'base64').toString('utf-8');
  } catch {
    return null;
  }

  const colonIndex = decoded.indexOf(':');
  if (colonIndex === -1) {
    return null;
  }

  const username = decoded.slice(0, colonIndex);
  // Application passwords may contain spaces for readability; strip them
  const password = decoded.slice(colonIndex + 1).replace(/\s/g, '');

  if (!username || !password) {
    return null;
  }

  // Look up the user by login
  const user = await getUserByLogin(conn, username);
  if (!user) {
    return null;
  }

  // Get application passwords for this user
  const appPasswords = await getApplicationPasswords(conn, user.ID);
  if (appPasswords.length === 0) {
    return null;
  }

  // Try to match the provided password against stored hashes
  let matched = false;
  for (const appPassword of appPasswords) {
    const isMatch = await compare(password, appPassword.password);
    if (isMatch) {
      matched = true;
      break;
    }
  }

  if (!matched) {
    return null;
  }

  // Build the authenticated user with resolved capabilities
  return resolveUserCapabilities(conn, user.ID, user.user_login, user.user_email);
}

/**
 * Resolves a user's full capability set by combining their direct
 * capabilities with all capabilities inherited from their roles.
 *
 * This mirrors the logic in WP_User::get_role_caps() where:
 * 1. User meta {prefix}capabilities gives role => true mappings
 * 2. Each role's capabilities are read from {prefix}user_roles option
 * 3. All capabilities are merged together
 */
export async function resolveUserCapabilities(
  conn: PoolConnection,
  userId: number,
  login: string,
  email: string
): Promise<AuthenticatedUser> {
  // Get the user's role assignments from usermeta
  const userCaps = await getUserCapabilities(conn, userId);
  const roles: string[] = [];

  for (const [key, value] of Object.entries(userCaps)) {
    if (value) {
      roles.push(key);
    }
  }

  // Get role definitions to resolve capabilities
  const roleDefinitions = await getRoleDefinitions(conn);
  const allcaps: Record<string, boolean> = {};

  // Merge capabilities from all assigned roles
  for (const role of roles) {
    const roleDef = roleDefinitions[role];
    if (roleDef?.capabilities) {
      for (const [cap, granted] of Object.entries(roleDef.capabilities)) {
        if (granted) {
          allcaps[cap] = true;
        }
      }
    }
  }

  return {
    id: userId,
    login,
    email,
    roles,
    capabilities: userCaps,
    allcaps,
  };
}
