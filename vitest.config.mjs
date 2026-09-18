import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  resolve: {
    alias: [
      // The import map of Configuration/JavaScriptModules.php
      {
        find: /^@priebera\/context-reporter\//,
        replacement: fileURLToPath(new URL('./Resources/Public/JavaScript/', import.meta.url)),
      },
    ],
  },
});
