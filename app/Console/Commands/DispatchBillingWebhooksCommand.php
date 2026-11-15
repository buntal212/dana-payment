<?php

namespace App\Console\Commands;

use App\Services\BillingWebhookForwarder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:dispatch-webhooks {--limit=25 : Jumlah maksimum pesan outbox yang diproses}')]
#[Description('Meneruskan pesan outbox webhook DANA yang tertunda ke UDEV Billing')]
class DispatchBillingWebhooksCommand extends Command
{
    public function handle(BillingWebhookForwarder $forwarder): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $processed = $forwarder->dispatchPending($limit);

        $this->info("{$processed} pesan outbox webhook berhasil diproses.");

        return self::SUCCESS;
    }
}
