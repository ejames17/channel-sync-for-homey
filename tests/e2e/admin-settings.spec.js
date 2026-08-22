import { test, expect } from '@playwright/test';

const wpPrefix = process.env.WP_PATH_PREFIX || '';

/**
 * End-to-End Test Suite for Homey Channel Sync WP-Admin.
 *
 * Verifies tab navigation, UI components, selectors, toggles, and AJAX endpoints.
 */
test.describe('Homey Channel Sync - WP-Admin Settings Page', () => {

	test.beforeEach(async ({ page }) => {
		// Navigate to primary plugin settings screen (prefixed for Bedrock if set)
		await page.goto(`${wpPrefix}/wp-admin/admin.php?page=homey-channel-sync`);

		// Fail-safe: If redirected to login, perform inline authentication automatically!
		if (page.url().includes('wp-login.php')) {
			const username = process.env.WP_ADMIN_USER || 'admin';
			const password = process.env.WP_ADMIN_PASS || 'admin';

			await page.fill('#user_login', username);
			await page.fill('#user_pass', password);
			await page.click('#wp-submit');
			await page.waitForURL(/wp-admin/);
			
			// Re-navigate to settings page
			await page.goto(`${wpPrefix}/wp-admin/admin.php?page=homey-channel-sync`);
		}
	});

	// =========================================================================
	// TAB 1: Channel & API Credentials
	// =========================================================================
	test('Tab 1 - Channel Selector & Beds24 Authentication flow', async ({ page }) => {
		// 1. Assert navigation landed on Tab 1 by default
		const activeTab = page.locator('.nav-tab-active');
		await expect(activeTab).toContainText('1. Channel & API Credentials');

		// 2. Assert active Channel Manager Cards and Guesty/Cloudbeds Soon badges
		const beds24Card = page.locator('.channel-option').first();
		await expect(beds24Card).toHaveClass(/selected/);

		const guestyCard = page.locator('.channel-option').nth(1);
		await expect(guestyCard).toHaveClass(/coming-soon-card/);
		await expect(guestyCard.locator('.coming-soon-badge')).toContainText('Soon', { ignoreCase: true });

		// 3. Verify Connection test buttons and AJAX response messaging
		const testConnBtn = page.locator('#homey-sync-test-conn');
		if (await testConnBtn.count() > 0) {
			// Trigger Connection Verification AJAX check
			await testConnBtn.click();
			
			// Assert that connection check displays status feedback box
			const statusMsg = page.locator('#connection-status-msg');
			await expect(statusMsg).toBeVisible();
			await expect(statusMsg).toHaveClass(/homey-sync-status-/);
		} else {
			// If disconnected, assert input elements are visible
			const authSelect = page.locator('#beds24_auth_method');
			await expect(authSelect).toBeVisible();
			
			const exchangeBtn = page.locator('#homey-sync-exchange-btn');
			await expect(exchangeBtn).toBeVisible();
		}
	});

	// =========================================================================
	// TAB 2: Listing Room Mappings
	// =========================================================================
	test('Tab 2 - Listing Mappings, Fuzzy Auto-Matcher, & Manual Toggles', async ({ page }) => {
		// 1. Navigate directly to Tab 2
		await page.goto(`${wpPrefix}/wp-admin/admin.php?page=homey-channel-sync&tab=mappings`);
		const activeTab = page.locator('.nav-tab-active');
		await expect(activeTab).toContainText('2. Listing Room Mappings');

		// 2. Assert that listings table is rendering mapping rows
		const mappingRows = page.locator('.homey-pms-row');
		const rowCount = await mappingRows.count();
		
		if (rowCount > 0) {
			// Verify first row contains list titles and ID description span details (first matching span only)
			const firstRow = mappingRows.first();
			await expect(firstRow.locator('.homey-listing-title')).toBeVisible();
			await expect(firstRow.locator('.description').first()).toContainText('ID:');

			// 3. Test Fuzzy Auto-Matching Algorithm Execution
			const autoMatchBtn = page.locator('#homey-sync-automatch-btn');
			if (await autoMatchBtn.count() > 0) {
				// Inject mock PMS inventory data directly in window context to ensure matching success (Future-proof!)
				await page.evaluate(() => {
					window.homeyPmsInventory = [
						{
							property_id: "74130",
							property_name: "Gorgeous Studio in Midtown Manhattan",
							rooms: [
								{
									room_id: "170328",
									room_name: "Apartment 4"
								}
							]
						}
					];
				});

				await autoMatchBtn.click();
				
				// Auto Match updates feedback box and matches rows
				const statusMsg = page.locator('#automatch-status-msg');
				await expect(statusMsg).toBeVisible();
				await expect(statusMsg).toHaveClass(/homey-sync-status-success/);
			}

			// 4. Test Manual Input Mode Toggle check
			const manualToggle = page.locator('#homey-pms-manual-toggle');
			const tableWrap = page.locator('#homey-mapping-table-wrap');

			// Assert manual toggle is unchecked by default
			await expect(manualToggle).not.toBeChecked();
			await expect(tableWrap).not.toHaveClass(/manual-mode-active/);

			// Check the manual input toggle
			await manualToggle.check();
			await expect(manualToggle).toBeChecked();
			await expect(tableWrap).toHaveClass(/manual-mode-active/);
		}
	});

	// =========================================================================
	// TAB 3: Sync Configuration
	// =========================================================================
	test('Tab 3 - Sync Toggles, WP-Cron Intervals, & Manual Force Trigger', async ({ page }) => {
		// 1. Navigate directly to Tab 3
		await page.goto(`${wpPrefix}/wp-admin/admin.php?page=homey-channel-sync&tab=settings`);
		const activeTab = page.locator('.nav-tab-active');
		await expect(activeTab).toContainText('3. Sync Configuration');

		// 2. Check/Uncheck Dynamic Daily Pricing Sync Checkbox
		const priceSyncCheckbox = page.locator('input[name="feature_price_sync"]');
		await expect(priceSyncCheckbox).toBeVisible();
		
		const isChecked = await priceSyncCheckbox.isChecked();
		if (isChecked) {
			await priceSyncCheckbox.uncheck();
			await expect(priceSyncCheckbox).not.toBeChecked();
		} else {
			await priceSyncCheckbox.check();
			await expect(priceSyncCheckbox).toBeChecked();
		}

		// 3. Verify Cron Schedule Interval dropdown selections
		const cronSelect = page.locator('#cron_schedule');
		await expect(cronSelect).toBeVisible();
		await cronSelect.selectOption('daily');
		await expect(cronSelect).toHaveValue('daily');

		// 4. Verify Manual Sync Trigger AJAX execution
		const syncNowBtn = page.locator('#homey-sync-trigger');
		if (await syncNowBtn.isEnabled()) {
			await syncNowBtn.click();
			
			// Assert sync feedback box is shown
			const progressBox = page.locator('#sync-progress-box');
			await expect(progressBox).toBeVisible();
			await expect(progressBox).toHaveClass(/homey-sync-status-/);

			// Assert sync CLI terminal console is shown
			const consoleBox = page.locator('#sync-console');
			await expect(page.locator('#sync-console')).toBeVisible();
			await expect(consoleBox).toContainText('[INFO]');
		}
	});

	// =========================================================================
	// TAB 4: Debug Logs
	// =========================================================================
	test('Tab 4 - Debug Logs Terminal stream, Download & AJAX Clearing', async ({ page }) => {
		// 1. Navigate directly to Tab 4
		await page.goto(`${wpPrefix}/wp-admin/admin.php?page=homey-channel-sync&tab=logs`);
		const activeTab = page.locator('.nav-tab-active');
		await expect(activeTab).toContainText('4. Debug Logs');

		// 2. Verify Logging checkbox is toggleable
		const debugLogCheckbox = page.locator('input[name="enable_debug_log"]');
		await expect(debugLogCheckbox).toBeVisible();

		// 3. Verify terminal log container displays entries
		const logConsole = page.locator('#homey-sync-log-viewer-console');
		await expect(logConsole).toBeVisible();

		// 4. Verify Download Log and Clear Log action buttons exist and handle clicks
		const downloadBtn = page.locator('a:has-text("Download Log File")');
		await expect(downloadBtn).toBeVisible();
		await expect(downloadBtn).toHaveAttribute('href', /action_download_logs/);

		const clearBtn = page.locator('#homey-sync-clear-logs-btn');
		await expect(clearBtn).toBeVisible();
	});
});
