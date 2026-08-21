<?php

namespace App\Jobs;

use App\Services\WhatsAppWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public array $payload)
    {
    }

    public function handle(WhatsAppWebhookService $service): void
    {
        $service->handle($this->payload);
    }
}
