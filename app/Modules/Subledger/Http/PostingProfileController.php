<?php

namespace App\Modules\Subledger\Http;

use App\Models\User;
use App\Modules\Subledger\Application\ControlAccountRules;
use App\Modules\Subledger\Application\ManagePostingProfiles;
use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Domain\PostingProfile;
use Illuminate\Http\JsonResponse;

class PostingProfileController
{
    public function __construct(private readonly ControlAccountRules $rules) {}

    /**
     * Posting profiles of the company ordered by name, each with the account that plays every
     * control role now (`null` when it is not mapped). `valid` is false when that account no longer
     * qualifies, for example because it was deactivated.
     */
    public function index(): JsonResponse
    {
        $profiles = PostingProfile::query()->with('accounts')->orderBy('name')->orderBy('id')->get();

        return response()->json(['data' => $profiles->map(fn (PostingProfile $profile): array => $this->present($profile))->all()]);
    }

    public function show(string $companyId, string $id): JsonResponse
    {
        return response()->json(['data' => $this->present(PostingProfile::query()->with('accounts')->findOrFail($id))]);
    }

    /**
     * Adds a profile. The first profile of the company becomes the default; later ones only when
     * `is_default` is true, which moves the default.
     */
    public function store(CreatePostingProfileRequest $request, ManagePostingProfiles $profiles): JsonResponse
    {
        /** @var array{name: string, is_default?: bool} $data */
        $data = $request->validated();

        return response()->json(['data' => $this->present($profiles->create($data)->load('accounts'))], 201);
    }

    /**
     * Renames the profile or makes it the default. The default cannot be cleared, only moved to
     * another profile. `lock_version` is required.
     */
    public function update(UpdatePostingProfileRequest $request, ManagePostingProfiles $profiles, string $companyId, string $id): JsonResponse
    {
        $data = $request->validated();
        $expected = (int) $data['lock_version'];
        unset($data['lock_version']);

        return response()->json(['data' => $this->present($profiles->update($id, $data, $expected)->load('accounts'))]);
    }

    /**
     * Maps a control role of the profile to an account as a new version; the change is audited.
     * The account must be an active leaf of the right class (asset for `receivables_control`,
     * liability for `payables_control`) that accepts the `subledger` source only. `lock_version`
     * is the latest version of that role (0 when never mapped). `effective_date` defaults to today,
     * or to all dates for the first mapping.
     */
    public function mapAccount(MapControlAccountRequest $request, ManagePostingProfiles $profiles, string $companyId, string $id, string $role): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{lock_version: int, account_id: string, effective_date?: string|null} $data */
        $data = $request->validated();

        $profiles->mapAccount($id, ControlRole::from($role), $data['account_id'], (int) $data['lock_version'], $user->id, $data['effective_date'] ?? null);

        return response()->json(['data' => $this->present(PostingProfile::query()->with('accounts')->findOrFail($id))]);
    }

    /**
     * @return array{id: string, name: string, is_default: bool, lock_version: int, accounts: list<array{key: string, account_id: string|null, effective_date: string|null, valid: bool, lock_version: int}>}
     */
    private function present(PostingProfile $profile): array
    {
        $accounts = [];

        foreach (ControlRole::cases() as $role) {
            $latest = $profile->accounts->where('control_role', $role->value)->sortByDesc('version')->first();

            $accounts[] = [
                'key' => $role->value,
                'account_id' => $latest?->account_id,
                'effective_date' => $latest?->effective_date->toDateString(),
                'valid' => $latest !== null && $this->rules->problems($profile->company_id, $latest->account_id, $role) === [],
                // The version of the mapping (0 when none), named as the concurrency field (API-TR-14).
                'lock_version' => $latest->version ?? 0,
            ];
        }

        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'is_default' => $profile->is_default,
            'lock_version' => $profile->lock_version,
            'accounts' => $accounts,
        ];
    }
}
