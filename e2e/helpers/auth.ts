import * as path from 'path';

export const AUTH_FILE = path.resolve( __dirname, '../.auth/admin.json' );

export const ADMIN_USER = process.env.WP_ADMIN_USER ?? 'admin';
export const ADMIN_PASS = process.env.WP_ADMIN_PASS ?? 'password';
