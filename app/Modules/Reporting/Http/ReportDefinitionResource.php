<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\Domain\ReportDefinition;
use App\Modules\Reporting\Domain\ReportDefinitionVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ReportDefinition $resource
 */
class ReportDefinitionResource extends JsonResource
{
    private ?ReportDefinitionVersion $version = null;

    /** The definition with the structure of one of its versions. */
    public static function withVersion(ReportDefinition $definition, ReportDefinitionVersion $version): self
    {
        $resource = new self($definition);
        $resource->version = $version;

        return $resource;
    }

    /**
     * @return array{id: string, key: string, name: string, kind: string, current_version: int, publish_on_period_close: bool, publish_on_year_close: bool, lock_version: int, version: int|null, structure: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'key' => $this->resource->key,
            'name' => $this->resource->name,
            'kind' => $this->resource->kind,
            'current_version' => $this->resource->current_version,
            'publish_on_period_close' => $this->resource->publish_on_period_close,
            'publish_on_year_close' => $this->resource->publish_on_year_close,
            'lock_version' => $this->resource->lock_version,
            'version' => $this->version?->version,
            'structure' => $this->version?->structure,
        ];
    }
}
