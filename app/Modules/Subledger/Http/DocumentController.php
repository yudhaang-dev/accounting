<?php

namespace App\Modules\Subledger\Http;

use App\Modules\Subledger\Domain\Document;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController
{
    /**
     * Documents, newest first. Filters: `filter[party_id]`, `filter[kind]`, `filter[date_from]` and
     * `filter[date_to]` on the date of the document. `per_page` defaults to 50, at most 200. Documents
     * are recorded by entries that have a subledger route, not through this API.
     */
    public function index(ListDocumentsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $documents = Document::query()
            ->when(isset($filter['party_id']), fn ($query) => $query->where('party_id', $filter['party_id']))
            ->when(isset($filter['kind']), fn ($query) => $query->where('kind', $filter['kind']))
            ->when(isset($filter['date_from']), fn ($query) => $query->whereDate('document_date', '>=', $filter['date_from']))
            ->when(isset($filter['date_to']), fn ($query) => $query->whereDate('document_date', '<=', $filter['date_to']))
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return DocumentResource::collection($documents);
    }

    public function show(string $companyId, string $id): DocumentResource
    {
        return new DocumentResource(Document::query()->findOrFail($id));
    }
}
