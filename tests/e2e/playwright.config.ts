import { defineConfig } from '@playwright/test';
import path from 'node:path';
import dotenv from 'dotenv';

// Credenciales y URL de la VM en tests/e2e/.env (no se versiona; ver .env.example).
dotenv.config({ path: path.join(__dirname, '.env'), quiet: true });

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,       // comparten la misma BD de testing
  workers: 1,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.SIRES_URL,
    locale: 'es-AR',
    timezoneId: 'America/Argentina/Buenos_Aires',
    trace: 'retain-on-failure',
    screenshot: 'off',
  },
  projects: [
    { name: 'setup', testMatch: /auth\.setup\.ts/ },
    { name: 'e2e', dependencies: ['setup'], testIgnore: /auth\.setup\.ts/ },
  ],
});
