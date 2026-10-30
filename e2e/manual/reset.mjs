// Empties the local database and leaves one verified user, for a clean manual-book run.
import { execFileSync } from 'node:child_process';

const env = {
    ...process.env,
    E2E_NAME: 'Budi Santoso',
    E2E_EMAIL: 'budi@example.com',
    E2E_PASSWORD: 'rahasia-e2e-123',
};
const run = (args) => execFileSync('php', args, { env, stdio: 'inherit' });

run(['artisan', 'migrate:fresh', '--force']);
run(['artisan', 'access:sync-permissions']);
run(['e2e/support/seed-user.php']);
