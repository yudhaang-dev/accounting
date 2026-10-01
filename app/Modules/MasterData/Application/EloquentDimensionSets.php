<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\DimensionSets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentDimensionSets implements DimensionSets
{
    public function emptySet(string $companyId): string
    {
        $signature = hash('sha256', '');

        DB::table('md_dimension_sets')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'company_id' => $companyId,
            'signature' => $signature,
            'created_at' => now(),
        ]);

        return (string) DB::table('md_dimension_sets')
            ->where('company_id', $companyId)
            ->where('signature', $signature)
            ->value('id');
    }

    public function exists(string $companyId, string $dimensionSetId): bool
    {
        return DB::table('md_dimension_sets')->where('company_id', $companyId)->where('id', $dimensionSetId)->exists();
    }
}
