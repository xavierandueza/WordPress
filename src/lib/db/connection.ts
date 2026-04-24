import mysql, { Pool, PoolConnection } from 'mysql2/promise';
import { dbConfig } from '@/config/database';

let pool: Pool | null = null;

/**
 * Returns a singleton MySQL connection pool.
 * Uses the WordPress database credentials from environment config.
 */
export function getPool(): Pool {
  if (!pool) {
    pool = mysql.createPool({
      host: dbConfig.host,
      port: dbConfig.port,
      user: dbConfig.user,
      password: dbConfig.password,
      database: dbConfig.database,
      connectionLimit: dbConfig.connectionLimit,
      waitForConnections: true,
      queueLimit: 0,
      // Match WordPress default charset
      charset: 'utf8mb4',
    });
  }
  return pool;
}

/**
 * Execute a parameterized query against the pool.
 */
export async function query<T extends mysql.RowDataPacket[]>(
  sql: string,
  params?: unknown[]
): Promise<T> {
  const [rows] = await getPool().execute<T>(sql, params);
  return rows;
}

/**
 * Execute an INSERT/UPDATE/DELETE statement against the pool.
 */
export async function execute(
  sql: string,
  params?: unknown[]
): Promise<mysql.ResultSetHeader> {
  const [result] = await getPool().execute<mysql.ResultSetHeader>(sql, params);
  return result;
}

/**
 * Runs a callback within a MySQL transaction.
 * Automatically commits on success, rolls back on error.
 * The connection is released back to the pool in all cases.
 */
export async function withTransaction<T>(
  fn: (conn: PoolConnection) => Promise<T>
): Promise<T> {
  const conn = await getPool().getConnection();
  try {
    await conn.beginTransaction();
    const result = await fn(conn);
    await conn.commit();
    return result;
  } catch (err) {
    await conn.rollback();
    throw err;
  } finally {
    conn.release();
  }
}

/**
 * Returns the configured table prefix (default: 'wp_').
 */
export function getTablePrefix(): string {
  return dbConfig.tablePrefix;
}
