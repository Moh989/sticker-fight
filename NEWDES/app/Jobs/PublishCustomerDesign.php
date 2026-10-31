<?php

namespace App\Jobs;

use App\Models\OrderItem;
use App\Services\CustomerDesignPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PublishCustomerDesign implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 3;

    public int $timeout = 200;

    public int $uniqueFor = 900;

    public function __construct(public int $itemId) {}

    public function uniqueId(): string
    {
        return 'customer-publication-'.$this->itemId;
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    /**
     * Execute the job.
     */
    public function handle(CustomerDesignPublisher $publisher): void
    {
        $publisher->publish(OrderItem::findOrFail($this->itemId));
    }

    public function failed(?\Throwable $exception): void
    {
        OrderItem::whereKey($this->itemId)->where('publication_consent', true)->where('publication_status', '!=', 'published')->update(['publication_status' => 'failed']);
    }
}
