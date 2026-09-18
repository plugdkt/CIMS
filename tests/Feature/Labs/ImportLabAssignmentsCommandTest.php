<?php

use App\Models\Lab;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeLabAssignmentsFixture(array $rows): string
{
    $path = sys_get_temp_dir().'/lab_assignments_test_'.uniqid().'.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, ['ลำดับ', 'ชื่อ-นามสกุล', 'ชื่อผู้ใช้งาน (UP Account)', 'ฝ่ายงาน/สังกัด', 'ตำแหน่งงาน', 'อีเมล']);
    foreach ($rows as $i => $row) {
        fputcsv($fh, [$i + 1, ...$row]);
    }
    fclose($fh);

    return $path;
}

test('an existing user with no lab yet gets assigned immediately', function () {
    $user = User::factory()->create(['username' => 'somchai.j', 'lab_id' => null]);
    $path = writeLabAssignmentsFixture([
        ['สมชาย ใจดี', 'somchai.j', 'ชีวเคมี', 'อาจารย์', '-'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('กำหนดสาขาให้ผู้ใช้ที่มีบัญชีอยู่แล้ว: 1 คน');

    $lab = Lab::where('code', 'LAB-BIOCHEM')->firstOrFail();
    expect($lab->name_th)->toBe('ชีวเคมี');
    expect($user->fresh()->lab_id)->toBe($lab->id);
});

test('an existing user who already has a lab is skipped, not overwritten', function () {
    $existingLab = Lab::create(['code' => 'LAB-EXISTING', 'name_th' => 'ของเดิม', 'is_active' => true]);
    $user = User::factory()->create(['username' => 'somchai.j', 'lab_id' => $existingLab->id]);
    $path = writeLabAssignmentsFixture([
        ['สมชาย ใจดี', 'somchai.j', 'ชีวเคมี', 'อาจารย์', '-'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('ข้าม (มีสาขาตั้งไว้อยู่ก่อนแล้ว ไม่ทับ): 1 คน');

    expect($user->fresh()->lab_id)->toBe($existingLab->id);
});

test('a username with no account yet is pre-created outright, with no sso_subject and no role', function () {
    $path = writeLabAssignmentsFixture([
        ['บุคคล ใหม่', 'newperson.x', 'สรีรวิทยา', 'นักวิทยาศาสตร์', 'newperson.x@up.ac.th'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('สร้างบัญชีล่วงหน้าให้');

    $lab = Lab::where('code', 'LAB-PHYSIO')->firstOrFail();
    $user = User::where('username', 'newperson.x')->firstOrFail();
    expect($user->sso_subject)->toBeNull();
    expect($user->lab_id)->toBe($lab->id);
    expect($user->full_name)->toBe('บุคคล ใหม่');
    expect($user->email)->toBe('newperson.x@up.ac.th');
    expect($user->pos_name)->toBe('นักวิทยาศาสตร์');
    expect($user->div_name)->toBe('สรีรวิทยา');
    expect($user->is_active)->toBeTrue();
    expect($user->roles)->toBeEmpty();
});

test('a missing email in the source file gets a synthesized placeholder, not a literal dash', function () {
    $path = writeLabAssignmentsFixture([
        ['บุคคล ใหม่', 'newperson.x', 'สรีรวิทยา', 'นักวิทยาศาสตร์', '-'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])->assertExitCode(0);

    expect(User::where('username', 'newperson.x')->firstOrFail()->email)->toBe('newperson.x@up.ac.th');
});

test('a department not in the known mapping is skipped entirely, no Lab or user is created for it', function () {
    $path = writeLabAssignmentsFixture([
        ['วิทยา สุนสะดี', 'wittaya.su', 'สำนักงานธุรการ', 'นักวิชาการคอมพิวเตอร์', '-'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])
        ->assertExitCode(0)
        ->expectsOutputToContain('ข้าม (ฝ่ายงาน/สังกัด ไม่ได้ผูกกับสาขาใดในระบบ, 1 แถว): สำนักงานธุรการ');

    expect(Lab::where('name_th', 'สำนักงานธุรการ')->exists())->toBeFalse();
    expect(User::where('username', 'wittaya.su')->exists())->toBeFalse();
});

test('re-running the import against the same file is idempotent — no duplicate user or Lab', function () {
    $path = writeLabAssignmentsFixture([
        ['บุคคล ใหม่', 'newperson.x', 'สรีรวิทยา', 'นักวิทยาศาสตร์', '-'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])->assertExitCode(0);
    $this->artisan('users:import-lab-assignments', ['path' => $path])->assertExitCode(0);

    expect(Lab::where('code', 'LAB-PHYSIO')->count())->toBe(1);
    expect(User::where('username', 'newperson.x')->count())->toBe(1);
});

test('a duplicate username within the same file is only created once', function () {
    $path = writeLabAssignmentsFixture([
        ['บุคคล ใหม่', 'newperson.x', 'สรีรวิทยา', 'นักวิทยาศาสตร์', '-'],
        ['บุคคล ใหม่', 'newperson.x', 'สรีรวิทยา', 'นักวิทยาศาสตร์', '-'],
    ]);

    $this->artisan('users:import-lab-assignments', ['path' => $path])->assertExitCode(0);

    expect(User::where('username', 'newperson.x')->count())->toBe(1);
});

test('a missing file fails cleanly', function () {
    $this->artisan('users:import-lab-assignments', ['path' => '/no/such/file.csv'])
        ->assertExitCode(1);
});
