import { PoolConnection, RowDataPacket } from 'mysql2/promise';
import { getTablePrefix } from '../connection';
import { WpTerm } from '@/lib/types';

/**
 * Retrieves all terms assigned to an object for a specific taxonomy.
 * Joins wp_terms, wp_term_taxonomy, and wp_term_relationships.
 */
export async function getObjectTerms(
  conn: PoolConnection,
  objectId: number,
  taxonomy: string
): Promise<WpTerm[]> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<(WpTerm & RowDataPacket)[]>(
    `SELECT t.term_id, t.name, t.slug,
            tt.taxonomy, tt.description, tt.parent, tt.count
     FROM ${prefix}terms t
     INNER JOIN ${prefix}term_taxonomy tt ON t.term_id = tt.term_id
     INNER JOIN ${prefix}term_relationships tr ON tt.term_taxonomy_id = tr.term_taxonomy_id
     WHERE tr.object_id = ? AND tt.taxonomy = ?`,
    [objectId, taxonomy]
  );
  return rows;
}

/**
 * Replaces all term relationships for an object within a given taxonomy.
 * This mirrors WordPress wp_set_object_terms() with the default behavior
 * of replacing (not appending) terms.
 *
 * Steps:
 * 1. Find existing term_taxonomy_ids for this object in this taxonomy
 * 2. Remove them from wp_term_relationships
 * 3. Decrement counts for removed terms
 * 4. Insert new relationships
 * 5. Increment counts for added terms
 */
export async function setObjectTerms(
  conn: PoolConnection,
  objectId: number,
  termIds: number[],
  taxonomy: string
): Promise<void> {
  const prefix = getTablePrefix();

  // Get term_taxonomy_ids for the current assignments in this taxonomy
  const [currentRelations] = await conn.execute<RowDataPacket[]>(
    `SELECT tr.term_taxonomy_id, tt.term_id
     FROM ${prefix}term_relationships tr
     INNER JOIN ${prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
     WHERE tr.object_id = ? AND tt.taxonomy = ?`,
    [objectId, taxonomy]
  );

  const currentTtIds = currentRelations.map(
    (r) => r.term_taxonomy_id as number
  );
  const currentTermIds = currentRelations.map((r) => r.term_id as number);

  // Remove all existing relationships for this taxonomy
  if (currentTtIds.length > 0) {
    const placeholders = currentTtIds.map(() => '?').join(',');
    await conn.execute(
      `DELETE FROM ${prefix}term_relationships
       WHERE object_id = ? AND term_taxonomy_id IN (${placeholders})`,
      [objectId, ...currentTtIds]
    );

    // Decrement counts for removed terms
    await conn.execute(
      `UPDATE ${prefix}term_taxonomy
       SET count = GREATEST(count - 1, 0)
       WHERE term_taxonomy_id IN (${placeholders})`,
      [...currentTtIds]
    );
  }

  // Insert new relationships
  for (const termId of termIds) {
    // Look up the term_taxonomy_id for this term in this taxonomy
    const [ttRows] = await conn.execute<RowDataPacket[]>(
      `SELECT term_taxonomy_id FROM ${prefix}term_taxonomy
       WHERE term_id = ? AND taxonomy = ?`,
      [termId, taxonomy]
    );

    if (ttRows.length === 0) {
      continue; // Invalid term for this taxonomy, skip
    }

    const ttId = ttRows[0].term_taxonomy_id;

    await conn.execute(
      `INSERT INTO ${prefix}term_relationships (object_id, term_taxonomy_id, term_order)
       VALUES (?, ?, 0)
       ON DUPLICATE KEY UPDATE term_order = term_order`,
      [objectId, ttId]
    );

    // Increment count
    await conn.execute(
      `UPDATE ${prefix}term_taxonomy SET count = count + 1
       WHERE term_taxonomy_id = ?`,
      [ttId]
    );
  }
}

/**
 * Checks if a term exists in a given taxonomy.
 */
export async function termExists(
  conn: PoolConnection,
  termId: number,
  taxonomy: string
): Promise<boolean> {
  const prefix = getTablePrefix();
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT 1 FROM ${prefix}terms t
     INNER JOIN ${prefix}term_taxonomy tt ON t.term_id = tt.term_id
     WHERE t.term_id = ? AND tt.taxonomy = ?
     LIMIT 1`,
    [termId, taxonomy]
  );
  return rows.length > 0;
}

/**
 * Gets the term_id for a post format slug (e.g., 'post-format-aside').
 * Post formats are stored as terms in the 'post_format' taxonomy.
 */
export async function getPostFormatTermId(
  conn: PoolConnection,
  formatSlug: string
): Promise<number | null> {
  const prefix = getTablePrefix();
  const termSlug = `post-format-${formatSlug}`;
  const [rows] = await conn.execute<RowDataPacket[]>(
    `SELECT t.term_id FROM ${prefix}terms t
     INNER JOIN ${prefix}term_taxonomy tt ON t.term_id = tt.term_id
     WHERE t.slug = ? AND tt.taxonomy = 'post_format'
     LIMIT 1`,
    [termSlug]
  );
  return rows[0]?.term_id ?? null;
}
