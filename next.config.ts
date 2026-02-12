import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  // Run on a separate port from the PHP WordPress instance
  // API routes are served under /api/sites/[site]/posts/[postId]
  poweredByHeader: false,
};

export default nextConfig;
