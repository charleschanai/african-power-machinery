<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchRequest;
use App\Http\Requests\StoreGuestRequest;
use App\Http\Requests\UpdateGuestRequest;
use App\Models\Guest;
use App\Services\CardVerificationService;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GuestController extends Controller
{
    public function __construct(private readonly CardVerificationService $cardService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guest::class);

        $guests = Guest::query()
            ->withCount('cards')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($sub) use ($term) {
                    $sub->where('full_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $guests,
        ]);
    }

    public function search(SearchRequest $request): JsonResponse
    {
        $results = $this->cardService->search($request->input('q'));

        return response()->json([
            'success' => true,
            'message' => $results->isEmpty() ? 'No results found.' : 'Results found.',
            'data' => $results,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Guest::class);

        $guests = Guest::query()
            ->withCount('cards')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($sub) use ($term) {
                    $sub->where('full_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->orderByDesc('id')
            ->cursor();

        $filename = 'guests-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($guests) {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['Full Name', 'Phone Number', 'Email', 'Notes', 'Number of Cards', 'Added']);

            foreach ($guests as $guest) {
                fputcsv($handle, [
                    $guest->full_name,
                    $guest->phone,
                    $guest->email ?? '-',
                    $guest->notes ?? '-',
                    $guest->cards_count,
                    $guest->created_at?->toDateTimeString() ?? '-',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function store(StoreGuestRequest $request): JsonResponse
    {
        $this->authorize('create', Guest::class);

        $guest = Guest::query()->create($request->validated());

        SecurityLogger::logRequest('guest.store', $request, $request->user(), ['guest_id' => $guest->id]);

        return response()->json([
            'success' => true,
            'message' => 'Guest added.',
            'data' => $guest,
        ], 201);
    }

    public function show(Guest $guest): JsonResponse
    {
        $guest->load(['cards' => function ($query) {
            $query->with([
                'guest',
                'invitationSends' => function ($q) {
                    $q->orderByDesc('id');
                },
            ]);
        }]);

        return response()->json([
            'success' => true,
            'data' => $guest,
        ]);
    }

    public function update(UpdateGuestRequest $request, Guest $guest): JsonResponse
    {
        $this->authorize('update', $guest);

        $guest->update($request->validated());

        SecurityLogger::logRequest('guest.update', $request, $request->user(), ['guest_id' => $guest->id]);

        return response()->json([
            'success' => true,
            'message' => 'Guest updated.',
            'data' => $guest,
        ]);
    }

    public function destroy(Request $request, Guest $guest): JsonResponse
    {
        $this->authorize('delete', $guest);

        SecurityLogger::logRequest('guest.destroy', $request, $request->user(), ['guest_id' => $guest->id]);

        $guest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Guest deleted.',
        ]);
    }
}
