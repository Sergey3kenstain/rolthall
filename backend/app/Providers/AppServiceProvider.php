<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // MySQL 5.7 — ограничение длины индексов для utf8mb4
        Schema::defaultStringLength(191);
    }
}
