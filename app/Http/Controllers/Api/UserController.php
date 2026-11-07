<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\SecurityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only user administration.
 *
 * Two rules shape everything here:
 *
 * 1. Nobody is ever hard-deleted. Entry logs reference users.processed_by
 *    with nullOnDelete(), so a deleted user silently erases the "who handled
 *    this entry" half of the gate audit trail. Deactivation keeps the row and
 *    revokes their tokens instead.
 * 2. The last usable admin cannot be removed, demoted or deactivated, and no
 *    admin can lock themselves out. Locking the only admin out of the system
 *    is unrecoverable without database access.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->withCount('processedEntries')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->input('q');
                $query->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('role'), fn ($query) => $query->where('role', $request->input('role')))
            ->when($request->has('status') && $request->input('status') !== '', function ($query) use ($request) {
                $query->where('is_active', $request->input('status') === 'active');
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        $data = $users->through(fn (User $user) => $user->toFrontendArray());

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = User::query()->create($request->safe()->only(['name', 'email', 'role', 'password']));

        SecurityLogger::logRequest('user.store', $request, $request->user(), [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User added.',
            'data' => $user->toFrontendArray(),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->safe()->except(['password']);

        $blocks = $this->lockoutGuards($request, $user, $data);

        if ($blocks !== null) {
            return $blocks;
        }

        $user->fill($data);

        if ($request->filled('password')) {
            $user->password = $request->input('password');
        }

        $user->save();

        // A deactivated user must stop working immediately, not whenever
        // their existing Sanctum token happens to expire.
        if (! $user->isActive()) {
            $user->tokens()->delete();
        }

        SecurityLogger::logRequest('user.update', $request, $request->user(), [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->isActive(),
            'password_changed' => $request->filled('password'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User updated.',
            'data' => $user->fresh()->toFrontendArray(),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $blocks = $this->lockoutGuards($request, $user, ['is_active' => false]);

        if ($blocks !== null) {
            return $blocks;
        }

        $user->update(['is_active' => false]);
        $user->tokens()->delete();

        SecurityLogger::logRequest('user.deactivate', $request, $request->user(), [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User deactivated. Their entry history is kept.',
            'data' => $user->fresh()->toFrontendArray(),
        ]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $user->update(['is_active' => true]);

        SecurityLogger::logRequest('user.activate', $request, $request->user(), [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User reactivated.',
            'data' => $user->fresh()->toFrontendArray(),
        ]);
    }

    /**
     * Set a new password on someone's behalf.
     *
     * Existing tokens are dropped so a stolen session cannot outlive the
     * password it was issued under.
     */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
            'password.min' => 'The password must be at least 8 characters.',
        ]);

        $user->password = $validated['password'];
        $user->save();

        $user->tokens()->delete();

        SecurityLogger::logRequest('user.reset_password', $request, $request->user(), [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password reset. That user has been logged out everywhere.',
        ]);
    }

    /**
     * Refuse any change that would leave the system with nobody able to
     * administer it, or that would lock the caller out of their own account.
     *
     * Returns the error response, or null when the change is safe.
     */
    private function lockoutGuards(Request $request, User $user, array $changes): ?JsonResponse
    {
        $actor = $request->user();

        $wouldDemote = array_key_exists('role', $changes) && $changes['role'] !== User::ROLE_ADMIN;
        $wouldDeactivate = array_key_exists('is_active', $changes) && ! $changes['is_active'];

        if ($user->is($actor) && ($wouldDemote || $wouldDeactivate)) {
            return $this->refuse(
                'You cannot change your own role or deactivate your own account.',
                'SELF_LOCKOUT'
            );
        }

        // Backstop for the case above. In practice the self-check already
        // covers it: if the target is a different admin then at least two
        // active admins exist, so the count can never be 1 here. It stays
        // because it costs one query and is the thing that actually matters
        // if the self-check is ever loosened.
        $wouldLoseAdminAccess = $user->isAdmin()
            && $user->isActive()
            && ($wouldDemote || $wouldDeactivate);

        if ($wouldLoseAdminAccess && $this->activeAdminCount() <= 1) {
            return $this->refuse(
                'This is the only active admin. Promote another admin first.',
                'LAST_ADMIN'
            );
        }

        return null;
    }

    private function activeAdminCount(): int
    {
        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->count();
    }

    private function refuse(string $message, string $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => $code,
        ], 422);
    }
}
