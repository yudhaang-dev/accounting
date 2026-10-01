import { execFileSync, execSync } from 'node:child_process';
import { appEnv, db, E2E_DATABASE, USER } from './env';

const run = (command: string, args: string[]) =>
    execFileSync(command, args, {
        env: {
            ...appEnv,
            E2E_NAME: USER.name,
            E2E_EMAIL: USER.email,
            E2E_PASSWORD: USER.password,
        },
        stdio: ['ignore', 'pipe', 'inherit'],
        encoding: 'utf8',
    });

/**
 * Prepares a clean world before the app starts: the e2e database exists and is migrated from
 * scratch, the permission catalog is synced, and one verified user is waiting to sign in. The built
 * SPA is rebuilt so the browser always runs the code in the working tree.
 *
 * It runs while the config loads, because Playwright starts the web server before any global setup
 * and the server's readiness check already needs the database.
 */
export function prepare() {
    // `psql` is not assumed to exist; PDO talks to the same server the app uses.
    run('php', [
        '-r',
        `
        $pdo = new PDO('pgsql:host=${db.host};port=${db.port};dbname=postgres', '${db.user}', '${db.password}');
        $exists = $pdo->query("select 1 from pg_database where datname = '${E2E_DATABASE}'")->fetchColumn();
        if (!$exists) { $pdo->exec('create database ${E2E_DATABASE}'); }
        `,
    ]);

    run('php', ['artisan', 'migrate:fresh', '--force']);
    run('php', ['artisan', 'access:sync-permissions']);
    run('php', ['e2e/support/seed-user.php']);

    // pnpm is a .cmd shim on Windows, which Node only starts through a shell.
    execSync('pnpm run build', { env: appEnv, stdio: 'ignore' });
}
