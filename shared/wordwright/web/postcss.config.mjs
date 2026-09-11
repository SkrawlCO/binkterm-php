/**
 * Tailwind via PostCSS rather than the Vite plugin.
 *
 * `@tailwindcss/vite` holds the whole candidate scan in memory during
 * transform, which exceeds the container limit here and kills the build. The
 * PostCSS integration is officially supported, produces identical output, and
 * streams instead. See ADR-018.
 */
export default {
  plugins: {
    '@tailwindcss/postcss': {},
  },
};
