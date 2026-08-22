import { test as setup, expect } from '@playwright/test';
import path from 'path';
import fs from 'fs';

const authFile = path.join(process.cwd(), 'playwright/.auth/user.json');

/**
 * Global Setup Auth Hook.
 *
 * Runs once before spec execution to authenticate with WordPress (/wp-login.php)
 * and preserve session state cookie files.
 */
setup('authenticate as admin', async ({ page }) => {
	const username = process.env.WP_ADMIN_USER || 'admin';
	const password = process.env.WP_ADMIN_PASS || 'admin';
	const wpPrefix = process.env.WP_PATH_PREFIX || '';

	// Navigate to standard WordPress login form (prefixed for Bedrock if set)
	await page.goto(`${wpPrefix}/wp-login.php`);

	// Check if already logged in (redirected to admin dashboard)
	if (page.url().includes('/wp-admin')) {
		// Ensure output directory exists defensively before writing
		const dir = path.dirname(authFile);
		if (!fs.existsSync(dir)) {
			fs.mkdirSync(dir, { recursive: true });
		}
		await page.context().storageState({ path: authFile });
		return;
	}

	// Fill and submit login form securely
	await page.fill('#user_login', username);
	await page.fill('#user_pass', password);
	await page.click('#wp-submit');

	// Wait for redirection and assert successful admin dashboard entry
	await expect(page).toHaveURL(/wp-admin/);

	// Ensure output directory exists defensively before writing
	const dir = path.dirname(authFile);
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true });
	}

	// Persist authenticated state cookies to file
	await page.context().storageState({ path: authFile });
});
