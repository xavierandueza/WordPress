/**
 * Parses an ISO 8601 date string and returns both local and UTC
 * representations in MySQL datetime format ('YYYY-MM-DD HH:mm:ss').
 *
 * Port of rest_get_date_with_gmt() from wp-includes/rest-api.php.
 *
 * @param dateString - ISO 8601 date string (e.g., '2024-01-15T10:30:00')
 * @param isUtc - If true, treats the input as UTC and computes local time.
 *                If false (default), treats the input as local time and computes UTC.
 * @param gmtOffset - The site's GMT offset in hours (from WordPress gmt_offset option).
 *                     Defaults to 0.
 * @returns Tuple of [localDatetime, utcDatetime] in MySQL format, or null if invalid.
 */
export function restGetDateWithGmt(
  dateString: string,
  isUtc: boolean = false,
  gmtOffset: number = 0
): [string, string] | null {
  const parsed = new Date(dateString);
  if (isNaN(parsed.getTime())) {
    return null;
  }

  if (isUtc) {
    // Input is UTC, compute local
    const utcDatetime = formatMysqlDatetime(parsed);
    const localDate = new Date(
      parsed.getTime() + gmtOffset * 60 * 60 * 1000
    );
    const localDatetime = formatMysqlDatetime(localDate);
    return [localDatetime, utcDatetime];
  } else {
    // Input is local time, compute UTC
    const localDatetime = formatMysqlDatetime(parsed);
    const utcDate = new Date(
      parsed.getTime() - gmtOffset * 60 * 60 * 1000
    );
    const utcDatetime = formatMysqlDatetime(utcDate);
    return [localDatetime, utcDatetime];
  }
}

/**
 * Formats a Date object as a MySQL datetime string.
 */
function formatMysqlDatetime(date: Date): string {
  const year = date.getUTCFullYear();
  const month = String(date.getUTCMonth() + 1).padStart(2, '0');
  const day = String(date.getUTCDate()).padStart(2, '0');
  const hours = String(date.getUTCHours()).padStart(2, '0');
  const minutes = String(date.getUTCMinutes()).padStart(2, '0');
  const seconds = String(date.getUTCSeconds()).padStart(2, '0');
  return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

/**
 * Converts a MySQL datetime string to an ISO 8601 / RFC 3339 string
 * suitable for REST API responses.
 *
 * Mirrors mysql_to_rfc3339() behavior from WordPress.
 *
 * Returns null for the zero date ('0000-00-00 00:00:00').
 */
export function mysqlToRfc3339(datetime: string): string | null {
  if (!datetime || datetime === '0000-00-00 00:00:00') {
    return null;
  }
  // MySQL format: 'YYYY-MM-DD HH:mm:ss' -> ISO 8601: 'YYYY-MM-DDTHH:mm:ss'
  return datetime.replace(' ', 'T');
}
