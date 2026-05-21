<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:release-holds')]
#[Description('Снимает hold с истёкших бронирований')]
class ReleaseExpiredHolds extends Command
{
    public function handle(): void
    {
        $expired = \App\Models\Booking::where('status', \App\Models\Booking::STATUS_HOLD)
            ->where('hold_expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            return;
        }

        $expired->each(fn($b) => $b->update(['status' => \App\Models\Booking::STATUS_CANCELLED]));

        $this->info("Released {$expired->count()} expired holds.");
    }
}
