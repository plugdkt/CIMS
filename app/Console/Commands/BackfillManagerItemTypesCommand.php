<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\ItemType;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITM-2610-052 phase 3: gives every existing warehouse manager (LAB_MANAGER / AUDITOR) BOTH kinds of
 * stock, so behaviour on the day of deploy stays what it was. It is a command and not part of the
 * migration because the migration runs before restrict_app_grants.sql has given `cmis_app` rights on
 * the new `user_item_types` table.
 *
 * Deploy order: (1) php artisan migrate (2) re-run docker/mariadb/restrict_app_grants.sql on every
 * database (3) php artisan managers:backfill-item-types. Without step 3 every manager is "deny by
 * default": none can see or decide requisitions (the /warehouse and queue pages say so).
 *
 * Safe to re-run: only managers with NO kind row at all are touched, so it never gives a kind back to
 * someone an ADMIN has narrowed. (A manager whose kinds were ALL removed by an ADMIN has no row either
 * and would get both kinds again on a re-run, so do not re-run it as routine.)
 */
final class BackfillManagerItemTypesCommand extends Command
{
    protected $signature = 'managers:backfill-item-types {--dry-run : Only count, write nothing}';

    protected $description = 'ให้ผู้ดูแลคลังทุกคนที่ยังไม่มีชนิดที่รับผิดชอบ ได้ทั้งสารเคมีและวัสดุสิ้นเปลือง (รันหลัง migrate และ restrict_app_grants.sql)';

    public function handle(): int
    {
        if (! Schema::hasTable('user_item_types')) {
            $this->error('ยังไม่มีตาราง user_item_types กรุณารัน php artisan migrate ก่อน แล้วรัน docker/mariadb/restrict_app_grants.sql ซ้ำ จากนั้นจึงรันคำสั่งนี้อีกครั้ง');

            return self::FAILURE;
        }

        try {
            return $this->backfill();
        } catch (QueryException $e) {
            // Most likely cmis_app has no rights on the new table yet (grants script not re-run).
            $this->error('เขียน/อ่านตาราง user_item_types ไม่ได้ (น่าจะยังไม่ได้รัน docker/mariadb/restrict_app_grants.sql ซ้ำหลัง migrate) กรุณารันสคริปต์นั้นกับทุกฐานแล้วลองใหม่');
            $this->line('รายละเอียดจากฐานข้อมูล: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function backfill(): int
    {
        $managerIds = User::whereHas('roles', fn ($q) => $q->whereIn('code', ['LAB_MANAGER', 'AUDITOR']))
            ->whereDoesntHave('itemTypes')
            ->pluck('id');

        if ($this->option('dry-run')) {
            $this->info("จะเพิ่มชนิดให้ผู้ดูแลคลัง {$managerIds->count()} คน (dry-run ไม่ได้เขียนอะไร)");

            return self::SUCCESS;
        }

        $added = 0;
        foreach ($managerIds->chunk(200) as $chunk) {
            $rows = [];
            foreach ($chunk as $userId) {
                foreach (ItemType::all() as $type) {
                    $rows[] = ['user_id' => $userId, 'item_type' => $type];
                }
            }
            $added += DB::table('user_item_types')->insertOrIgnore($rows);
        }

        if ($added > 0) {
            AuditLog::record(
                action: 'ITEM_TYPE_BACKFILL',
                message: "managers:backfill-item-types added {$added} rows for {$managerIds->count()} managers",
            );
        }

        $this->info("เพิ่มแล้ว {$added} แถว สำหรับผู้ดูแลคลัง {$managerIds->count()} คน");

        return self::SUCCESS;
    }
}
