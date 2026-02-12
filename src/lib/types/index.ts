/**
 * Database row shape matching the wp_posts table schema.
 * Column types mirror wp-admin/includes/schema.php lines 159-189.
 */
export interface WpPostRow {
  ID: number;
  post_author: number;
  post_date: string;
  post_date_gmt: string;
  post_content: string;
  post_title: string;
  post_excerpt: string;
  post_status: string;
  comment_status: string;
  ping_status: string;
  post_password: string;
  post_name: string;
  to_ping: string;
  pinged: string;
  post_modified: string;
  post_modified_gmt: string;
  post_content_filtered: string;
  post_parent: number;
  guid: string;
  menu_order: number;
  post_type: string;
  post_mime_type: string;
  comment_count: number;
}

/**
 * REST API response shape for a post, matching the output of
 * WP_REST_Posts_Controller::prepare_item_for_response().
 */
export interface WpPostResponse {
  id: number;
  date: string | null;
  date_gmt: string | null;
  guid: { rendered: string; raw: string };
  modified: string;
  modified_gmt: string;
  slug: string;
  status: string;
  type: string;
  link: string;
  title: { raw: string; rendered: string };
  content: {
    raw: string;
    rendered: string;
    protected: boolean;
    block_version: number;
  };
  excerpt: {
    raw: string;
    rendered: string;
    protected: boolean;
  };
  author: number;
  featured_media: number;
  comment_status: string;
  ping_status: string;
  sticky: boolean;
  template: string;
  format: string;
  meta: Record<string, unknown>;
  categories: number[];
  tags: number[];
  password?: string;
  permalink_template?: string;
  generated_slug?: string;
  class_list?: string[];
}

/**
 * Database row shape for wp_users table.
 */
export interface WpUserRow {
  ID: number;
  user_login: string;
  user_pass: string;
  user_nicename: string;
  user_email: string;
  user_url: string;
  user_registered: string;
  user_activation_key: string;
  user_status: number;
  display_name: string;
}

/**
 * Authenticated user with resolved capabilities from the database.
 * Capabilities are loaded from wp_usermeta {prefix}capabilities
 * and resolved through the role definitions in {prefix}user_roles option.
 */
export interface AuthenticatedUser {
  id: number;
  login: string;
  email: string;
  roles: string[];
  /** Direct capabilities assigned to this user via their roles. */
  capabilities: Record<string, boolean>;
  /** Fully resolved capabilities including all role-inherited caps. */
  allcaps: Record<string, boolean>;
}

/**
 * WordPress taxonomy term from the database.
 * Combines data from wp_terms and wp_term_taxonomy.
 */
export interface WpTerm {
  term_id: number;
  name: string;
  slug: string;
  taxonomy: string;
  description: string;
  parent: number;
  count: number;
}

/**
 * Post type capability mapping.
 * Mirrors the cap object from get_post_type_object() in PHP.
 */
export interface PostTypeCaps {
  edit_post: string;
  read_post: string;
  delete_post: string;
  edit_posts: string;
  edit_others_posts: string;
  delete_posts: string;
  publish_posts: string;
  read_private_posts: string;
  read: string;
  delete_private_posts: string;
  delete_published_posts: string;
  delete_others_posts: string;
  edit_private_posts: string;
  edit_published_posts: string;
  create_posts: string;
}

/**
 * Default capabilities for the 'post' post type.
 */
export const POST_TYPE_CAPS: PostTypeCaps = {
  edit_post: 'edit_post',
  read_post: 'read_post',
  delete_post: 'delete_post',
  edit_posts: 'edit_posts',
  edit_others_posts: 'edit_others_posts',
  delete_posts: 'delete_posts',
  publish_posts: 'publish_posts',
  read_private_posts: 'read_private_posts',
  read: 'read',
  delete_private_posts: 'delete_private_posts',
  delete_published_posts: 'delete_published_posts',
  delete_others_posts: 'delete_others_posts',
  edit_private_posts: 'edit_private_posts',
  edit_published_posts: 'edit_published_posts',
  create_posts: 'edit_posts',
};

/**
 * WordPress application password entry as stored in the
 * _application_passwords usermeta (PHP serialized array).
 */
export interface WpApplicationPassword {
  uuid: string;
  app_id: string;
  name: string;
  password: string;
  created: number;
  last_used: number | null;
  last_ip: string | null;
}

/**
 * Fields that can be updated on a wp_posts row.
 * Subset of WpPostRow with only the mutable columns.
 */
export type WpPostUpdateData = Partial<
  Pick<
    WpPostRow,
    | 'post_title'
    | 'post_content'
    | 'post_excerpt'
    | 'post_status'
    | 'post_date'
    | 'post_date_gmt'
    | 'post_name'
    | 'post_author'
    | 'post_password'
    | 'post_parent'
    | 'menu_order'
    | 'comment_status'
    | 'ping_status'
  >
>;
