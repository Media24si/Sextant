<?php

use Illuminate\Support\Facades\Schedule;

/*
 * The scheduler runs under `php artisan schedule:work` (systemd/supervisor),
 * not a cron-invoked schedule:run, because notifications:flush runs every
 * 30 seconds (SPEC §14). Tasks with ->sentryMonitor() check in to Sentry Crons.
 */

Schedule::command('horizon:snapshot')->everyFiveMinutes()->sentryMonitor();
