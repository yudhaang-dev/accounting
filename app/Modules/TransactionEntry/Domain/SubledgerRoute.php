<?php

namespace App\Modules\TransactionEntry\Domain;

use App\Modules\Subledger\Contracts\ControlRole;
use App\Modules\Subledger\Contracts\DocumentKind;

/**
 * The subledger route of a `structured` version: an entry of the version records a document of one kind
 * for the party named by a field, and posts to the control account of that party's profile and, when the
 * document moves cash, to the account of the cash account named by another field (TE-FR-10, SL-FR-04).
 * The optional fields give the number and the due date of the document, and the open items it settles.
 *
 * The kind `transfer` is no document: the entry moves cash from the cash account of one field to the one of
 * another, each in its own currency, and has no party and no control account.
 *
 * `posting_profile_id` names the posting profile whose control account the entries of the version post to; empty
 * means the profile of the party (SL-FR-02).
 *
 * @phpstan-type Route array{kind: string, control_role: string, party_field: string, document_number_field: string|null, due_date_field: string|null, cash_account_field: string|null, allocations_field: string|null, counterpart_cash_account_field: string|null, posting_profile_id: string|null}
 */
final class SubledgerRoute
{
    public const TRANSFER = 'transfer';

    /**
     * The shape that is stored, with every key present.
     *
     * @param  array<string, mixed>  $input
     * @return Route
     */
    public static function normalize(array $input): array
    {
        return [
            'kind' => (string) ($input['kind'] ?? ''),
            'control_role' => (string) ($input['control_role'] ?? ''),
            'party_field' => (string) ($input['party_field'] ?? ''),
            'document_number_field' => self::optional($input['document_number_field'] ?? null),
            'due_date_field' => self::optional($input['due_date_field'] ?? null),
            'cash_account_field' => self::optional($input['cash_account_field'] ?? null),
            'allocations_field' => self::optional($input['allocations_field'] ?? null),
            'counterpart_cash_account_field' => self::optional($input['counterpart_cash_account_field'] ?? null),
            'posting_profile_id' => self::optional($input['posting_profile_id'] ?? null),
        ];
    }

    /** @param  Route|null  $route */
    public static function isTransfer(?array $route): bool
    {
        return $route !== null && $route['kind'] === self::TRANSFER;
    }

    /** @param  Route  $route */
    public static function kind(array $route): DocumentKind
    {
        return DocumentKind::from($route['kind']);
    }

    /** @param  Route  $route */
    public static function role(array $route): ControlRole
    {
        return ControlRole::from($route['control_role']);
    }

    /**
     * The fields the route uses, with the type each must have; a field the route does not name is left out.
     *
     * @param  Route  $route
     * @return list<array{key: string, name: string, type: FieldType}>
     */
    public static function fields(array $route): array
    {
        $types = [
            'party_field' => FieldType::Party,
            'document_number_field' => FieldType::Reference,
            'due_date_field' => FieldType::Date,
            'cash_account_field' => FieldType::CashAccount,
            'counterpart_cash_account_field' => FieldType::CashAccount,
            'allocations_field' => FieldType::Allocations,
        ];
        $used = [];

        foreach ($types as $key => $type) {
            if (($route[$key] ?? '') !== '') {
                $used[] = ['key' => $key, 'name' => (string) $route[$key], 'type' => $type];
            }
        }

        return $used;
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
