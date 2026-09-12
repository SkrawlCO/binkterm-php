import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

// Vendored canonical game packages import their sibling engine package by its
// original upstream specifier ('@parlour/engine') — that import is left
// untouched (see PROVENANCE.md) rather than rewritten to a relative path, so
// the vendored files stay byte-identical to upstream. This alias is the only
// thing that makes that resolve outside the original pnpm workspace.
export default defineConfig({
  resolve: {
    alias: {
      '@parlour/engine': fileURLToPath(
        new URL('./vendor/packages/engine/src/index.ts', import.meta.url),
      ),
    },
  },
  test: {
    include: ['tests/**/*.test.ts', 'terminal/tests/**/*.test.ts', 'persistence/**/*.test.ts'],
  },
});
