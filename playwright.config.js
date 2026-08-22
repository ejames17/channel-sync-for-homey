import { defineConfig, devices } from '@playwright/test';
import path from 'path';
import fs from 'fs';

// Natively load local .env credentials if present (zero-dependency, no dotenv package needed!)
const envPath = path.join(process.cwd(), '.env');
if (fs.existsSync(envPath)) {
	if (typeof process.loadEnvFile === 'function') {
		process.loadEnvFile(envPath);
	} else {
		// Fallback: Parse basic key-value pairs from .env for older Node versions
		try {
			const envContent = fs.readFileSync(envPath, 'utf8');
			envContent.split(/\r?\n/).forEach(line => {
				const trimmed = line.trim();
				if (trimmed && !trimmed.startsWith('#')) {
					const parts = trimmed.split('=');
					const key = parts[0] ? parts[0].trim() : '';
					const val = parts.slice(1).join('=') ? parts.slice(1).join('=').trim() : '';
					if (key && val) {
						process.env[key] = val;
					}
				}
			});
		} catch (e) {
			// Fail-safe silent catch
		}
	}
}

/**
 * Playwright E2E configuration file for local WordPress plugin testing.
 *
 * @see https://playwright.dev/docs/test-configuration
 */
export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30000,
	expect: {
		timeout: 5000,
	},
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1, // Run tests sequentially to avoid database collision issues in local WordPress
	reporter: 'list',
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://reservationresources.test',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},
	projects: [
		// Setup project to login once and cache storage state
		{
			name: 'setup',
			testMatch: /auth\.setup\.js/,
		},
		// Chromium browser tests running with loaded storage state
		{
			name: 'chromium',
			use: {
				...devices['Desktop Chrome'],
				storageState: path.join(process.cwd(), 'playwright/.auth/user.json'),
			},
			dependencies: ['setup'],
		},
	],
});
