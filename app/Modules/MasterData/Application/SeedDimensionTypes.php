<?php

namespace App\Modules\MasterData\Application;

use App\Modules\MasterData\Domain\DimensionType;
use App\Support\Company\CompanyContext;

/**
 * Seeds the two starting dimension types of a new company (MD-FR-02). They are ordinary data: a
 * company may rename, change or deactivate them, and no code looks at their keys.
 */
final class SeedDimensionTypes
{
    /** @var list<array{key: string, label: string, is_hierarchical: bool}> */
    private const TEMPLATE = [
        ['key' => 'department', 'label' => 'Department', 'is_hierarchical' => true],
        ['key' => 'project', 'label' => 'Project', 'is_hierarchical' => false],
    ];

    public function __construct(private readonly CompanyContext $context) {}

    public function __invoke(string $companyId): void
    {
        $this->context->run($companyId, function (): void {
            foreach (self::TEMPLATE as $type) {
                DimensionType::create($type);
            }
        });
    }
}
