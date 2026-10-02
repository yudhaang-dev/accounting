<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Contracts\DimensionSets;
use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Reads and writes through the query builder, so it works without a company context: callers pass
 * the company explicitly and every query is filtered by it (P-05).
 */
final class EloquentDimensionSets implements DimensionSets
{
    public function emptySet(string $companyId): string
    {
        return $this->store($companyId, []);
    }

    public function exists(string $companyId, string $dimensionSetId): bool
    {
        return DB::table('md_dimension_sets')->where('company_id', $companyId)->where('id', $dimensionSetId)->exists();
    }

    public function resolve(string $companyId, array $dimensions, string $date): string
    {
        $dimensions = array_filter($dimensions, fn (?string $valueId): bool => $valueId !== null);

        if ($dimensions === []) {
            return $this->emptySet($companyId);
        }

        $types = DB::table('md_dimension_types')->where('company_id', $companyId)->whereIn('key', array_keys($dimensions))->get()->keyBy('key');
        $values = DB::table('md_dimension_values')->where('company_id', $companyId)->whereIn('id', array_values($dimensions))->get()->keyBy('id');
        $parents = DB::table('md_dimension_values')->where('company_id', $companyId)->whereIn('parent_id', array_values($dimensions))->distinct()->pluck('parent_id')->all();

        /** @var array<string, list<string>> $errors */
        $errors = [];
        $items = [];

        foreach ($dimensions as $key => $valueId) {
            $type = $types->get($key);
            $value = $values->get($valueId);
            $problem = null;

            $problem = match (true) {
                $type === null => __('dimensions.type_unknown', ['key' => $key]),
                $type->status !== 'active' => __('dimensions.type_inactive_for', ['type' => $type->label]),
                $value === null || $value->dimension_type_id !== $type->id => __('dimensions.value_unknown', ['type' => $type->label]),
                $value->status !== 'active' => __('dimensions.value_inactive', ['type' => $type->label, 'code' => $value->code]),
                ! $this->validOn($value, $date) => __('dimensions.value_not_valid_on', ['type' => $type->label, 'code' => $value->code, 'date' => $date]),
                $type->leaf_only && in_array($value->id, $parents, true) => __('dimensions.value_not_leaf', ['type' => $type->label, 'code' => $value->code]),
                default => null,
            };

            if ($problem !== null) {
                $errors["dimensions.{$key}"] = [is_array($problem) ? implode(' ', $problem) : $problem];

                continue;
            }

            $items[$type->id] = $value->id;
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::DimensionInvalid, errors: $errors);
        }

