<?php

namespace App\Modules\Consolidation\Http;

use App\Modules\Consolidation\Domain\PerimeterMember;
use App\Modules\Reporting\Contracts\ReportPackageAccount;
use App\Modules\Reporting\Contracts\ReportPackages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MemberAccountController
{
    /**
     * The accounts of a member company that a mapping can use (active, postable), by code. Only for a company
     * that is an approved member of the group: reading a group does not open any other company (CO-FR-01).
     *
     * @response array{data: list<array{id: string, code: string, name: string, class: string}>}
     */
    public function index(Request $request, ReportPackages $packages, string $group): JsonResponse
    {
        $companyId = $request->validate(['company_id' => ['required', 'string', 'ulid']])['company_id'];

        $member = PerimeterMember::query()->where('group_id', $group)->where('company_id', $companyId)->where('status', PerimeterMember::APPROVED)->exists();

        if (! $member) {
            throw ValidationException::withMessages(['company_id' => [__('consolidation.mapping_company_not_member')]]);
        }

        return response()->json(['data' => array_map(
            fn (ReportPackageAccount $account): array => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'class' => $account->class],
            $packages->accounts($companyId),
        )]);
    }
}
