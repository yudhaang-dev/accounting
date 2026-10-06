<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Domain\ReportSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ReportSnapshot $resource
 */
class ReportSnapshotResource extends JsonResource
{
    private bool $withResult = false;

    /** The snapshot with the values it was published with. */
    public static function withResult(ReportSnapshot $snapshot): self
    {
        $resource = new self($snapshot);
        $resource->withResult = true;

        return $resource;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'definition_id' => $this->resource->definition_id,
            'definition_version' => $this->resource->definition_version,
            'sequence' => $this->resource->sequence,
            'parameters' => $this->resource->parameters,
            'mapping_versions' => $this->resource->mapping_versions,
            'data_boundary' => $this->resource->data_boundary,
            'reason' => $this->resource->reason,
            'recomputed_from_id' => $this->resource->recomputed_from_id,
            'published_by' => $this->resource->published_by,
            'published_at' => $this->resource->published_at->toIso8601String(),
            ...($this->withResult ? ['result' => $this->resource->result] : []),
        ];
    }
}
