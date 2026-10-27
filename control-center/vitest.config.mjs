import { defineConfig } from 'vitest/config';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const controlCenterRoot = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  root: controlCenterRoot,
  resolve: { alias: { '@': resolve(controlCenterRoot) } },
  esbuild: { jsx: 'automatic' },
  test: {
    environment: 'jsdom',
    setupFiles: [resolve(controlCenterRoot, 'tests/setup.ts')],
    css: true,
  },
});
