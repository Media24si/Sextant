<?php

use App\Models\MonitorRun;
use Illuminate\Support\Facades\Schedule;

/*
 * The scheduler runs under `php artisan schedule:work` (systemd/supervisor),
 * not a cron-invoked schedule:run, because notifications:flush runs every
 * 30 seconds (SPEC §14). Tasks with ->sentryMonitor() check in to Sentry Crons.
 */

Schedule::command('monitors:dispatch')->everyMinute()->withoutOverlapping()->sentryMonitor();
Schedule::command('sextant:sweep')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:flush')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('sextant:heartbeat')->everyFiveMinutes();
Schedule::command('model:prune', ['--model' => [MonitorRun::class]])->dailyAt('03:30');
Schedule::command('horizon:snapshot')->everyFiveMinutes()->sentryMonitor();

Schedule::command('proxies:refresh')->daily()->withoutOverlapping();
Schedule::command('rdap:refresh-bootstrap')->weekly()->withoutOverlapping();
