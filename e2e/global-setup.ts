import { chromium, type FullConfig } from '@playwright/test';
import { AUTH_FILE, ADMIN_USER, ADMIN_PASS } from './helpers/auth';

// Runs once before any test file/worker starts: one real wp-admin login,
// saved to AUTH_FILE, so every test's `page` fixture starts pre-authenticated
// instead of each spec logging in for itself (same reasoning as dansal's
// e2e/global-setup.ts).
export default async function globalSetup( config: FullConfig ) {
	const baseURL = config.projects[ 0 ].use.baseURL as string;
	const browser = await chromium.launch();
	const page = await browser.newPage();

	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASS );
	await page.click( '#wp-submit' );
	await page.waitForSelector( '#wpadminbar' );

	await page.context().storageState( { path: AUTH_FILE } );
	await browser.close();
}