        return $this->store($companyId, $items);
    }

    public function resolveValues(string $companyId, array $valueIds, string $date): string
    {
        $values = DB::table('md_dimension_values as v')
            ->join('md_dimension_types as t', fn ($join) => $join->on('t.company_id', '=', 'v.company_id')->on('t.id', '=', 'v.dimension_type_id'))
            ->where('v.company_id', $companyId)
            ->whereIn('v.id', $valueIds)
            ->get(['v.id', 't.key', 't.label']);

        $keyed = [];
        /** @var array<string, list<string>> $errors */
        $errors = [];

        foreach ($values as $value) {
            if (isset($keyed[$value->key])) {
                $errors["dimensions.{$value->key}"] = [__('dimensions.type_repeated', ['type' => $value->label])];
            }

            $keyed[$value->key] = $value->id;
        }

        foreach (array_diff($valueIds, $values->pluck('id')->all()) as $unknown) {
            $errors["dimensions.{$unknown}"] = [__('dimensions.value_unknown_id')];
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::DimensionInvalid, errors: $errors);
        }

        return $this->resolve($companyId, $keyed, $date);
    }

    public function describe(string $companyId, array $dimensionSetIds): array
    {
        if ($dimensionSetIds === []) {
            return [];
        }

        $described = array_fill_keys(
            DB::table('md_dimension_sets')->where('company_id', $companyId)->whereIn('id', $dimensionSetIds)->pluck('id')->all(),
            [],
        );

        $items = DB::table('md_dimension_set_items as i')
            ->join('md_dimension_types as t', fn ($join) => $join->on('t.company_id', '=', 'i.company_id')->on('t.id', '=', 'i.dimension_type_id'))
            ->where('i.company_id', $companyId)
            ->whereIn('i.dimension_set_id', $dimensionSetIds)
            ->orderBy('t.key')
            ->get(['i.dimension_set_id', 't.key', 'i.dimension_value_id']);

        foreach ($items as $item) {
            $described[$item->dimension_set_id][$item->key] = $item->dimension_value_id;
        }

        return $described;
    }

    public function descendants(string $companyId, string $valueId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE walk(id, depth) AS (
                SELECT v.id, 1 FROM md_dimension_values v WHERE v.company_id = ? AND v.parent_id = ?
                UNION ALL
                SELECT v.id, w.depth + 1 FROM md_dimension_values v JOIN walk w ON v.parent_id = w.id WHERE v.company_id = ?
            ) SELECT id FROM walk ORDER BY depth, id',
            [$companyId, $valueId, $companyId],
        );

        return array_values(array_map(fn (stdClass $row): string => (string) $row->id, $rows));
    }

    public function ancestors(string $companyId, string $valueId): array
    {
        $rows = DB::select(
            'WITH RECURSIVE walk(id, depth) AS (
                SELECT v.parent_id, 1 FROM md_dimension_values v WHERE v.company_id = ? AND v.id = ? AND v.parent_id IS NOT NULL
                UNION ALL
                SELECT v.parent_id, w.depth + 1 FROM md_dimension_values v JOIN walk w ON v.id = w.id WHERE v.company_id = ? AND v.parent_id IS NOT NULL
            ) SELECT id FROM walk ORDER BY depth',
            [$companyId, $valueId, $companyId],
        );

        return array_values(array_map(fn (stdClass $row): string => (string) $row->id, $rows));
    }

    public function setIdsMatching(string $companyId, array $filters): array
    {
        $query = DB::table('md_dimension_sets as s')->where('s.company_id', $companyId);

        foreach ($filters as $key => $valueId) {
            $type = DB::table('md_dimension_types')->where('company_id', $companyId)->where('key', $key)->first()
                ?? throw new ApiException(ErrorCode::DimensionInvalid, errors: ["dimensions.{$key}" => [__('dimensions.type_unknown', ['key' => $key])]]);

            $known = DB::table('md_dimension_values')->where('company_id', $companyId)->where('dimension_type_id', $type->id)->where('id', $valueId)->exists();

            if (! $known) {
                throw new ApiException(ErrorCode::DimensionInvalid, errors: ["dimensions.{$key}" => [__('dimensions.value_unknown', ['type' => $type->label])]]);
            }

            $matching = [$valueId, ...$this->descendants($companyId, $valueId)];

            $query->whereExists(fn ($sub) => $sub->from('md_dimension_set_items as i')
                ->whereColumn('i.company_id', 's.company_id')
                ->whereColumn('i.dimension_set_id', 's.id')
                ->where('i.dimension_type_id', $type->id)
                ->whereIn('i.dimension_value_id', $matching));
        }

        return array_values(array_map('strval', $query->pluck('s.id')->all()));
    }

    /**
     * Finds or creates the set for these items (type ID => value ID). Concurrent callers converge on
     * one row through the unique signature; only the creator writes the items.
     *
     * @param  array<string, string>  $items
     */
    private function store(string $companyId, array $items): string
    {
        ksort($items);
        $signature = hash('sha256', implode('|', array_map(fn (string $type, string $value): string => "{$type}:{$value}", array_keys($items), $items)));

        return DB::transaction(function () use ($companyId, $items, $signature): string {
            $id = (string) Str::ulid();

            $created = DB::table('md_dimension_sets')->insertOrIgnore([
                'id' => $id,
                'company_id' => $companyId,
                'signature' => $signature,
                'created_at' => now(),
            ]);

            if ($created === 1) {
                foreach ($items as $typeId => $valueId) {
                    DB::table('md_dimension_set_items')->insert([
                        'dimension_set_id' => $id,
                        'company_id' => $companyId,
                        'dimension_type_id' => $typeId,
                        'dimension_value_id' => $valueId,
                    ]);
                }

                return $id;
            }

            return (string) DB::table('md_dimension_sets')->where('company_id', $companyId)->where('signature', $signature)->value('id');
        });
    }

    private function validOn(stdClass $value, string $date): bool
    {
        return ($value->valid_from === null || $value->valid_from <= $date)
            && ($value->valid_to === null || $value->valid_to >= $date);
    }
}
