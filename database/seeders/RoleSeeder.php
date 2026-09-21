<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/** Roles from spec §3. */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'STUDENT', 'name_th' => 'นิสิต'],
            ['code' => 'ADVISOR', 'name_th' => 'อาจารย์ที่ปรึกษา'],
            ['code' => 'STAFF', 'name_th' => 'อาจารย์/เจ้าหน้าที่'],
            ['code' => 'SCIENTIST', 'name_th' => 'นักวิทยาศาสตร์'],
            ['code' => 'LAB_MANAGER', 'name_th' => 'หัวหน้าสาขาวิชา'],
            ['code' => 'ADMIN', 'name_th' => 'ผู้ดูแลระบบ'],
            // Repurposed 2026-09-21 (user-requested): AUDITOR reads as "ผู้ดูแลคลัง"
            // now, not the original spec-described read-only auditor — see
            // PermissionSeeder's own note on this role's grants for the full reasoning.
            ['code' => 'AUDITOR', 'name_th' => 'ผู้ดูแลคลัง'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role);
        }
    }
}
