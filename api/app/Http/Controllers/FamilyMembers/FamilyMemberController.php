<?php

namespace App\Http\Controllers\FamilyMembers;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\FamilyMembers\StoreFamilyMemberRequest;
use App\Jobs\SyncConnection;
use App\Models\FamilyMember;
use App\Services\Steam\SteamClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class FamilyMemberController extends Controller
{
    /**
     * I.api: status comes from the joined synthetic steam_family connection.
     */
    public function index(Request $request): JsonResponse
    {
        $members = $request->user()->familyMembers()->with('connection')->get();

        return response()->json($members->map(fn (FamilyMember $member) => $this->present($member))->values());
    }

    /**
     * V25: identity was already resolved+confirmed via the existing
     * GET /api/connections/steam/resolve — this creates the synthetic
     * steam_family connection (V58, mirrors manual T14/V19) + family_members
     * row, then queues the initial sync.
     */
    public function store(StoreFamilyMemberRequest $request): JsonResponse
    {
        $steamId = $request->validated()['steam_id'];
        $user = $request->user();

        if ($user->familyMembers()->where('steam_id', $steamId)->exists()) {
            throw ValidationException::withMessages([
                'steam_id' => ['That family member is already added.'],
            ]);
        }

        $identity = app(SteamClient::class)->playerSummary($steamId);

        abort_if($identity === null, Response::HTTP_NOT_FOUND);

        $connection = $user->platformConnections()->create([
            'platform' => 'steam_family',
            'external_account_id' => $steamId,
            'status' => ConnectionStatus::Pending,
        ]);

        $member = $user->familyMembers()->create([
            'steam_id' => $steamId,
            'persona_name' => $identity['persona_name'],
            'avatar_url' => $identity['avatar_url'],
            'platform_connection_id' => $connection->id,
        ]);

        SyncConnection::dispatch($connection->id);

        return response()->json($this->present($member->setRelation('connection', $connection)), Response::HTTP_CREATED);
    }

    /**
     * Hard-delete (⊥ V13 soft-keep — no real "your data" to preserve):
     * deleting the synthetic connection cascades the family_members row and
     * every shared owned_games row sourced from it.
     */
    public function destroy(Request $request, FamilyMember $familyMember): JsonResponse
    {
        abort_unless($familyMember->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);

        $familyMember->connection->delete();

        return response()->json(null, Response::HTTP_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FamilyMember $member): array
    {
        return [
            'id' => $member->id,
            'steam_id' => $member->steam_id,
            'persona_name' => $member->persona_name,
            'avatar_url' => $member->avatar_url,
            'last_synced_at' => $member->connection->last_synced_at?->toIso8601String(),
            'status' => $member->connection->status->value,
        ];
    }
}
