<?php

namespace App\Modules\Subledger\Http;

use App\Models\User;
use App\Modules\Subledger\Application\BankReconciliationReport;
use App\Modules\Subledger\Application\ImportBankStatement;
use App\Modules\Subledger\Application\ManageBankImportMapping;
use App\Modules\Subledger\Application\MatchBankStatementLines;
use App\Modules\Subledger\Domain\BankStatement;
use App\Modules\Subledger\Domain\BankStatementLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;

class BankStatementController
{
    /**
     * The column mapping of the statement CSV of a cash account, or `null` data when none is saved yet.
     *
     * @response array{data: array{id: string, cash_account_id: string, delimiter: string, date_format: string, decimal_separator: string, has_header: bool, date_column: int, description_column: int|null, reference_column: int|null, amount_column: int|null, money_in_column: int|null, money_out_column: int|null, lock_version: int}|null}
     */
    public function mapping(ManageBankImportMapping $mappings, string $companyId, string $id): JsonResponse
    {
        $mapping = $mappings->find($id);

        return new JsonResponse(['data' => $mapping === null ? null : (new BankImportMappingResource($mapping))->resolve()]);
    }

    /**
     * Saves where the columns of the bank's CSV are. The first save creates the mapping (201); later saves
     * need the `lock_version` that was read.
     */
    public function saveMapping(SaveBankImportMappingRequest $request, ManageBankImportMapping $mappings, string $companyId, string $id): JsonResponse
    {
        $data = $request->validated();
        $expected = isset($data['lock_version']) ? (int) $data['lock_version'] : null;
        unset($data['lock_version']);

        if ($mappings->find($id) !== null) {
            return (new BankImportMappingResource($mappings->save($id, $data, $expected)))->response();
        }

        return (new BankImportMappingResource($mappings->save($id, $data, $expected)))->response()->setStatusCode(201);
    }

    /**
     * Imported statements, newest closing date first. Filter: `filter[cash_account_id]`. `per_page`
     * defaults to 50, at most 200.
     */
    public function index(ListBankStatementsRequest $request): AnonymousResourceCollection
    {
        $filter = $request->validated('filter') ?? [];

        $statements = BankStatement::query()
            ->when(isset($filter['cash_account_id']), fn ($query) => $query->where('cash_account_id', $filter['cash_account_id']))
            ->orderByDesc('closing_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50))
            ->appends($request->query());

        return BankStatementResource::collection($statements);
    }

    public function show(string $companyId, string $id): BankStatementResource
    {
        return new BankStatementResource(BankStatement::query()->findOrFail($id));
    }

    /**
     * Imports a statement into a cash account with the mapping saved for it. The whole file is rejected
     * with the line of every problem when any line is wrong, and the same file is not imported twice
     * into one account.
     */
    public function store(ImportBankStatementRequest $request, ImportBankStatement $import, string $companyId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('file');

        $statement = $import(
            $id,
            $file instanceof UploadedFile ? (string) $file->getContent() : (string) $request->validated('csv'),
            $request->validated('file_name') ?? ($file instanceof UploadedFile ? $file->getClientOriginalName() : null),
            (string) $request->validated('closing_balance'),
            $request->validated('closing_date'),
            $user->id,
        );

        return (new BankStatementResource($statement))->response()->setStatusCode(201);
    }

    /**
     * The lines of a statement in file order. Filter: `filter[matched]`. `per_page` defaults to 100, at
     * most 200.
     */
    public function lines(ListBankStatementLinesRequest $request, string $companyId, string $id): AnonymousResourceCollection
    {
        BankStatement::query()->findOrFail($id);
        $filter = $request->validated('filter') ?? [];

        $lines = BankStatementLine::query()
            ->where('statement_id', $id)
            ->when(isset($filter['matched']), fn ($query) => $query->whereNull('cash_transaction_id', not: (bool) $filter['matched']))
            ->orderBy('line_number')
            ->paginate($request->integer('per_page', 100))
            ->appends($request->query());

        return BankStatementLineResource::collection($lines);
    }

    /**
     * Cash transactions that may belong to each unmatched line: the same account, direction and amount,
     * with a date within `window_days` (7 when left out). Best first; lines without a candidate are left out.
     *
     * @response array{data: list<array{line_id: string, candidates: list<array{cash_transaction_id: string, score: int, date_difference_days: int, reference_match: bool}>}>}
     */
    public function suggestions(BankSuggestionsRequest $request, MatchBankStatementLines $matching, string $companyId, string $id): JsonResponse
    {
        $statement = BankStatement::query()->findOrFail($id);

        return new JsonResponse(['data' => $matching->suggestions($statement, $request->integer('window_days', MatchBankStatementLines::DEFAULT_WINDOW_DAYS))]);
    }

    /**
     * Reconciles the book balance of the account with the balance of the statement on its closing date:
     * what the bank has not shown, what the books do not hold, and the difference that remains.
     *
     * @response array{data: array{statement_id: string, cash_account_id: string, currency: string, closing_date: string, statement_balance: string, book_balance: string, outstanding_in: string, outstanding_out: string, unrecorded_in: string, unrecorded_out: string, adjusted_statement_balance: string, adjusted_book_balance: string, difference: string, reconciled: bool, outstanding_transactions: list<array{cash_transaction_id: string, transaction_date: string, direction: string, amount: string, reference: string|null}>, unrecorded_lines: list<array{line_id: string, statement_id: string, line_number: int, transaction_date: string, direction: string, amount: string, description: string|null, reference: string|null}>}}
     */
    public function report(BankReconciliationReport $report, string $companyId, string $id): JsonResponse
    {
        return new JsonResponse(['data' => $report(BankStatement::query()->findOrFail($id))]);
    }

    /**
     * Matches a line to a cash transaction of the same account, direction and amount that no other line
     * holds. The transaction is marked cleared on the date of the bank line unless it already was.
     */
    public function match(MatchBankStatementLineRequest $request, MatchBankStatementLines $matching, string $companyId, string $id): BankStatementLineResource
    {
        /** @var User $user */
        $user = $request->user();

        return new BankStatementLineResource($matching->match($id, (string) $request->validated('cash_transaction_id'), $user->id));
    }

    /**
     * Undoes a match. The cleared mark goes too when the match put it there.
     */
    public function unmatch(MatchBankStatementLines $matching, string $companyId, string $id): BankStatementLineResource
    {
        return new BankStatementLineResource($matching->unmatch($id));
    }
}
