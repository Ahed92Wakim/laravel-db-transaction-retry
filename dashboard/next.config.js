/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'export',
  trailingSlash: true,
  basePath: '/transaction-retry',
  images: { unoptimized: true },
};

module.exports = nextConfig;
