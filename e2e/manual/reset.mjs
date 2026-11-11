// Empties the local database and leaves one verified user, for a clean manual-book run.
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';

const password =
    process.env.E2E_PASSWORD ?? randomBytes(18).toString('base64url');
const env = {
    ...process.env,
    E2E_NAME: 'Budi Santoso',
    E2E_EMAIL: 'budi@example.com',
    E2E_PASSWORD: password,
};
const run = (args) => execFileSync('php', args, { env, stdio: 'inherit' });

run(['artisan', 'migrate:fresh', '--force']);
run(['artisan', 'access:sync-permissions']);
run(['e2e/support/seed-user.php']);

console.log(`\nbudi@example.com / E2E_PASSWORD=${password}`);
