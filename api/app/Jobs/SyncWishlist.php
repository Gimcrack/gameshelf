<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Wishlist\WishlistSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncWishlist implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    /**
     * V8/V22: wishlist sync always runs off the request cycle.
     */
    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        app(WishlistSyncService::class)->sync($user);
    }
}
