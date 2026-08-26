<?php

namespace Deployer;

require 'recipe/laravel.php';

/*
 * Zero-downtime deployment with Deployer (deployer.org). The recipe is
 * itself reference material — a self-hosting brokerage follows it — so
 * everything server-shaped lives here in the repo, not in a private
 * runbook. Provisioning steps for a small VPS (a 2-core/4GB Hetzner
 * instance is plenty) are documented in the README's Deployment section.
 *
 * Usage:
 *   vendor/bin/dep deploy production
 *
 * First run: `vendor/bin/dep deploy production` then copy .env into the
 * shared directory (dep will pause on the missing .env), set the app key
 * and node identity, and run `php artisan openyacht:install` once inside
 * {{deploy_path}}/current.
 */

set('repository', 'git@github.com:OpenYacht/reference-app-php.git');
set('keep_releases', 5);

// storage/ and .env are shared by the Laravel recipe already. The
// SQLite default lives under database/; production on MySQL leaves this
// shared file unused but harmless.
add('shared_files', ['database/database.sqlite']);

host('production')
    // The node's identity domain — permanent in practice (it anchors
    // every canonical listing URI), so point DNS first and choose
    // deliberately (yacht-identity.md).
    ->setHostname(getenv('DEPLOY_HOST') ?: 'openyacht.example.com')
    ->setRemoteUser('deployer')
    ->setDeployPath('~/openyacht');

// Frontend build on the server: vendors must be installed first (the
// Vite wayfinder plugin shells out to artisan) and .env must be linked
// (it already is at this point in the flow).
task('pnpm:build', function (): void {
    cd('{{release_path}}');
    run('pnpm install --frozen-lockfile');
    run('pnpm build');
})->desc('Install JS dependencies and build assets');

after('deploy:vendors', 'pnpm:build');

// Queue workers hold the old release's code in memory until restarted;
// media imports and federation notifications ride the queue (README:
// the supervised worker is load-bearing).
after('artisan:migrate', 'artisan:queue:restart');

after('deploy:failed', 'deploy:unlock');
