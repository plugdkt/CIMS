<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Auth\Services\MscAccReader;
use App\Models\AuditLog;
use App\Models\Lab;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `php artisan users:import-msc-acc` — imports personnel from the faculty's
 * MEDSCI ACC database (`account_medsci`).
 *
 * Maps divisions to Labs (001-006), generates up.ac.th emails if missing,
 * attaches initial roles based on job title (SCIENTIST for scientists,
 * STAFF/ADVISOR for lecturers, STAFF for general personnel), and preserves
 * existing user roles and lab assignments.
 *
 * @phpstan-import-type MscAccUserRow from MscAccReader
 */
final class ImportMscAccUsersCommand extends Command
{
    protected $signature = 'users:import-msc-acc
                            {--database=account_medsci : The database name for msc_acc}
                            {--dry-run : Preview imports without saving to database}';

    protected $description = 'นำเข้าข้อมูลบุคลากรจากฐานข้อมูลระบบ msc_acc (account_medsci)';

    /** @var array<string, array{code: string, name: string}> */
    private const DIVISION_LAB_MAP = [
        'กายวิภาคศาสตร์' => ['code' => '004', 'name' => 'กายวิภาคศาสตร์'],
        'จุลชีววิทยาและปรสิตวิทยา' => ['code' => '002', 'name' => 'จุลชีววิทยา'],
        'จุลชีววิทยา' => ['code' => '002', 'name' => 'จุลชีววิทยา'],
        'ชีวเคมี' => ['code' => '003', 'name' => 'ชีวเคมี'],
        'สรีรวิทยา' => ['code' => '005', 'name' => 'สรีรวิทยา'],
        'สำนักงานธุรการ' => ['code' => '001', 'name' => 'สำนักงานธุรการ'],
        'โภชนาการ' => ['code' => '006', 'name' => 'โภชนาการ'],
    ];

    public function handle(MscAccReader $reader): int
    {
        $dbName = (string) $this->option('database');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("กำลังเชื่อมต่อฐานข้อมูล: {$dbName}...");

        try {
            $rawUsers = $reader->getPersonnel($dbName);
        } catch (\Throwable $e) {
            $this->error("ไม่สามารถเชื่อมต่อฐานข้อมูล {$dbName}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("พบข้อมูลบุคลากรใน {$dbName} ทั้งหมด {$rawUsers->count()} รายการ");

        // Deduplicate usernames intelligently:
        // Prefer row with non-'สำนักงานธุรการ' department (academic branch), and row with email
        /** @var array<string, MscAccUserRow> $deduped */
        $deduped = [];
        foreach ($rawUsers as $row) {
            $username = trim((string) $row->username);
            if ($username === '') {
                continue;
            }

            if (! isset($deduped[$username])) {
                $deduped[$username] = $row;
                continue;
            }

            $current = $deduped[$username];
            $currentIsAdminOffice = ($current->div_name === 'สำนักงานธุรการ');
            $newIsAdminOffice = ($row->div_name === 'สำนักงานธุรการ');

            if ($currentIsAdminOffice && ! $newIsAdminOffice) {
                $deduped[$username] = $row;
            } elseif (! empty($row->email) && empty($current->email)) {
                $deduped[$username] = $row;
            }
        }

        $this->info('จำนวนบุคลากรหลังจากตัดชื่อผู้ใช้ซ้ำ: '.count($deduped).' คน');

        // Resolve or create Labs
        $labMap = $this->resolveLabs($dryRun);

        // Preload roles
        $roles = Role::all()->keyBy('code');

        $created = 0;
        $updated = 0;

        foreach ($deduped as $row) {
            $username = trim((string) $row->username);
            $fullName = trim((string) $row->name_user);
            $posName = $row->pos_name ? trim((string) $row->pos_name) : null;
            $divName = $row->div_name ? trim((string) $row->div_name) : null;
            $email = trim((string) $row->email);

            if ($email === '' || $email === '-' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = "{$username}@up.ac.th";
            }

            $labId = $divName !== null ? ($labMap[$divName] ?? null) : null;

            $user = User::where('username', $username)->first();

            if ($user !== null) {
                if (! $dryRun) {
                    $user->update([
                        'sso_subject' => $user->sso_subject ?? (string) $row->id_user,
                        'pos_name' => $user->pos_name ?? $posName,
                        'div_name' => $user->div_name ?? $divName,
                        'lab_id' => $user->lab_id ?? $labId,
                    ]);
                }
                $updated++;
                continue;
            }

            // New user
            if ($dryRun) {
                $created++;
                continue;
            }

            $user = User::create([
                'ulid' => (string) Str::ulid(),
                'sso_subject' => (string) $row->id_user,
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName,
                'pos_name' => $posName,
                'div_name' => $divName,
                'lab_id' => $labId,
                'is_active' => true,
            ]);

            // Assign initial roles based on position
            $assignedRoleIds = [];
            if ($posName !== null && str_contains($posName, 'นักวิทยาศาสตร์') && isset($roles['SCIENTIST'])) {
                $assignedRoleIds[] = $roles['SCIENTIST']->id;
            } elseif ($posName !== null && (str_contains($posName, 'อาจารย์') || str_contains($posName, 'คณบดี') || str_contains($posName, 'ประธานหลักสูตร'))) {
                if (isset($roles['STAFF'])) {
                    $assignedRoleIds[] = $roles['STAFF']->id;
                }
                if (isset($roles['ADVISOR'])) {
                    $assignedRoleIds[] = $roles['ADVISOR']->id;
                }
            } else {
                if (isset($roles['STAFF'])) {
                    $assignedRoleIds[] = $roles['STAFF']->id;
                }
            }

            if (! empty($assignedRoleIds)) {
                $user->roles()->syncWithoutDetaching($assignedRoleIds);
            }

            $created++;
        }

        if (! $dryRun) {
            AuditLog::record(
                action: 'BULK_USER_IMPORT',
                result: 'SUCCESS',
                message: "Imported {$created} new users, updated {$updated} existing users from msc_acc",
            );
        }

        $this->newLine();
        $this->info('=== สรุปผลการนำเข้าข้อมูลบุคลากร ===');
        $this->line("• เพิ่มผู้ใช้งานใหม่: {$created} คน");
        $this->line("• อัปเดตข้อมูลผู้ใช้เดิม: {$updated} คน");
        if ($dryRun) {
            $this->warn(' (โหมด --dry-run: ไม่มีการบันทึกลงฐานข้อมูลจริง)');
        } else {
            $this->info('✓ นำเข้าข้อมูลสำเร็จเรียบร้อยแล้ว');
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> div_name => lab_id */
    private function resolveLabs(bool $dryRun): array
    {
        $map = [];
        foreach (self::DIVISION_LAB_MAP as $divName => $meta) {
            $lab = Lab::where('name_th', $meta['name'])
                ->orWhere('code', $meta['code'])
                ->first();

            if ($lab === null && ! $dryRun) {
                $lab = Lab::create([
                    'code' => $meta['code'],
                    'name_th' => $meta['name'],
                    'is_active' => true,
                ]);
            }

            if ($lab !== null) {
                $map[$divName] = $lab->id;
            }
        }

        return $map;
    }
}
