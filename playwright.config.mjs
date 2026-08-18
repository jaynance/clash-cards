import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests',
  testMatch: /scanner-regression\.spec\.mjs/,
  workers: 1,
  reporter: [['list'], ['html', { outputFolder: 'test-results/html', open: 'never' }]],
  use: {
    baseURL: 'http://127.0.0.1:4173',
    headless: true,
    viewport: { width: 1600, height: 1000 },
    trace: 'retain-on-failure'
  },
  webServer: {
    command: 'python3 -m http.server 4173 --bind 127.0.0.1',
    url: 'http://127.0.0.1:4173/tests/scanner-harness.html',
    reuseExistingServer: true,
    timeout: 30_000
  }
});
