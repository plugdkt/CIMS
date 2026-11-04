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
            'requisition.approve_scientist' => 'อนุมัติ/พิจารณาใบขอเบิก (นักวิทยาศาสตร์และผู้ดูแลคลัง ในสาขาของตน)',
            'requisition.issue' => 'จ่ายของตามใบขอเบิก',
            'requisition.issue_override' => 'อนุมัติจ่ายเกิน 10% จากที่ขอ (BR-04)',
            'receiving.manage' => 'รับของเข้าคลัง',
            'ims.view' => 'ดูคลัง IMS (คลังกลางของสาขา)',
            'ims.browse' => 'เปิดดูของในคลังกลางของสาขาตัวเอง (อ่านอย่างเดียว ไม่เห็นราคา)',
            'ims.manage' => 'จัดการคลัง IMS (นำเข้าเอกสารจัดซื้อ ยืนยัน ตัดจ่ายเข้า working stock)',
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
            'STUDENT' => ['requisition.create', 'requisition.view_own', 'item.view', 'ims.browse'],
            'STAFF' => ['requisition.create', 'requisition.view_own', 'item.view', 'ims.browse'],
            'ADVISOR' => ['requisition.create', 'requisition.view_own', 'requisition.approve_advisor', 'item.view', 'ims.browse'],
            // User-requested 2026-09-22: stock takes, disposals, adjustments and the reports
            // page all belong to the warehouse managers (LAB_MANAGER/AUDITOR) and ADMIN.
            // SCIENTIST is now purely the dispensing role — create/view their own
            // requisitions, issue against approved ones, and read the item ledger.
            // ITM-2610-051 / approvals.md K (CEO, 2026-11-04): the 2026-09-21 move of requisition
            // approval to the warehouse managers is reversed. Every scientist of a branch may approve that
            // branch's requisitions (RequisitionPolicy::scientistDecide checks lab_id), and the warehouse
            // managers below may issue as well as approve. ADMIN still approves nothing.
            'SCIENTIST' => [
                'requisition.create', 'requisition.view_own', 'requisition.issue', 'requisition.approve_scientist',
                'ledger.view', 'item.view', 'ims.browse',
            ],
            'LAB_MANAGER' => [
                'requisition.create', 'requisition.view_own', 'requisition.view_all', 'requisition.approve_scientist', 'requisition.issue', 'requisition.issue_override', 'receiving.manage', 'stocktake.manage', 'ledger.view', 'ledger.adjust',
                'disposal.request', 'disposal.approve', 'item.view', 'item.manage', 'location.manage', 'report.view',
                'lab.manage_members', 'ims.view', 'ims.manage',
            ],
            // ADMIN sees every branch's reports (the reports page's own lab picker lets them
            // narrow to one) and reaches the stock-take/disposal/adjustment menus, but still
            // holds no ledger.* write permission (spec §3), kept when this area was handed to
            // the warehouse managers (2026-09-22). CEO decision E7 (ITM-2610-036): ADMIN may still
            // receive goods via the legacy GRN (`receiving.manage`), which writes RECEIVE ledger
            // rows indirectly. ADMIN cannot adjust stock (no ledger.adjust), approve a stock take,
            // approve a disposal (no disposal.approve), import/confirm IMS receipts (no ims.manage)
            // or issue/return goods (no requisition.issue). See StockLedgerPolicy::viewAdjustments() for how the
            // adjustments menu stays visible without granting the write.
            'ADMIN' => ['requisition.create', 'requisition.view_own', 'requisition.view_all', 'receiving.manage', 'stocktake.manage', 'disposal.request', 'user.manage', 'unit.manage', 'lab.manage', 'ledger.verify', 'audit.view', 'item.view', 'report.view', 'ims.view'],
            // Repurposed 2026-09-21 (user-requested): AUDITOR is no longer the
            // spec-described read-only oversight role — it's now a second,
            // independently-assignable flavor of branch-scoped warehouse manager
            // (name_th "ผู้ดูแลคลัง"), so its grants mirror LAB_MANAGER's exactly.
            // See User::isBranchManager() for the scoping side of this change.
            'AUDITOR' => [
                'requisition.create', 'requisition.view_own', 'requisition.view_all', 'requisition.approve_scientist', 'requisition.issue', 'requisition.issue_override', 'receiving.manage', 'stocktake.manage', 'ledger.view', 'ledger.adjust',
                'disposal.request', 'disposal.approve', 'item.view', 'item.manage', 'location.manage', 'report.view',
                'lab.manage_members', 'ims.view', 'ims.manage',
            ],
        ];

        foreach ($grants as $roleCode => $permissionCodes) {
            $role = Role::where('code', $roleCode)->firstOrFail();
            $permissionIds = Permission::whereIn('code', $permissionCodes)->pluck('id');
            $role->permissions()->syncWithoutDetaching($permissionIds);
        }

        // syncWithoutDetaching() above is additive-only, so every grant SCIENTIST has lost
        // has to be detached explicitly from an already-seeded database. Viewing all
        // requisitions and stock-in/receiving moved to the warehouse managers on 2026-09-21;
        // stock takes, disposals and the reports page followed on 2026-09-22. (Requisition
        // approval moved there too, but came back to SCIENTIST on 2026-11-04 — deliberately NOT
        // detached any more, or every seed would take it away again.)
        $scientistRole = Role::where('code', 'SCIENTIST')->first();
        if ($scientistRole !== null) {
            $staleScientistPermissionIds = Permission::whereIn('code', [
                'requisition.view_all',
                'receiving.manage',
                'stocktake.manage',
                'disposal.request',
                'report.view',
            ])->pluck('id');
            $scientistRole->permissions()->detach($staleScientistPermissionIds);
        }

        // Also detach AUDITOR's old read-only grants from an already-seeded database.
        // requisition.issue is no longer detached from LAB_MANAGER/AUDITOR: since 2026-11-04 the
        // warehouse managers dispense as well (approvals.md K), so it is part of their grants above.
        $auditorRole = Role::where('code', 'AUDITOR')->first();
        if ($auditorRole !== null) {
            $staleAuditorPermissionIds = Permission::whereIn('code', ['ledger.verify', 'audit.view'])->pluck('id');
            $auditorRole->permissions()->detach($staleAuditorPermissionIds);
        }

        $this->seedLoanPermissions();
    }

    /**
     * ITM-2610-051 (borrow / return module): the loan.* permission codes and who holds them. A separate block
     * (own arrays) so nothing above changes. LAB_MANAGER and AUDITOR get the IDENTICAL set (RoleScopingTest
     * pins that); ADMIN gets every code except loan.approve_advisor (the advisor step is never ADMIN's,
     * BR-L03), and that exception to the "ADMIN never writes a ledger" rule covers loan_movements only, never
     * stock_ledger. `loan.verify` is ADMIN only. SCIENTIST is the counter role: it borrows like anyone and
     * hands out / receives back for its own branch.
     *
     * Updated 2026-11-04 (approvals.md K): loan approvers follow the requisition rule (every scientist plus the
     * warehouse managers of the branch, never ADMIN); `loan.asset_approve` marks who may be named as the second
     * person of an ADJUST / RETIRE.
     */
    private function seedLoanPermissions(): void
    {
        $loanCatalog = [
            'loan.create' => 'สร้าง/ส่งคำขอยืมของตนเอง',
            'loan.view_own' => 'ดูรายการยืมของตนเอง',
            'loan.view_all' => 'ดูรายการยืมทั้งหมด (จำกัดสาขาตามบทบาท)',
            'loan.approve_advisor' => 'อนุมัติคำขอยืมของนิสิตในความดูแล',
            'loan.approve' => 'อนุมัติ/ปฏิเสธคำขอยืม (นักวิทยาศาสตร์และผู้ดูแลคลัง ในสาขาของตน)',
            'loan.handout' => 'ส่งมอบของ รับคืน บันทึกสภาพ และบันทึกเหตุเสียหาย/สูญหาย',
            'loan.view_queue' => 'ดูคิวส่งมอบ/รับคืนของสาขา',
            'loan.renew' => 'ขอต่อกำหนดคืน',
            'loan.cancel' => 'ยกเลิกเอกสารยืมก่อนส่งมอบ',
            'loan.incident_resolve' => 'บันทึกผลเรื่องเสียหาย/สูญหาย',
            'loan.asset_manage' => 'ขึ้นทะเบียน/ปรับ/ปลดระวางของให้ยืม',
            'loan.asset_approve' => 'เป็นผู้อนุมัติแทนการปรับยอด/ปลดระวางของให้ยืม (คนละคนกับผู้ทำรายการ)',
            'loan.verify' => 'ตรวจ hash chain ของบัญชีของให้ยืม',
            'loan.report' => 'รายงานและ Dashboard ยืม-คืน',
        ];

        foreach ($loanCatalog as $code => $nameTh) {
            Permission::updateOrCreate(['code' => $code], ['name_th' => $nameTh]);
        }

        $borrower = ['loan.create', 'loan.view_own', 'loan.renew', 'loan.cancel'];
        $branchManager = [
            'loan.create', 'loan.view_own', 'loan.view_all', 'loan.approve', 'loan.handout', 'loan.view_queue',
            'loan.renew', 'loan.cancel', 'loan.incident_resolve', 'loan.asset_manage', 'loan.asset_approve', 'loan.report',
        ];

        $loanGrants = [
            'STUDENT' => $borrower,
            'STAFF' => $borrower,
            'ADVISOR' => array_merge($borrower, ['loan.approve_advisor']),
            'SCIENTIST' => array_merge($borrower, ['loan.handout', 'loan.view_queue', 'loan.approve', 'loan.asset_approve']),
            'LAB_MANAGER' => $branchManager,
            'AUDITOR' => $branchManager,
            // ADMIN approves neither loans nor an ADJUST / RETIRE (approvals.md K; ADMIN stand-in is off by default).
            'ADMIN' => array_values(array_diff(array_keys($loanCatalog), ['loan.approve_advisor', 'loan.approve', 'loan.asset_approve'])),
        ];

        foreach ($loanGrants as $roleCode => $permissionCodes) {
            $role = Role::where('code', $roleCode)->firstOrFail();
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('code', $permissionCodes)->pluck('id'));
        }

        // Already-seeded databases: ADMIN used to hold loan.approve, which the additive sync above cannot remove.
        $adminRole = Role::where('code', 'ADMIN')->first();
        if ($adminRole !== null) {
            $adminRole->permissions()->detach(Permission::whereIn('code', ['loan.approve', 'loan.asset_approve'])->pluck('id'));
        }
    }
}
