<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function createMockMscAccTables(): void
{
    Schema::dropIfExists('user');
    Schema::dropIfExists('position');
    Schema::dropIfExists('division');

    Schema::create('position', function ($table) {
        $table->increments('id_pos');
        $table->string('pos_name');
    });

    Schema::create('division', function ($table) {
        $table->increments('id_div');
        $table->string('div_name');
    });

    Schema::create('user', function ($table) {
        $table->increments('id_user');
        $table->string('name_user');
        $table->string('username');
        $table->string('email')->nullable();
        $table->unsignedInteger('id_pos')->nullable();
        $table->unsignedInteger('id_div')->nullable();
    });
}

function dropMockMscAccTables(): void
{
    Schema::dropIfExists('user');
    Schema::dropIfExists('position');
    Schema::dropIfExists('division');
}

afterEach(function () {
    dropMockMscAccTables();
});

test('it handles database connection failure cleanly', function () {
    $this->artisan('users:import-msc-acc', ['--database' => 'non_existent_database_99999'])
        ->assertExitCode(1)
        ->expectsOutputToContain('ไม่สามารถเชื่อมต่อฐานข้อมูล');
});

test('it previews imports in dry-run mode without creating or updating users', function () {
    createMockMscAccTables();

    DB::table('division')->insert(['id_div' => 1, 'div_name' => 'ชีวเคมี']);
    DB::table('position')->insert(['id_pos' => 1, 'pos_name' => 'อาจารย์']);
    DB::table('user')->insert([
        'id_user' => 10,
        'name_user' => 'ผศ.ดร.ทดสอบ ชีวเคมี',
        'username' => 'test.biochem',
        'email' => 'test.biochem@up.ac.th',
        'id_pos' => 1,
        'id_div' => 1,
    ]);

    $dbName = (string) DB::getDatabaseName();

    $this->artisan('users:import-msc-acc', ['--database' => $dbName, '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('โหมด --dry-run')
        ->expectsOutputToContain('เพิ่มผู้ใช้งานใหม่: 1 คน');

    expect(User::where('username', 'test.biochem')->exists())->toBeFalse();
    expect(AuditLog::where('action', 'BULK_USER_IMPORT')->exists())->toBeFalse();
});

test('it imports personnel with proper roles, labs, deduplication and fallback emails', function () {
    createMockMscAccTables();

    DB::table('division')->insert([
        ['id_div' => 1, 'div_name' => 'ชีวเคมี'],
        ['id_div' => 2, 'div_name' => 'จุลชีววิทยา'],
        ['id_div' => 3, 'div_name' => 'สำนักงานธุรการ'],
        ['id_div' => 4, 'div_name' => 'สรีรวิทยา'],
    ]);

    DB::table('position')->insert([
        ['id_pos' => 1, 'pos_name' => 'อาจารย์'],
        ['id_pos' => 2, 'pos_name' => 'นักวิทยาศาสตร์'],
        ['id_pos' => 3, 'pos_name' => 'เจ้าหน้าที่บริหารงานทั่วไป'],
    ]);

    DB::table('user')->insert([
        [
            'id_user' => 101,
            'name_user' => 'อาจารย์ สมศรี มีสุข',
            'username' => 'somsri.me',
            'email' => 'somsri.me@up.ac.th',
            'id_pos' => 1,
            'id_div' => 1,
        ],
        [
            'id_user' => 102,
            'name_user' => 'นายวิทยาการ ทดลอง',
            'username' => 'witthaya.ex',
            'email' => 'witthaya.ex@up.ac.th',
            'id_pos' => 2,
            'id_div' => 2,
        ],
        [
            'id_user' => 103,
            'name_user' => 'สมเกียรติ สังกัดธุรการ',
            'username' => 'somkiat.dupe',
            'email' => null,
            'id_pos' => 3,
            'id_div' => 3,
        ],
        [
            'id_user' => 104,
            'name_user' => 'ดร.สมเกียรติ สรีรวิทยา',
            'username' => 'somkiat.dupe',
            'email' => 'somkiat.physio@up.ac.th',
            'id_pos' => 1,
            'id_div' => 4,
        ],
        [
            'id_user' => 105,
            'name_user' => 'นางสมพร ไร้อีเมล',
            'username' => 'somporn.noemail',
            'email' => '-',
            'id_pos' => 3,
            'id_div' => 3,
        ],
    ]);

    $dbName = (string) DB::getDatabaseName();

    $this->artisan('users:import-msc-acc', ['--database' => $dbName])
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
    createMockMscAccTables();

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

    DB::table('division')->insert(['id_div' => 1, 'div_name' => 'ชีวเคมี']);
    DB::table('position')->insert(['id_pos' => 1, 'pos_name' => 'อาจารย์']);
    DB::table('user')->insert([
        'id_user' => 200,
        'name_user' => 'Existing Admin In MSC',
        'username' => 'existing.admin',
        'email' => 'existing.admin@up.ac.th',
        'id_pos' => 1,
        'id_div' => 1,
    ]);

    $dbName = (string) DB::getDatabaseName();

    $this->artisan('users:import-msc-acc', ['--database' => $dbName])
        ->assertExitCode(0)
        ->expectsOutputToContain('อัปเดตข้อมูลผู้ใช้เดิม: 1 คน');

    $refreshed = $existingUser->fresh();
    expect($refreshed->pos_name)->toBe('อาจารย์');
    expect($refreshed->div_name)->toBe('ชีวเคมี');
    expect($refreshed->lab->code)->toBe('003');
    expect($refreshed->hasRole('ADMIN'))->toBeTrue();
});
