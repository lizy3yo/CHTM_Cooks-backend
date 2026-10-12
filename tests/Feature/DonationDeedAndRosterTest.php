<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\Donation;
use App\Models\DonationDeed;
use App\Models\InventoryItem;
use App\Models\User;
use Database\Seeders\RealDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Deeds of donation (one deed, many items), who may record them, and the
 * rules that come with the registrar's roster: a starting password must be
 * replaced, and only officially enrolled students borrow.
 */
class DonationDeedAndRosterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('API_ENCRYPTION_KEY')) {
            config(['app.api_encryption_key' => 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=']);
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }
    }

    private function user(string $role, array $extra = []): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true] + $extra);
    }

    private function auth(User $user): array
    {
        $secret = env('JWT_SECRET', 'test-secret-key-that-is-long-enough-for-jwt');
        return ['Authorization' => 'Bearer ' . JwtHelper::sign(['userId' => $user->id], $secret)];
    }

    private function decrypt($response): array
    {
        $data = $response->json();
        $key = base64_decode(env('API_ENCRYPTION_KEY', 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE='));
        $plain = openssl_decrypt(
            base64_decode($data['payload']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
            base64_decode($data['iv']), base64_decode($data['tag'])
        );
        return json_decode($plain, true);
    }

    /** Mirrors the frontend: every mutating request body is AES-GCM encrypted. */
    private function send(string $method, string $url, array $body = [], array $headers = [])
    {
        $key = base64_decode(env('API_ENCRYPTION_KEY', 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE='));
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode((object) $body), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        return $this->json($method, $url, [
            'payload' => base64_encode($cipher),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'timestamp' => time(),
        ], $headers);
    }

    private function deedPayload(array $overrides = []): array
    {
        return $overrides + [
            'representativeName' => 'Juan P. Santos',
            'program' => 'BSHM',
            'dateDonated' => now()->subDay()->toDateString(),
            'items' => [
                ['itemName' => 'Frying Pan', 'quantity' => 2, 'unitCost' => 600],
                ['itemName' => 'Wok', 'quantity' => 1, 'unitCost' => 300],
            ],
        ];
    }

    public function test_a_deed_records_every_item_and_adds_them_to_stock(): void
    {
        $custodian = $this->user('custodian');
        $existing = InventoryItem::create([
            'name' => 'Wok', 'category' => 'Cookware', 'tools_or_equipment' => 'Equipment',
            'quantity' => 4, 'eom_count' => 4, 'created_by' => $custodian->id,
        ]);

        $response = $this->send('POST', '/api/donations/deeds', $this->deedPayload(), $this->auth($custodian));
        $response->assertStatus(201);
        $body = $this->decrypt($response);

        $this->assertMatchesRegularExpression('/^DEED-\d{4}-0001$/', $body['deed']['reference']);
        $this->assertCount(2, $body['donations']);
        $this->assertSame(1, DonationDeed::count());

        // Name match adds to the existing item; a new name creates one.
        $existing->refresh();
        $this->assertSame(1, $existing->donations);
        $this->assertSame(4, $existing->eom_count, 'a donation shows as Over, it does not raise the starting count');
        $pan = InventoryItem::where('name', 'Frying Pan')->first();
        $this->assertSame(2, $pan->donations);
        $this->assertSame('Tools', $pan->category, 'a new item is filed under its type');

        $line = Donation::where('item_name', 'Frying Pan')->first();
        $this->assertSame('pending', $line->deed_status);
        $this->assertEquals(600, $line->unit_cost);
    }

    public function test_a_receipt_only_deed_records_the_amount_without_touching_stock(): void
    {
        $custodian = $this->user('custodian');
        $itemsBefore = InventoryItem::count();

        $response = $this->send('POST', '/api/donations/deeds', $this->deedPayload([
            'items' => [], 'orNumber' => '22892', 'declaredTotal' => 21010,
        ]), $this->auth($custodian));

        $response->assertStatus(201);
        $line = $this->decrypt($response)['donations'][0];
        $this->assertTrue($line['receiptOnly']);
        $this->assertEquals(21010, $line['totalValue']);
        $this->assertSame($itemsBefore, InventoryItem::count());

        // No items and no receipt is not a donation.
        $this->send('POST', '/api/donations/deeds', $this->deedPayload(['items' => []]), $this->auth($custodian))
            ->assertStatus(400);
    }

    public function test_signed_files_apply_to_every_item_on_the_deed(): void
    {
        $custodian = $this->user('custodian');
        $created = $this->decrypt($this->send('POST', '/api/donations/deeds', $this->deedPayload(), $this->auth($custodian)));
        $firstId = $created['donations'][0]['id'];

        $this->send('POST', "/api/donations/{$firstId}/deed", [
            'fileUrls' => ['https://example.com/deed-1.pdf', 'https://example.com/deed-2.pdf'],
        ], $this->auth($custodian))->assertStatus(200);

        $this->assertSame(2, Donation::where('deed_status', 'signed')->count());
        $this->assertCount(2, DonationDeed::first()->files);
    }

    public function test_only_managing_roles_may_change_donations(): void
    {
        $custodian = $this->user('custodian');
        $created = $this->decrypt($this->send('POST', '/api/donations/deeds', $this->deedPayload(), $this->auth($custodian)));
        $id = $created['donations'][0]['id'];

        foreach (['student', 'admin'] as $role) {
            $headers = $this->auth($this->user($role));
            $this->send('POST', '/api/donations/deeds', $this->deedPayload(), $headers)->assertStatus(403);
            $this->send('PUT', "/api/donations/{$id}", ['quantity' => 99], $headers)->assertStatus(403);
            $this->send('PATCH', "/api/donations/{$id}", ['quantityToAdd' => 5], $headers)->assertStatus(403);
            $this->send('DELETE', "/api/donations/{$id}", [], $headers)->assertStatus(403);
        }
        $this->assertSame(2, Donation::count());
    }

    public function test_public_registration_cannot_create_staff(): void
    {
        foreach (['superadmin', 'custodian', 'instructor', 'admin'] as $role) {
            $this->send('POST', '/api/auth/register', [
                'email' => "{$role}@example.com", 'password' => 'Secret#123',
                'firstName' => 'A', 'lastName' => 'B', 'role' => $role,
            ])->assertStatus(400);
        }
        $this->assertSame(0, User::count());
    }

    public function test_a_starting_password_must_be_changed_before_anything_else(): void
    {
        $student = $this->user('student', ['password' => Hash::make('Starting#123'), 'must_change_password' => true]);
        $headers = $this->auth($student);

        $this->getJson('/api/class-codes/my-classes', $headers)->assertStatus(403);
        $this->getJson('/api/auth/me', $headers)->assertStatus(200);

        // The replacement has to be a different password.
        $this->send('PATCH', '/api/auth/profile/password', [
            'currentPassword' => 'Starting#123', 'newPassword' => 'Starting#123',
        ], $headers)->assertStatus(400);

        $this->send('PATCH', '/api/auth/profile/password', [
            'currentPassword' => 'Starting#123', 'newPassword' => 'MyOwn#12345',
        ], $headers)->assertStatus(200);

        $this->assertFalse($student->fresh()->must_change_password);
        $this->getJson('/api/class-codes/my-classes', $headers)->assertStatus(200);
    }

    public function test_only_officially_enrolled_roster_students_can_borrow(): void
    {
        $student = $this->user('student', ['enrollment_status' => 'For Enlistment']);
        $classId = DB::table('class_codes')->insertGetId([
            'code' => 'HPC101-1A', 'course_code' => 'HPC 101', 'course_name' => 'Kitchen Essentials (Lab)',
            'section' => '1A', 'academic_year' => '2026-2027', 'semester' => 'Second',
            'max_enrollment' => 45, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('inventory_items')->insertGetId([
            'name' => 'Chef Knife', 'category' => 'Knives', 'tools_or_equipment' => 'Tools',
            'quantity' => 10, 'created_by' => $student->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->send('POST', '/api/borrow-requests', [
            'classCodeId' => $classId, 'purpose' => 'Knife skills practical',
            'borrowDate' => now()->addDay()->setTime(8, 0)->toIso8601String(),
            'returnDate' => now()->addDays(2)->setTime(17, 0)->toIso8601String(),
            'items' => [['itemId' => $itemId, 'quantity' => 1]],
        ], $this->auth($student));

        $response->assertStatus(403);
        $this->assertSame('NOT_ENROLLED', $this->decrypt($response)['code']);
    }

    public function test_the_real_data_seeder_loads_the_roster_and_can_be_rerun(): void
    {
        $this->user('instructor');
        $this->user('superadmin');

        $this->seed(RealDataSeeder::class);

        $students = User::where('role', 'student')->whereNotNull('student_number');
        $this->assertSame(845, $students->count());
        $this->assertSame(845, (clone $students)->where('must_change_password', true)->count());
        $this->assertSame(6, (clone $students)->where('is_active', false)->count());
        $this->assertSame(9, DB::table('courses')->count());
        $this->assertSame(53, DB::table('class_codes')->count());
        $this->assertSame(10, DonationDeed::count());
        $this->assertSame(20, Donation::count());

        // The inventory export, filed under Tools or Equipment and nothing else.
        $this->assertGreaterThanOrEqual(330, InventoryItem::count());
        $this->assertSame(['Equipment', 'Tools'], InventoryItem::distinct()->orderBy('category')->pluck('category')->all());
        $this->assertSame(0, InventoryItem::whereColumn('category', '!=', 'tools_or_equipment')->count());
        $plate = InventoryItem::where('name', 'DINNER PLATE')->first();
        $this->assertSame($plate->quantity + $plate->donations, $plate->eom_count, 'loaded items start with no variance');
        $this->assertTrue($plate->is_required);
        $items = InventoryItem::count();

        $first = User::where('email', '202610221@gordoncollege.edu.ph')->first();
        $this->assertSame('Abad', $first->last_name);
        $this->assertSame('1st Year', $first->year_level);

        // Students who are not officially enrolled are in no class.
        $this->assertSame(0, DB::table('class_code_student')
            ->join('users', 'users.id', '=', 'class_code_student.user_id')
            ->where('users.enrollment_status', '!=', 'Officially Enrolled')->count());

        // A password a student has already chosen survives a rerun.
        $first->update(['password' => Hash::make('MyOwn#12345'), 'must_change_password' => false]);
        $stock = InventoryItem::sum('donations');

        $this->seed(RealDataSeeder::class);

        $this->assertSame(845, User::where('role', 'student')->whereNotNull('student_number')->count());
        $this->assertSame(20, Donation::count());
        $this->assertSame($items, InventoryItem::count());
        $this->assertEquals($stock, InventoryItem::sum('donations'));
        $this->assertTrue(Hash::check('MyOwn#12345', $first->fresh()->password));
        $this->assertFalse($first->fresh()->must_change_password);
    }
}
