import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
  output: 'standalone',
  experimental: { cpus: 1 },
  outputFileTracingRoot: process.cwd(),
};

export default nextConfig;
