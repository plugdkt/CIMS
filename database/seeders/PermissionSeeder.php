<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Core permission set derived from the role descriptions in spec §3 and the
 * functional requirements each role acts on (§7). Deliberately not exhaustive —
 * later tasks (RBAC admin UI, approval/ledger services, etc.) add more permissions
 * as those features are built, rather than guessing every fine-grained code up front.
 *
 * ADMIN never gets a ledger.* write permission (§3: "ไม่มีสิทธิ์แตะ Ledger"); it does
 * get ledger.verify, a read-only integrity check FR-LG-06 originally granted to
 * AUDITOR/ADMIN together — AUDITOR lost it in the 2026-09-21 repurposing below, so
 * ADMIN is now the only holder; this is a deliberate, user-approved deviation from
 * FR-LG-06's literal wording, not an oversight.
 *
 * ADMIN also gets item.view (added for the Lab Inventory feature, docs/
 * lab_inventory_handover_spec.md) — that page's own lab-picker is explicitly meant to
 * let ADMIN browse every branch's stock, which needs the same read gate the item
 * catalog/detail pages already use. User-approved 2026-09-16, since ADMIN previously
 * had no way to view /items or /items/{id} at all.
 */
final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'requisition.create' => 'สร้างใบขอเบิก',
            'requisition.view_own' => 'ดูใบขอเบิกของตนเอง',
            'requisition.view_all' => 'ดูใบขอเบิกทั้งหมด',
            'requisition.approve_advisor' => 'อนุมัติใบขอเบิก (อาจารย์ที่ปรึกษา)',
            'requisition.approve_scientist' => 'พิจารณาใบขอเบิก (นักวิทยาศาสตร์)',
            'requisition.issue' => 'จ่ายของตามใบขอเบิก',
            'requisition.issue_override' => 'อนุมัติจ่ายเกิน 10% จากที่ขอ (BR-04)',
            'receiving.manage' => 'รับของเข้าคลัง',
            'stocktake.manage' => 'ดำเนินการตรวจนับสต๊อก',
            'ledger.view' => 'ดูบัญชีคุมวัสดุ',
            'ledger.adjust' => 'อนุมัติปรับปรุงยอด',
            'ledger.verify' => 'ตรวจสอบความสมบูรณ์ของบัญชีคุม (Hash Chain)',
            'disposal.request' => 'ขอทำลาย/ตัดจำหน่าย',
            'disposal.approve' => 'อนุมัติทำลาย/ตัดจำหน่าย',
            'item.view' => 'ดูทะเบียนสารเคมี/วัสดุ',
            'item.manage' => 'จัดการทะเบียนสารเคมี/วัสดุ',
            'location.manage' => 'จัดการผังจัดเก็บ',
            'lab.manage' => 'จัดการสาขาวิชา',
            'lab.manage_members' => 'จัดการสมาชิกที่เบิกได้ในสาขาวิชา',
            'unit.manage' => 'จัดการหน่วยนับ',
            'user.manage' => 'จัดการผู้ใช้และสิทธิ์',
            'audit.view' => 'เข้าถึง Audit Log',
            'report.view' => 'ดูรายงานและ Dashboard',
        ];

        foreach ($catalog as $code => $nameTh) {
            Permission::updateOrCreate(['code' => $code], ['name_th' => $nameTh]);
        }

        $grants = [
            'STUDENT' => ['requisition.create', 'requisition.view_own', 'item.view'],
            'STAFF' => ['requisition.create', 'requisition.view_own', 'item.view'],
            'ADVISOR' => ['requisition.create', 'requisition.view_own', 'requisition.approve_advisor', 'item.view'],
            'SCIENTIST' => [
                'requisition.create', 'requisition.view_own', 'requisition.view_all', 'requisition.approve_scientist', 'requisition.issue',
                'receiving.manage', 'stocktake.manage', 'ledger.view', 'disposal.request',
                'item.view', 'report.view',
            ],
            'LAB_MANAGER' => [
                'requisition.create', 'requisition.view_own', 'requisition.view_all', 'requisition.issue_override', 'ledger.view', 'ledger.adjust',
                'disposal.approve', 'item.view', 'item.manage', 'location.manage', 'report.view',
                'lab.manage_members',
            ],
            'ADMIN' => ['requisition.create', 'requisition.view_own', 'user.manage', 'unit.manage', 'lab.manage', 'ledger.verify', 'audit.view', 'item.view'],
            // Repurposed 2026-09-21 (user-requested): AUDITOR is no longer the
            // spec-described read-only oversight role — it's now a second,
            // independently-assignable flavor of branch-scoped warehouse manager
            // (name_th "ผู้ดูแลคลัง"), so its grants mirror LAB_MANAGER's exactly.
            // See User::isBranchManager() for the scoping side of this change.
            'AUDITOR' => [
                'requisition.create', 'requisition.view_own', 'requisition.view_all', 'requisition.issue_override', 'ledger.view', 'ledger.adjust',
                'disposal.approve', 'item.view', 'item.manage', 'location.manage', 'report.view',
                'lab.manage_members',
            ],
        ];

        foreach ($grants as $roleCode => $permissionCodes) {
            $role = Role::where('code', $roleCode)->firstOrFail();
            $permissionIds = Permission::whereIn('code', $permissionCodes)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }

        // syncWithoutDetaching() above is additive-only, so it would never remove
        // AUDITOR's old read-only grants from an already-seeded database — detach
        // them explicitly. Both stay available system-wide via ADMIN's own grant.
        $auditorRole = Role::where('code', 'AUDITOR')->first();
        if ($auditorRole !== null) {
            $staleAuditorPermissionIds = Permission::whereIn('code', ['ledger.verify', 'audit.view'])->pluck('id');
            $auditorRole->permissions()->detach($staleAuditorPermissionIds);
        }
    }
}
