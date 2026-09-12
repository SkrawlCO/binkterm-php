import type { NextConfig } from 'next';

// Production deployment adaptation (Slice 5): served from a WebDoor subpath,
// not the site root, so the static export's own root-absolute asset URLs
// need Next's basePath — the standard fix for exactly this deployment shape.
// See ../README.md "web/ provenance" for the full adapted-file ledger.
const nextConfig: NextConfig = {
  output: 'export',
  trailingSlash: true,
  basePath: '/webdoors/parlour/assets',
  allowedDevOrigins: ['127.0.0.1'],
  transpilePackages: ['@parlour/engine', '@parlour/game-blitz', '@parlour/game-wildpile'],
  images: { unoptimized: true },
};

export default nextConfig;
