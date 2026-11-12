<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCardTemplateRequest;
use App\Http\Requests\UpdateCardTemplateRequest;
use App\Models\CardTemplate;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CardTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $templates = CardTemplate::query()
            ->withCount('cards')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where('name', 'like', "%{$term}%");
            })
            ->when($request->filled('card_type'), function ($query) use ($request) {
                $query->where('card_type', $request->input('card_type'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('status', $request->input('status'));
            })
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        $data = $templates->through(fn (CardTemplate $template) => $template->toFrontendArray());

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(StoreCardTemplateRequest $request): JsonResponse
    {
        $data = $request->safe()->except(['front_design', 'back_design']);

        if ($request->hasFile('front_design')) {
            $data['front_design'] = $request->file('front_design')->store('card-templates', 'public');
        }

        if ($request->hasFile('back_design')) {
            $data['back_design'] = $request->file('back_design')->store('card-templates', 'public');
        }

        $data['status'] ??= CardTemplate::STATUS_ACTIVE;

        $template = CardTemplate::query()->create($data);

        SecurityLogger::logRequest('card_template.store', $request, $request->user(), [
            'template_id' => $template->id,
            'name' => $template->name,
            'card_type' => $template->card_type,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Card template added.',
            'data' => $template->toFrontendArray(),
        ], 201);
    }

    public function show(CardTemplate $cardTemplate): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $cardTemplate->toFrontendArray(),
        ]);
    }

    public function update(UpdateCardTemplateRequest $request, CardTemplate $cardTemplate): JsonResponse
    {
        $data = $request->safe()->except(['front_design', 'back_design']);

        if ($request->hasFile('front_design')) {
            if ($cardTemplate->front_design) {
                Storage::disk('public')->delete($cardTemplate->front_design);
            }
            $data['front_design'] = $request->file('front_design')->store('card-templates', 'public');
        }

        if ($request->hasFile('back_design')) {
            if ($cardTemplate->back_design) {
                Storage::disk('public')->delete($cardTemplate->back_design);
            }
            $data['back_design'] = $request->file('back_design')->store('card-templates', 'public');
        }

        $cardTemplate->update($data);

        SecurityLogger::logRequest('card_template.update', $request, $request->user(), [
            'template_id' => $cardTemplate->id,
            'name' => $cardTemplate->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Card template updated.',
            'data' => $cardTemplate->fresh()->toFrontendArray(),
        ]);
    }

    public function toggleStatus(CardTemplate $cardTemplate): JsonResponse
    {
        $cardTemplate->update([
            'status' => $cardTemplate->isActive() ? CardTemplate::STATUS_INACTIVE : CardTemplate::STATUS_ACTIVE,
        ]);

        return response()->json([
            'success' => true,
            'message' => $cardTemplate->isActive()
                ? 'Template enabled.'
                : 'Template disabled.',
            'data' => $cardTemplate->fresh()->toFrontendArray(),
        ]);
    }

    public function destroy(Request $request, CardTemplate $cardTemplate): JsonResponse
    {
        $frontDesign = $cardTemplate->front_design;
        $backDesign = $cardTemplate->back_design;

        SecurityLogger::logRequest('card_template.destroy', $request, $request->user(), [
            'template_id' => $cardTemplate->id,
            'name' => $cardTemplate->name,
        ]);

        // The card_template_id FK uses ON DELETE SET NULL, so existing card
        // records remain intact when their design is removed.
        DB::transaction(fn () => $cardTemplate->delete());

        if ($frontDesign) {
            Storage::disk('public')->delete($frontDesign);
        }
        if ($backDesign) {
            Storage::disk('public')->delete($backDesign);
        }

        return response()->json([
            'success' => true,
            'message' => 'Card template deleted.',
        ]);
    }
}
