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

    public function hasToken(): bool { return !empty($this->token); }

    public function sendToChat(int|string $chatId, string $text): bool
    {
        if (!$this->token) return false;

        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->post("{$this->baseUrl}/messages?chat_id={$chatId}", [
                    'text'   => $text,
                    'format' => 'html',
                ]);

            if (!$response->successful()) {
                Log::error('Max sendToChat error', [
                    'chat_id' => $chatId,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Max sendToChat exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendMessage(int|string $userId, string $text, array $buttons = []): bool
    {
        if (!$this->token) return false;

        try {
            $body = ['text' => $text, 'format' => 'html'];
            if (!empty($buttons)) {
                $body['attachments'] = $this->buildKeyboard($buttons);
            }

            $response = Http::withHeaders(['Authorization' => $this->token])
                ->post("{$this->baseUrl}/messages?user_id={$userId}", $body);

            if (!$response->successful()) {
                Log::error('Max sendMessage error', [
                    'user_id' => $userId,
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

    public function sendPhoto(int|string $userId, string $photoPath, string $caption = '', array $buttons = []): bool
    {
        if (!$this->token) return false;

        try {
            // Шаг 1: получаем pre-signed upload URL
            $step1 = Http::withHeaders(['Authorization' => $this->token])
                ->attach('data', fopen($photoPath, 'r'), basename($photoPath))
                ->post("{$this->baseUrl}/uploads?type=image");

            if (!$step1->successful()) {
                Log::error('Max sendPhoto step1 error', ['status' => $step1->status(), 'body' => $step1->body()]);
                return false;
            }

            $uploadUrl = $step1->json('url');
            if (!$uploadUrl) {
                Log::error('Max sendPhoto: no url in step1 response', ['body' => $step1->body()]);
                return false;
            }

            // Шаг 2: загружаем файл на pre-signed URL → получаем token
            $step2 = Http::attach('data', fopen($photoPath, 'r'), basename($photoPath))
                ->post($uploadUrl);

            if (!$step2->successful()) {
                Log::error('Max sendPhoto step2 error', ['status' => $step2->status(), 'body' => $step2->body()]);
                return false;
            }

            $photos = $step2->json('photos') ?? [];
            $photoToken = null;
            foreach ($photos as $photoData) {
                $photoToken = $photoData['token'] ?? null;
                if ($photoToken) break;
            }

            if (!$photoToken) {
                Log::error('Max sendPhoto: no token in step2 response', ['body' => $step2->body()]);
                return false;
            }

            // Шаг 3: отправляем сообщение с токеном фото
            $body = [
                'text'        => $caption,
                'format'      => 'html',
                'attachments' => [['type' => 'image', 'payload' => ['token' => $photoToken]]],
            ];
            if (!empty($buttons)) {
                $body['attachments'][] = $this->buildKeyboard($buttons)[0];
            }

            $response = Http::withHeaders(['Authorization' => $this->token])
                ->post("{$this->baseUrl}/messages?user_id={$userId}", $body);

            if (!$response->successful()) {
                Log::error('Max sendPhoto step3 error', [
                    'user_id' => $userId,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Max sendPhoto exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function setCommands(array $commands): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => $this->token])
                ->patch("{$this->baseUrl}/me", ['commands' => $commands]);

            return $response->json() ?? ['error' => 'empty response'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function buildKeyboard(array $rows): array
    {
        return [[
            'type'    => 'inline_keyboard',
            'payload' => ['buttons' => $rows],
        ]];
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
