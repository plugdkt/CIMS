<?php

use App\Domain\Auth\Services\MscAccReader;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it handles database connection failure cleanly', function () {
    $mockReader = Mockery::mock(MscAccReader::class);
    $mockReader->shouldReceive('getPersonnel')
        ->with('non_existent_database_99999')
        ->andThrow(new \RuntimeException('Connection refused'));
    $this->instance(MscAccReader::class, $mockReader);

    $this->artisan('users:import-msc-acc', ['--database' => 'non_existent_database_99999'])
        ->assertExitCode(1)
        ->expectsOutputToContain('ไม่สามารถเชื่อมต่อฐานข้อมูล');
});

test('it previews imports in dry-run mode without creating or updating users', function () {
    $mockReader = Mockery::mock(MscAccReader::class);
    $mockReader->shouldReceive('getPersonnel')
        ->andReturn(collect([
            (object) [
                'id_user' => 10,
                'name_user' => 'ผศ.ดร.ทดสอบ ชีวเคมี',
                'username' => 'test.biochem',
                'email' => 'test.biochem@up.ac.th',
                'pos_name' => 'อาจารย์',
                'div_name' => 'ชีวเคมี',
            ],
        ]));
    $this->instance(MscAccReader::class, $mockReader);

    $this->artisan('users:import-msc-acc', ['--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('โหมด --dry-run')
        ->expectsOutputToContain('เพิ่มผู้ใช้งานใหม่: 1 คน');

    expect(User::where('username', 'test.biochem')->exists())->toBeFalse();
    expect(AuditLog::where('action', 'BULK_USER_IMPORT')->exists())->toBeFalse();
});

test('it imports personnel with proper roles, labs, deduplication and fallback emails', function () {
    $mockReader = Mockery::mock(MscAccReader::class);
    $mockReader->shouldReceive('getPersonnel')
        ->andReturn(collect([
            (object) [
                'id_user' => 101,
                'name_user' => 'อาจารย์ สมศรี มีสุข',
                'username' => 'somsri.me',
                'email' => 'somsri.me@up.ac.th',
                'pos_name' => 'อาจารย์',
                'div_name' => 'ชีวเคมี',
            ],
            (object) [
                'id_user' => 102,
                'name_user' => 'นายวิทยาการ ทดลอง',
                'username' => 'witthaya.ex',
                'email' => 'witthaya.ex@up.ac.th',
                'pos_name' => 'นักวิทยาศาสตร์',
                'div_name' => 'จุลชีววิทยา',
            ],
            (object) [
                'id_user' => 103,
                'name_user' => 'สมเกียรติ สังกัดธุรการ',
                'username' => 'somkiat.dupe',
                'email' => null,
                'pos_name' => 'เจ้าหน้าที่บริหารงานทั่วไป',
                'div_name' => 'สำนักงานธุรการ',
            ],
            (object) [
                'id_user' => 104,
                'name_user' => 'ดร.สมเกียรติ สรีรวิทยา',
                'username' => 'somkiat.dupe',
                'email' => 'somkiat.physio@up.ac.th',
                'pos_name' => 'อาจารย์',
                'div_name' => 'สรีรวิทยา',
            ],
            (object) [
                'id_user' => 105,
                'name_user' => 'นางสมพร ไร้อีเมล',
                'username' => 'somporn.noemail',
                'email' => '-',
                'pos_name' => 'เจ้าหน้าที่บริหารงานทั่วไป',
                'div_name' => 'สำนักงานธุรการ',
            ],
        ]));
    $this->instance(MscAccReader::class, $mockReader);

    $this->artisan('users:import-msc-acc')
        ->assertExitCode(0)
        ->expectsOutputToContain('เพิ่มผู้ใช้งานใหม่: 4 คน')
        ->expectsOutputToContain('นำเข้าข้อมูลสำเร็จเรียบร้อยแล้ว');

    $lecturer = User::where('username', 'somsri.me')->firstOrFail();
    expect($lecturer->full_name)->toBe('อาจารย์ สมศรี มีสุข');
    expect($lecturer->lab->code)->toBe('003');
    expect($lecturer->hasRole('STAFF'))->toBeTrue();
    expect($lecturer->hasRole('ADVISOR'))->toBeTrue();
    expect($lecturer->hasRole('SCIENTIST'))->toBeFalse();

    $scientist = User::where('username', 'witthaya.ex')->firstOrFail();
    expect($scientist->full_name)->toBe('นายวิทยาการ ทดลอง');
    expect($scientist->lab->code)->toBe('002');
    expect($scientist->hasRole('SCIENTIST'))->toBeTrue();
    expect($scientist->hasRole('ADVISOR'))->toBeFalse();

    $dedupedUser = User::where('username', 'somkiat.dupe')->firstOrFail();
    expect($dedupedUser->full_name)->toBe('ดร.สมเกียรติ สรีรวิทยา');
    expect($dedupedUser->email)->toBe('somkiat.physio@up.ac.th');
    expect($dedupedUser->lab->code)->toBe('005');
    expect($dedupedUser->hasRole('ADVISOR'))->toBeTrue();

    $noEmailUser = User::where('username', 'somporn.noemail')->firstOrFail();
    expect($noEmailUser->email)->toBe('somporn.noemail@up.ac.th');

    expect(AuditLog::where('action', 'BULK_USER_IMPORT')->exists())->toBeTrue();
});

test('it updates existing users without overwriting their existing roles', function () {
    $adminRole = Role::where('code', 'ADMIN')->firstOrFail();
    $existingUser = User::factory()->create([
        'username' => 'existing.admin',
        'full_name' => 'Existing Admin',
        'email' => 'existing.admin@up.ac.th',
        'pos_name' => null,
        'div_name' => null,
        'lab_id' => null,
    ]);
    $existingUser->roles()->attach($adminRole->id);

    $mockReader = Mockery::mock(MscAccReader::class);
    $mockReader->shouldReceive('getPersonnel')
        ->andReturn(collect([
            (object) [
                'id_user' => 200,
                'name_user' => 'Existing Admin In MSC',
                'username' => 'existing.admin',
                'email' => 'existing.admin@up.ac.th',
                'pos_name' => 'อาจารย์',
                'div_name' => 'ชีวเคมี',
            ],
        ]));
    $this->instance(MscAccReader::class, $mockReader);

    $this->artisan('users:import-msc-acc')
        ->assertExitCode(0)
        ->expectsOutputToContain('อัปเดตข้อมูลผู้ใช้เดิม: 1 คน');

    $refreshed = $existingUser->fresh();
    expect($refreshed->pos_name)->toBe('อาจารย์');
    expect($refreshed->div_name)->toBe('ชีวเคมี');
    expect($refreshed->lab->code)->toBe('003');
    expect($refreshed->hasRole('ADMIN'))->toBeTrue();
});
