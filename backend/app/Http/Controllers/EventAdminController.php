<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Event;
use App\Models\EventField;
use App\Models\EventRegistration;
use App\Models\EventTariff;
use App\Models\EventTariffTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EventAdminController extends Controller
{
    private function isDeveloper(Request $r): bool
    {
        return $r->user()->hasRole('developer');
    }

    private function isOwner(Request $r): bool
    {
        return $r->user()->hasAnyRole(['owner', 'developer']);
    }

    // ── Events CRUD ───────────────────────────────────────────────────────

    public function index(): JsonResponse
    {
        $events = Event::withCount('registrations')
            ->orderByDesc('event_date')
            ->get()
            ->map(fn($e) => [
                'id'                => $e->id,
                'title'             => $e->title,
                'slug'              => $e->slug,
                'event_date'        => $e->event_date?->format('Y-m-d'),
                'status'            => $e->status,
                'tag'               => $e->tag,
                'payments_enabled'  => $e->payments_enabled,
                'poster_url'        => $e->poster_url,
                'registrations_count' => $e->registrations_count,
                'paid_count'        => $e->registrations()->where('payment_status', 'paid')->count(),
            ]);

        return response()->json(['ok' => true, 'events' => $events], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(int $id): JsonResponse
    {
        $event = Event::with(['fields', 'tariffs.tiers'])->findOrFail($id);

        return response()->json([
            'ok'    => true,
            'event' => $this->formatEvent($event),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title'                        => 'required|string|max:191',
            'slug'                         => 'nullable|string|max:100|unique:events,slug|regex:/^[a-z0-9\-]+$/',
            'description'                  => 'nullable|string',
            'event_date'                   => 'nullable|date',
            'tag'                          => 'nullable|string|max:100',
            'status'                       => 'nullable|in:draft,active,closed',
            'payments_enabled'             => 'boolean',
            'allow_multiple_registrations' => 'boolean',
            'messenger_settings'           => 'nullable|array',
        ]);

        $data['slug']       = $data['slug'] ?? Str::slug($data['title']);
        $data['created_by'] = $request->user()->id;

        $event = Event::create($data);

        return response()->json(['ok' => true, 'event' => $this->formatEvent($event->load(['fields', 'tariffs.tiers']))], 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $data = $request->validate([
            'title'                        => 'sometimes|string|max:191',
            'slug'                         => 'sometimes|string|max:100|unique:events,slug,' . $id . '|regex:/^[a-z0-9\-]+$/',
            'description'                  => 'sometimes|nullable|string',
            'event_date'                   => 'sometimes|nullable|date',
            'tag'                          => 'sometimes|nullable|string|max:100',
            'status'                       => 'sometimes|in:draft,active,closed',
            'payments_enabled'             => 'sometimes|boolean',
            'allow_multiple_registrations' => 'sometimes|boolean',
            'messenger_settings'           => 'sometimes|nullable|array',
        ]);

        $event->update($data);

        return response()->json(['ok' => true, 'event' => $this->formatEvent($event->fresh(['fields', 'tariffs.tiers']))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (!$this->isOwner($request)) abort(403);
        Event::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    // ── Fields ────────────────────────────────────────────────────────────

    public function syncFields(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $fields = $request->validate([
            'fields'               => 'required|array',
            'fields.*.label'       => 'required|string|max:191',
            'fields.*.field_type'        => 'required|in:text,number,phone,email,telegram,instagram,select,multiselect,textarea,checkbox,date,file',
            'fields.*.slug'              => 'required|string|max:100',
            'fields.*.placeholder'       => 'nullable|string|max:255',
            'fields.*.has_mask'          => 'boolean',
            'fields.*.mask_pattern'      => 'nullable|string|max:500',
            'fields.*.is_required'       => 'boolean',
            'fields.*.options'           => 'nullable|array',
            'fields.*.sort_order'        => 'integer',
            'fields.*.depends_on_tariff' => 'nullable|string|max:191',
            'fields.*.dep_field'         => 'nullable|string|max:100',
            'fields.*.dep_value'         => 'nullable|string|max:500',
        ])['fields'];

        $event->fields()->delete();

        foreach ($fields as $i => $f) {
            EventField::create(array_merge($f, ['event_id' => $event->id, 'sort_order' => $i]));
        }

        return response()->json(['ok' => true, 'fields' => $event->fresh('fields')->fields], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Tariffs ───────────────────────────────────────────────────────────

    public function syncTariffs(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $tariffs = $request->validate([
            'tariffs'                      => 'required|array',
            'tariffs.*.name'               => 'required|string|max:191',
            'tariffs.*.has_options'         => 'boolean',
            'tariffs.*.options'             => 'nullable|array',
            'tariffs.*.dependent_fields'    => 'nullable|array',
            'tariffs.*.sort_order'          => 'integer',
            'tariffs.*.tiers'              => 'required|array',
            'tariffs.*.tiers.*.from_date'  => 'required|date',
            'tariffs.*.tiers.*.to_date'    => 'required|date|after_or_equal:tariffs.*.tiers.*.from_date',
            'tariffs.*.tiers.*.price'      => 'required|integer|min:0',
        ])['tariffs'];

        // Удаляем старые тарифы (cascade удалит тиры)
        $event->tariffs()->each(fn($t) => $t->tiers()->delete());
        $event->tariffs()->delete();

        foreach ($tariffs as $i => $t) {
            $tariff = EventTariff::create([
                'event_id'         => $event->id,
                'name'             => $t['name'],
                'has_options'      => $t['has_options'] ?? false,
                'options'          => $t['options'] ?? null,
                'dependent_fields' => $t['dependent_fields'] ?? null,
                'sort_order'       => $i,
            ]);

            foreach ($t['tiers'] as $tier) {
                EventTariffTier::create([
                    'tariff_id' => $tariff->id,
                    'from_date' => $tier['from_date'],
                    'to_date'   => $tier['to_date'],
                    'price'     => $tier['price'],
                ]);
            }
        }

        return response()->json(['ok' => true, 'tariffs' => $event->fresh(['tariffs.tiers'])->tariffs], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Poster upload ─────────────────────────────────────────────────────

    public function uploadPoster(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);

        $request->validate([
            'poster' => 'required|image|mimes:jpg,jpeg,png|max:1536', // 1.5 MB
        ]);

        // Удаляем старую афишу
        if ($event->poster_path && file_exists(storage_path('app/public/' . $event->poster_path))) {
            unlink(storage_path('app/public/' . $event->poster_path));
        }

        $path = $request->file('poster')->store('events/posters', 'public');
        $event->update(['poster_path' => $path]);

        return response()->json(['ok' => true, 'poster_url' => $event->poster_url], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Registrations ─────────────────────────────────────────────────────

    public function registrations(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $q     = $request->query('q', '');

        $regs = EventRegistration::with('tariff')
            ->where('event_id', $id)
            ->when($q, function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('phone', 'like', "%{$q}%")
                          ->orWhere('tg_user_id', 'like', "%{$q}%")
                          ->orWhereJsonContains('fields_data', $q);
                });
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($r) => $this->formatRegistration($r));

        return response()->json(['ok' => true, 'registrations' => $regs], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function registrationDetail(int $eventId, int $regId): JsonResponse
    {
        $reg = EventRegistration::with('tariff')
            ->where('event_id', $eventId)
            ->findOrFail($regId);

        return response()->json(['ok' => true, 'registration' => $this->formatRegistration($reg, true)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function formatEvent(Event $event): array
    {
        return [
            'id'                           => $event->id,
            'title'                        => $event->title,
            'slug'                         => $event->slug,
            'description'                  => $event->description,
            'poster_url'                   => $event->poster_url,
            'event_date'                   => $event->event_date?->format('Y-m-d'),
            'tag'                          => $event->tag,
            'status'                       => $event->status,
            'payments_enabled'             => $event->payments_enabled,
            'allow_multiple_registrations' => $event->allow_multiple_registrations,
            'messenger_settings'           => $event->messenger_settings,
            'fields'                       => $event->fields,
            'tariffs'                      => $event->tariffs->map(fn($t) => [
                'id'               => $t->id,
                'name'             => $t->name,
                'has_options'      => $t->has_options,
                'options'          => $t->options,
                'dependent_fields' => $t->dependent_fields ?? [],
                'sort_order'       => $t->sort_order,
                'tiers'       => $t->tiers->map(fn($tier) => [
                    'id'        => $tier->id,
                    'from_date' => $tier->from_date->format('Y-m-d'),
                    'to_date'   => $tier->to_date->format('Y-m-d'),
                    'price'     => $tier->price,
                ]),
            ]),
            'registrations_count' => $event->registrations()->count(),
            'paid_count'          => $event->registrations()->where('payment_status', 'paid')->count(),
        ];
    }

    private function formatRegistration(EventRegistration $r, bool $full = false): array
    {
        $base = [
            'id'              => $r->id,
            'phone'           => $r->phone,
            'tg_user_id'      => $r->tg_user_id,
            'max_user_id'     => $r->max_user_id,
            'display_name'    => $r->display_name,
            'tariff'          => $r->tariff?->name,
            'selected_option' => $r->selected_option,
            'payment_status'  => $r->payment_status,
            'payment_amount'  => $r->payment_amount,
            'paid_at'         => $r->paid_at?->format('d.m.Y H:i'),
            'ticket_sent'     => $r->ticket_sent,
            'created_at'      => $r->created_at->format('d.m.Y H:i'),
        ];

        if ($full) {
            $base['fields_data']      = $r->fields_data;
            $base['payment_order_id'] = $r->payment_order_id;
        }

        return $base;
    }
}
