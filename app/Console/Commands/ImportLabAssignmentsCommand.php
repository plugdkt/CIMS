<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lab;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * `php artisan users:import-lab-assignments <path>` — one-off bulk assignment of
 * `users.lab_id` from a real HR/roster export (columns: ลำดับ, ชื่อ-นามสกุล,
 * ชื่อผู้ใช้งาน (UP Account), ฝ่ายงาน/สังกัด, ตำแหน่งงาน, อีเมล).
 *
 * User-confirmed 2026-09-18: this command sets *only* `lab_id` — it never assigns a
 * CMIS role (SCIENTIST etc.) from the job-title column, since a job title string
 * doesn't reliably map to a CMIS permission and role changes stay an explicit ADMIN
 * action via /admin/users.
 *
 * Deliberately takes a filesystem `path` argument rather than a file committed to
 * this repo — the source export is a real personal-data roster; see CHANGELOG.md.
 *
 * `ฝ่ายงาน/สังกัด` values not listed in DEPARTMENT_LAB_CODES are skipped, not guessed
 * at — e.g. "สำนักงานธุรการ" (administrative office) is real staff but has no actual
 * chemical inventory, user-confirmed not to become a Lab record.
 *
 * A username that already has a `users` row gets `lab_id` set immediately, but only
 * if it's still null — this command never overwrites a lab an ADMIN already set by
 * hand. A username with no `users` row yet is *pre-created* here (`sso_subject` left
 * null — a `users` row otherwise can only ever be created by a real SSO login) so the
 * branch is assigned right away rather than waiting for that person to log in;
 * `UserProvisioningService::provision()` claims the row (sets `sso_subject`) the
 * moment that username's first real SSO login happens — see its own doc comment.
 */
final class ImportLabAssignmentsCommand extends Command
{
    protected $signature = 'users:import-lab-assignments {path}';

    protected $description = 'กำหนดสาขา (lab_id) ให้ผู้ใช้จากไฟล์ CSV รายชื่อบุคลากร (ฝ่ายงาน/สังกัด -> สาขา)';

    /** @var array<string, string> ฝ่ายงาน/สังกัด (จากไฟล์) => รหัสสาขาภายในของ CMIS */
    private const DEPARTMENT_LAB_CODES = [
        'กายวิภาคศาสตร์' => 'LAB-ANAT',
        'จุลชีววิทยาและปรสิตวิทยา' => 'LAB-MICRO',
        'ชีวเคมี' => 'LAB-BIOCHEM',
        'สรีรวิทยา' => 'LAB-PHYSIO',
        'โภชนาการ' => 'LAB-NUTRI',
    ];

    public function handle(): int
    {
        /** @var string $path */
        $path = $this->argument('path');
        if (! is_file($path)) {
            $this->error("ไม่พบไฟล์: {$path}");

            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error("เปิดไฟล์ไม่ได้: {$path}");

            return self::FAILURE;
        }

        fgetcsv($fh); // header row

        $labIds = $this->resolveLabIds();

        $assignedExisting = 0;
        $preCreated = 0;
        $skippedHasLab = 0;
        $skippedDept = 0;
        /** @var array<string, true> $skippedDeptNames */
        $skippedDeptNames = [];

        while (($row = fgetcsv($fh)) !== false) {
            [, $fullName, $username, $department, $jobTitle, $email] = array_pad($row, 6, null);
            $username = trim((string) $username);
            $department = trim((string) $department);
            $fullName = trim((string) $fullName);
            $jobTitle = trim((string) $jobTitle);
            $email = trim((string) $email);

            if ($username === '' || $department === '') {
                continue;
            }

            $labId = $labIds[$department] ?? null;
            if ($labId === null) {
                $skippedDept++;
                $skippedDeptNames[$department] = true;

                continue;
            }

            $user = User::where('username', $username)->first();

            if ($user !== null) {
                if ($user->lab_id !== null) {
                    $skippedHasLab++;

                    continue;
                }

                $user->update(['lab_id' => $labId]);
                $assignedExisting++;

                continue;
            }

            // No real account yet — pre-create one now rather than making this
            // person's branch wait for their first SSO login. `sso_subject` stays
            // null until they actually log in and UserProvisioningService claims it;
            // every SSO-owned field here (name/email/pos_name/div_name) is only a
            // placeholder, overwritten with the authoritative SSO payload at that
            // point, same as a normal first login always does.
            User::create([
                'ulid' => (string) Str::ulid(),
                'sso_subject' => null,
                'username' => $username,
                'email' => ($email !== '' && $email !== '-') ? $email : "{$username}@up.ac.th",
                'full_name' => $fullName !== '' ? $fullName : $username,
                'pos_name' => $jobTitle !== '' ? $jobTitle : null,
                'div_name' => $department,
                'lab_id' => $labId,
                'is_active' => true,
            ]);
            $preCreated++;
        }

        fclose($fh);

        $this->info("กำหนดสาขาให้ผู้ใช้ที่มีบัญชีอยู่แล้ว: {$assignedExisting} คน");
        $this->info("ข้าม (มีสาขาตั้งไว้อยู่ก่อนแล้ว ไม่ทับ): {$skippedHasLab} คน");
        $this->info("สร้างบัญชีล่วงหน้าให้ (ยังไม่เคย Login — จะยืนยันตัวตนจริงตอน Login ครั้งแรก): {$preCreated} คน");

        if ($skippedDept > 0) {
            $this->line(
                "ข้าม (ฝ่ายงาน/สังกัด ไม่ได้ผูกกับสาขาใดในระบบ, {$skippedDept} แถว): "
                .implode(', ', array_keys($skippedDeptNames))
            );
        }

        return self::SUCCESS;
    }

    /** @return array<string, int> ฝ่ายงาน/สังกัด => labs.id (สร้าง Lab ให้ถ้ายังไม่มี) */
    private function resolveLabIds(): array
    {
        $ids = [];
        foreach (self::DEPARTMENT_LAB_CODES as $departmentName => $code) {
            $lab = Lab::firstOrCreate(['code' => $code], ['name_th' => $departmentName, 'is_active' => true]);
            $ids[$departmentName] = $lab->id;
        }

        return $ids;
    }
}
