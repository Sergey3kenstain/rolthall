<?php

namespace App\Http\Controllers;

use App\Models\EventTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventTemplateController extends Controller
{
    /** GET /api/admin/event-templates — список (все авторизованные роли) */
    public function index(): JsonResponse
    {
        $templates = EventTemplate::orderBy('name')
            ->get(['id', 'name', 'description', 'created_at']);

        return response()->json(['ok' => true, 'templates' => $templates], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /api/admin/event-templates/{id} — полные данные шаблона */
    public function show(int $id): JsonResponse
    {
        $tpl = EventTemplate::findOrFail($id);
        return response()->json(['ok' => true, 'template' => $tpl], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** POST /api/admin/event-templates — создать (developer only) */
    public function store(Request $request): JsonResponse
    {
        $this->requireDeveloper($request);

        $data = $request->validate([
            'name'               => 'required|string|max:191',
            'description'        => 'nullable|string|max:1000',
            'form_css'           => 'nullable|string',
            'fields'             => 'nullable|array',
            'tariffs'            => 'nullable|array',
            'messenger_settings' => 'nullable|array',
        ]);

        $tpl = EventTemplate::create($data);
        return response()->json(['ok' => true, 'template' => $tpl], 201, [], JSON_UNESCAPED_UNICODE);
    }

    /** PUT /api/admin/event-templates/{id} — обновить (developer only) */
    public function update(Request $request, int $id): JsonResponse
    {
        $this->requireDeveloper($request);

        $tpl  = EventTemplate::findOrFail($id);
        $data = $request->validate([
            'name'               => 'sometimes|string|max:191',
            'description'        => 'nullable|string|max:1000',
            'form_css'           => 'nullable|string',
            'fields'             => 'nullable|array',
            'tariffs'            => 'nullable|array',
            'messenger_settings' => 'nullable|array',
        ]);

        $tpl->update($data);
        return response()->json(['ok' => true, 'template' => $tpl], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** DELETE /api/admin/event-templates/{id} — удалить (developer only) */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requireDeveloper($request);
        EventTemplate::findOrFail($id)->delete();
        return response()->json(['ok' => true]);
    }

    private function requireDeveloper(Request $request): void
    {
        if (!$request->user()?->hasRole('developer')) {
            abort(403, 'Developer only');
        }
    }
}
