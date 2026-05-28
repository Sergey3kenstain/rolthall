<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MaxBotService
{
    private string $token;
    private string $baseUrl = 'https://platform-api.max.ru';

    public function __construct()
    {
        $file        = storage_path('app/max_settings.json');
        $settings    = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
        $this->token = $settings['token'] ?? config('services.max.bot_token', '');
    }

    public function sendMessage(int|string $userId, string $text): bool
    {
        if (!$this->token) return false;

        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->post("{$this->baseUrl}/messages?user_id={$userId}", [
                    'text'   => $text,
                    'format' => 'html',
                ]);

            if (!$response->successful()) {
                Log::error('Max sendMessage error', [
                    'chat_id' => $chatId,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Max sendMessage exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function setWebhook(string $url): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->post("{$this->baseUrl}/subscriptions", ['url' => $url]);

            return $response->json() ?? ['error' => 'empty response'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getWebhookInfo(): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->get("{$this->baseUrl}/subscriptions");

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getMe(): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->get("{$this->baseUrl}/me");

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
