<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Camp;
use App\Services\TenantManager;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendWebPushToRole implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 90;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $role,
        public string $title,
        public string $body,
        public ?string $url,
        public array $data,
        public ?int $campId,
    ) {}

    public function handle(WebPushService $webPush, TenantManager $tenants): void
    {
        if ($this->campId) {
            $camp = Camp::query()->find($this->campId);
            if ($camp) {
                $tenants->setCurrentCamp($camp);
            }
        }

        $webPush->notifyRole($this->role, $this->title, $this->body, $this->url, $this->data, false);
    }
}
