<?php

namespace App\Http\Controllers;

use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandingController extends Controller
{
    /**
     * Принимаем заявку с лендинга и отправляем в Telegram
     * POST /api/landing/booking
     */
    public function booking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:191',
            'phone'    => 'required|string|max:30',
            'type'     => 'required|string|max:100',
            'date'     => 'required|string',
            'time'     => 'required|string',
            'duration' => 'nullable|string|max:50',
            'comment'  => 'nullable|string|max:1000',
        ]);

        $date = $data['date']
            ? date('d.m.Y', strtotime($data['date']))
            : '—';

        $lines = [
            '🎯 <b>Новая заявка — ROLTHALL</b>',
            '',
            "👤 <b>Имя:</b> {$data['name']}",
            "📞 <b>Телефон:</b> {$data['phone']}",
            "🏷 <b>Тип аренды:</b> {$data['type']}",
            "📅 <b>Дата:</b> {$date}",
            "🕐 <b>Время:</b> {$data['time']}",
        ];

        if (!empty($data['duration'])) {
            $lines[] = "⏱ <b>Длительность:</b> {$data['duration']}";
        }
        if (!empty($data['comment'])) {
            $lines[] = "💬 <b>Комментарий:</b> {$data['comment']}";
        }

        $text = implode("\n", $lines);

        try {
            (new NotificationService())->sendRaw($text);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
