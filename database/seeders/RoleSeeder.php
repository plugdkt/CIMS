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
            ['code' => 'LAB_MANAGER', 'name_th' => 'หัวหน้าห้องปฏิบัติการ'],
            ['code' => 'ADMIN', 'name_th' => 'ผู้ดูแลระบบ'],
            ['code' => 'AUDITOR', 'name_th' => 'ผู้ตรวจสอบ'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role);
        }
    }
}
