<?php

declare(strict_types=1);

namespace App\Domain\Loan;

use App\Models\User;

/**
 * ITM-2610-070: the borrowing cart ("ตะกร้ายืม"), the loan module's twin of the shop's basket. It lives in the
 * session only (no table): loan asset ULID => whole quantity, under a key of its own per user, so a cart never
 * follows another account that logs in on the same browser. It is a wish list only: nothing is reserved, and every
 * rule is checked again by LoanService when the request is stored and submitted.
 *
 * Only for borrowing for oneself: counter staff typing a request in for somebody else and the edit of a DRAFT keep
 * the ordinary form.
 */
final class LoanCart
{
    public static function key(int $userId): string
    {
        return 'loans.cart.'.$userId;
    }

    /** @return array<string, int> asset ulid => quantity */
    public static function lines(User $user): array
    {
        $raw = session(self::key($user->id), []);

        $lines = [];
        foreach (is_array($raw) ? $raw : [] as $ulid => $qty) {
            if (is_string($ulid) && is_int($qty) && $qty > 0) {
                $lines[$ulid] = $qty;
            }
        }

        return $lines;
    }

    public static function count(User $user): int
    {
        return count(self::lines($user));
    }

    /** @param  array<string, int>  $lines */
    public static function put(User $user, array $lines): void
    {
        session([self::key($user->id) => $lines]);
    }

    public static function forget(User $user): void
    {
        session()->forget(self::key($user->id));
    }
}
