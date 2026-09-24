import { defineConfig, devices } from '@playwright/test';
import { AUTH_FILE } from './helpers/auth';

const BASE_URL = process.env.BASE_URL ?? 'http://localhost:8888';

export default defineConfig( {
	testDir: './tests',
	timeout: 30_000,
	expect: { timeout: 5_000 },
	// The block editor's "Meta Boxes" panel open/closed state is a single
	// per-user preference persisted server-side (not scoped per post type or
	// browser context) — concurrent workers reusing the same wp-admin user
	// can race each other's toggle of it, regardless of which spec file
	// they're in. `fullyParallel: false` alone only serializes within a
	// file, so `workers: 1` is needed too. Three cheap smoke tests don't
	// need the parallel speedup anyway.
	fullyParallel: false,
	workers: 1,
	retries: 1,
	globalSetup: require.resolve( './global-setup' ),
	reporter: [ [ 'list' ], [ 'html', { open: 'never' } ] ],
	use: {
		baseURL: BASE_URL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		storageState: AUTH_FILE,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
