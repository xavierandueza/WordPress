export interface DatabaseConfig {
  host: string;
  port: number;
  user: string;
  password: string;
  database: string;
  tablePrefix: string;
  connectionLimit: number;
}

export const dbConfig: DatabaseConfig = {
  host: process.env.WP_DB_HOST || 'localhost',
  port: parseInt(process.env.WP_DB_PORT || '3306', 10),
  user: process.env.WP_DB_USER || 'root',
  password: process.env.WP_DB_PASSWORD || '',
  database: process.env.WP_DB_NAME || 'wordpress',
  tablePrefix: process.env.WP_TABLE_PREFIX || 'wp_',
  connectionLimit: parseInt(process.env.WP_DB_POOL_SIZE || '10', 10),
};
