/** @type {import('next').NextConfig} */
const isDev = process.env.NODE_ENV === 'development';

const nextConfig = {
  output: isDev ? undefined : 'export',
  trailingSlash: true,
  basePath: '/transaction-retry',
  images: { unoptimized: true },
  ...(isDev && {
    async rewrites() {
      return [
        {
          source: '/api/transaction-retry/:path*',
          destination: 'http://localhost:3001/api/transaction-retry/:path*',
          basePath: false,
        },
      ];
    },
  }),
};

module.exports = nextConfig;
