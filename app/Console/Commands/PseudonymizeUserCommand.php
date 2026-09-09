<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auth\Services\PseudonymizeUserService;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * SEC-PD-04: `php artisan users:pseudonymize {user}` — a deliberately CLI-only, manual,
 * confirmation-gated action (no web route), same reasoning as `ledger:verify`/
 * `ledger:snapshot`: server access is the access-control boundary here, not an in-app
 * permission check. There is no automatic scheduled trigger for this — the retention
 * period that decides *when* a given user qualifies is still an open policy question
 * (see CLAUDE.md), so this always requires a human to decide and run it explicitly.
 */
final class PseudonymizeUserCommand extends Command
{
    protected $signature = 'users:pseudonymize {user : ulid or username of the user to pseudonymize}';

    protected $description = 'PDPA (SEC-PD-04): ล้างข้อมูลส่วนบุคคลของผู้ใช้แบบถาวร (ใช้เมื่อพ้นระยะเก็บข้อมูลแล้วเท่านั้น)';

    public function handle(PseudonymizeUserService $service): int
    {
        $identifier = (string) $this->argument('user');
        $user = User::where('ulid', $identifier)->orWhere('username', $identifier)->first();

        if ($user === null) {
            $this->error("ไม่พบผู้ใช้: {$identifier}");

            return self::FAILURE;
        }

        if ($user->pseudonymized_at !== null) {
            $this->error('ผู้ใช้นี้ถูกล้างข้อมูลส่วนบุคคลไปแล้วเมื่อ '.$user->pseudonymized_at->format('d/m/Y H:i'));

            return self::FAILURE;
        }

        $confirmed = $this->confirm(
            "ยืนยันล้างข้อมูลส่วนบุคคลของ \"{$user->full_name}\" ({$user->username}) อย่างถาวร? การกระทำนี้ย้อนกลับไม่ได้",
        );
        if (! $confirmed) {
            $this->info('ยกเลิกการดำเนินการ');

            return self::SUCCESS;
        }

        $userId = $user->id;
        $service->pseudonymize($user);

        AuditLog::record(
            action: 'PDPA_PSEUDONYMIZE',
            entityType: 'User',
            entityId: $userId,
            message: 'ล้างข้อมูลส่วนบุคคลถาวรตามนโยบายการเก็บรักษาข้อมูล (SEC-PD-04) ผ่าน php artisan users:pseudonymize',
        );

        $this->info('ล้างข้อมูลส่วนบุคคลสำเร็จ');

        return self::SUCCESS;
    }
}
