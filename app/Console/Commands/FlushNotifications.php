<?php

namespace App\Console\Commands;

use App\Notifications\Discord\NotificationFlusher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Every 30 seconds: send pending incident events to Discord (SPEC §12.1). */
#[Signature('notifications:flush')]
#[Description('Send pending incident events to Discord')]
class FlushNotifications extends Command
{
    public function handle(NotificationFlusher $flusher): int
    {
        $report = $flusher->flush(now());

        $this->components->info("{$report['sent']} sent in {$report['messages']} messages, {$report['suppressed']} suppressed, {$report['waiting']} waiting.");

        return self::SUCCESS;
    }
}
