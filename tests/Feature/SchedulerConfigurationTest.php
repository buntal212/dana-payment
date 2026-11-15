<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerConfigurationTest extends TestCase
{
    public function test_billing_webhook_dispatch_is_scheduled_every_minute_without_overlapping(): void
    {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())->filter(function (Event $event): bool {
            return str_contains($event->command, 'billing:dispatch-webhooks --limit=25');
        });

        $this->assertCount(1, $events, 'Command billing:dispatch-webhooks --limit=25 harus terdaftar di scheduler.');

        /** @var Event $event */
        $event = $events->first();
        $this->assertSame('* * * * *', $event->expression, 'Command harus dijalankan setiap menit (* * * * *).');
        $this->assertTrue($event->withoutOverlapping, 'Command harus menggunakan withoutOverlapping().');
    }
}
