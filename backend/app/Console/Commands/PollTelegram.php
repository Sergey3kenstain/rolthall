<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class PollTelegram extends Command
{
    protected $signature   = 'telegram:poll';
    protected $description = 'Poll Telegram getUpdates (fallback when webhook is blocked on Beget)';

    public function handle(): int
    {
        $token = config('services.telegram.bot_token');
        if (!$token) {
            $this->warn('Telegram bot token not configured.');
            return 0;
        }

        $offsetFile = storage_path('app/tg_poll_offset.txt');
        $offset     = file_exists($offsetFile) ? (int) file_get_contents($offsetFile) : 0;

        $response = Http::timeout(5)->get("https://api.telegram.org/bot{$token}/getUpdates", [
            'offset'  => $offset > 0 ? $offset + 1 : 0,
            'limit'   => 100,
            'timeout' => 0,
        ]);

        if (!$response->ok()) return 0;

        $updates = $response->json('result') ?? [];
        if (empty($updates)) return 0;

        $controller = new TelegramController();
        $lastId     = $offset;

        foreach ($updates as $update) {
            $lastId = max($lastId, $update['update_id']);
            $controller->processUpdate($update);
        }

        file_put_contents($offsetFile, $lastId);
        return 0;
    }
}
