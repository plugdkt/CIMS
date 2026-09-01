<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * audit_logs is append-only (AGENT RULE #6) — enforced at the DB layer via grants + a
 * BEFORE UPDATE/DELETE trigger (see the audit_logs migration), and mirrored here so an
 * accidental ->update()/->delete() call fails fast during development instead of at the DB.
 */
#[Fillable([
    'occurred_at', 'user_id', 'username', 'ip_address', 'user_agent', 'action',
    'entity_type', 'entity_id', 'old_value', 'new_value', 'result', 'message',
])]
final class AuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'old_value' => 'array',
            'new_value' => 'array',
        ];
    }

    /**
     * @param  string  $action  e.g. "LOGIN_SUCCESS", "REQ_APPROVE", "LEDGER_INSERT"
     * @param  array<string, mixed>|null  $oldValue
     * @param  array<string, mixed>|null  $newValue
     */
    public static function record(
        string $action,
        string $result = 'SUCCESS',
        ?int $userId = null,
        ?string $username = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $message = null,
    ): self {
        $ip = request()->ip();

        return self::create([
            'occurred_at' => now(),
            'user_id' => $userId,
            'username' => $username,
            'ip_address' => $ip !== null ? inet_pton($ip) : null,
            'user_agent' => substr((string) request()->userAgent(), 0, 512),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'result' => $result,
            'message' => $message,
        ]);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw new LogicException('audit_logs is append-only — rows cannot be updated.');
    }

    public function delete(): never
    {
        throw new LogicException('audit_logs is append-only — rows cannot be deleted.');
    }
}
