<?php

use App\Domain\Attachments\Contracts\VirusScanner;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function fakeScanner(bool $clean = true): void
{
    $fake = new class ($clean) implements VirusScanner {
        public function __construct(private readonly bool $clean)
        {
        }

        public function isClean(string $absolutePath): bool
        {
            return $this->clean;
        }
    };

    app()->instance(VirusScanner::class, $fake);
}

function labManager(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'LAB_MANAGER')->firstOrFail());

    return $user;
}

function scientist(): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::where('code', 'SCIENTIST')->firstOrFail());

    return $user;
}

function itemForAttachments(): Item
{
    return Item::create([
        'item_code' => 'CHM-'.fake()->unique()->numerify('#####'),
        'category_id' => ItemCategory::where('code', 'CHEMICAL')->firstOrFail()->id,
        'name_th' => 'โซเดียมไฮดรอกไซด์',
        'base_unit_id' => Unit::where('code', 'g')->firstOrFail()->id,
        'is_active' => true,
    ]);
}

beforeEach(function () {
    Storage::fake('attachments');
    fakeScanner(clean: true);
});

test('LAB_MANAGER can upload an SDS file and it is versioned from 1', function () {
    $manager = labManager();
    $item = itemForAttachments();

    $response = $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('sds-v1.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionDoesntHaveErrors()->assertRedirect();

    $attachment = Attachment::where('owner_type', 'Item')->where('owner_id', $item->id)->first();
    expect($attachment)->not->toBeNull();
    expect($attachment->version)->toBe(1);
    expect($attachment->doc_type)->toBe('SDS');
    expect($attachment->sha256)->toHaveLength(64);
    expect($attachment->stored_name)->not->toBe('sds-v1.pdf'); // SEC-FU-09: never the client filename

    Storage::disk('attachments')->assertExists($attachment->stored_name);
});

test('uploading a second SDS for the same item increments the version', function () {
    $manager = labManager();
    $item = itemForAttachments();

    $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('v1.pdf', 50, 'application/pdf'),
    ]);
    $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('v2.pdf', 50, 'application/pdf'),
    ]);

    $versions = Attachment::where('owner_id', $item->id)->orderBy('version')->pluck('version')->all();
    expect($versions)->toBe([1, 2]);
});

test('a disallowed file extension is rejected', function () {
    $manager = labManager();
    $item = itemForAttachments();

    $response = $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('malware.exe', 10),
    ]);

    $response->assertSessionHasErrors('file');
    expect(Attachment::count())->toBe(0);
});

test('a file disguised with a fake extension is still rejected by real MIME sniffing', function () {
    $manager = labManager();
    $item = itemForAttachments();

    // UploadedFile::fake() reports MIME from the *filename*, not real content — no good for
    // this test. A real UploadedFile (test mode, wrapping a genuine temp file) goes through
    // Symfony's actual finfo-based guesser, same as a live request (SEC-FU-02, ST-05).
    $tmpPath = tempnam(sys_get_temp_dir(), 'upload');
    file_put_contents($tmpPath, '<?php system($_GET["c"]); ?>');
    $file = new \Illuminate\Http\UploadedFile($tmpPath, 'shell.pdf', null, null, true);

    $response = $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => $file,
    ]);

    $response->assertSessionHasErrors('file');
    expect(Attachment::count())->toBe(0);
});

test('an oversized file is rejected', function () {
    $manager = labManager();
    $item = itemForAttachments();

    $response = $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('file');
});

test('an infected file is rejected and nothing is stored', function () {
    fakeScanner(clean: false);
    $manager = labManager();
    $item = itemForAttachments();

    $response = $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('infected.pdf', 10, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('file');
    expect(Attachment::count())->toBe(0);
});

test('SCIENTIST (item.view only) cannot upload attachments', function () {
    $user = scientist();
    $item = itemForAttachments();

    $this->actingAs($user)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
    ])->assertStatus(403);
});

test('SCIENTIST (item.view) can download, but a user with no item permission cannot', function () {
    $manager = labManager();
    $item = itemForAttachments();
    $this->actingAs($manager)->post(route('items.attachments.store', $item), [
        'doc_type' => 'SDS',
        'file' => UploadedFile::fake()->create('sds.pdf', 10, 'application/pdf'),
    ]);
    $attachment = Attachment::where('owner_id', $item->id)->firstOrFail();

    $viewer = scientist();
    $this->actingAs($viewer)->get(route('attachments.download', $attachment))->assertOk();

    $noPermission = User::factory()->create();
    $noPermission->roles()->attach(Role::where('code', 'ADMIN')->firstOrFail()); // ADMIN has no item.view
    $this->actingAs($noPermission)->get(route('attachments.download', $attachment))->assertStatus(403);
});
