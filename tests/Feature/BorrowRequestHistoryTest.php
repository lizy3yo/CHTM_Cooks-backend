<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\BorrowRequest;
use App\Models\Notification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Each lifecycle step is recorded against the person who performed it, and
 * admins and superadmins are notified of every step.
 */
class BorrowRequestHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $classCodeId;
    private int $itemId;
    private User $student;
    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('API_ENCRYPTION_KEY')) {
            config(['app.api_encryption_key' => 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=']);
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }

        $this->student = $this->user('student');
        $this->instructor = $this->user('instructor');

        $this->classCodeId = DB::table('class_codes')->insertGetId([
            'code' => 'CK-101', 'course_code' => 'CK101', 'course_name' => 'Kitchen Basics',
            'section' => 'A', 'academic_year' => '2026-2027', 'semester' => 'First',
            'max_enrollment' => 40, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('class_code_instructor')->insert([
            'class_code_id' => $this->classCodeId, 'user_id' => $this->instructor->id,
        ]);

        $this->itemId = DB::table('inventory_items')->insertGetId([
            'name' => 'Chef Knife', 'category' => 'Knives', 'tools_or_equipment' => 'Tools',
            'quantity' => 10, 'created_by' => $this->student->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true]);
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
    private function encrypt(array $body): array
    {
        $key = base64_decode(env('API_ENCRYPTION_KEY', 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE='));
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt(json_encode((object) $body), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        return [
            'payload' => base64_encode($cipher),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'timestamp' => time(),
        ];
    }

    private function request(string $status): BorrowRequest
    {
        $req = BorrowRequest::create([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
            'class_code_id' => $this->classCodeId,
            'purpose' => 'Practical exam',
            'borrow_date' => Carbon::tomorrow(),
            'return_date' => Carbon::now()->addDays(3),
            'status' => $status,
            'created_by' => $this->student->id,
        ]);

        DB::table('borrow_request_items')->insert([
            'borrow_request_id' => $req->id, 'item_id' => $this->itemId,
            'name' => 'Chef Knife', 'quantity' => 2, 'category' => 'Knives',
        ]);

        return $req;
    }

    public function test_each_step_records_the_person_who_performed_it(): void
    {
        $releaser = $this->user('custodian');
        $handler = $this->user('admin');
        $inspector = $this->user('custodian');
        $req = $this->request('pending_instructor');

        $this->postJson("/api/borrow-requests/{$req->id}/approve", $this->encrypt([]), $this->auth($this->instructor))->assertStatus(200);
        $this->postJson("/api/borrow-requests/{$req->id}/release", $this->encrypt([]), $this->auth($releaser))->assertStatus(200);
        $this->postJson("/api/borrow-requests/{$req->id}/pickup", $this->encrypt([]), $this->auth($handler))->assertStatus(200);
        $this->postJson("/api/borrow-requests/{$req->id}/return", $this->encrypt([]), $this->auth($this->student))->assertStatus(200);
        $this->postJson("/api/borrow-requests/{$req->id}/inspect-items", $this->encrypt([
            'items' => [['itemId' => $this->itemId, 'status' => 'good']],
        ]), $this->auth($inspector))->assertStatus(200);

        $response = $this->getJson("/api/borrow-requests/{$req->id}", $this->auth($handler));
        $response->assertStatus(200);
        $events = collect($this->decrypt($response)['events']);

        $this->assertSame(
            ['approved', 'ready_for_pickup', 'picked_up', 'return_initiated', 'inspected'],
            $events->pluck('event')->all()
        );

        $actorOf = fn (string $event) => $events->firstWhere('event', $event)['actorId'];
        $this->assertSame((string) $this->instructor->id, $actorOf('approved'));
        $this->assertSame((string) $releaser->id, $actorOf('ready_for_pickup'));
        $this->assertSame((string) $handler->id, $actorOf('picked_up'));
        $this->assertSame((string) $this->student->id, $actorOf('return_initiated'));
        $this->assertSame((string) $inspector->id, $actorOf('inspected'));

        $inspected = $events->firstWhere('event', 'inspected');
        $this->assertSame('returned', $inspected['outcome']);
        $this->assertSame('custodian', $inspected['actorRole']);
        $this->assertNotEmpty($inspected['actorName']);
    }

    public function test_admin_and_superadmin_are_notified_of_every_step(): void
    {
        $admin = $this->user('admin');
        $superadmin = $this->user('superadmin');
        $custodian = $this->user('custodian');
        $req = $this->request('pending_instructor');

        $this->postJson("/api/borrow-requests/{$req->id}/approve", $this->encrypt([]), $this->auth($this->instructor))->assertStatus(200);
        // "Ready for pickup" used to reach the student only.
        $this->postJson("/api/borrow-requests/{$req->id}/release", $this->encrypt([]), $this->auth($custodian))->assertStatus(200);

        foreach ([$admin, $superadmin] as $overseer) {
            $rows = Notification::where('user_id', $overseer->id)->orderBy('id')->get();

            $this->assertSame(
                ['borrow_request_approved', 'borrow_request_ready_for_pickup'],
                $rows->pluck('type')->all()
            );
            $this->assertSame($overseer->role, $rows[0]->audience_role);
            $this->assertStringStartsWith("/{$overseer->role}/requests?requestId=", $rows[0]->link);
            $this->assertSame($this->instructor->id, $rows[0]->metadata['actorId']);
            $this->assertSame($custodian->id, $rows[1]->metadata['actorId']);
        }
    }

    public function test_an_admin_who_releases_a_request_is_linked_to_the_admin_portal(): void
    {
        $admin = $this->user('admin');
        $req = $this->request('approved_instructor');

        $this->postJson("/api/borrow-requests/{$req->id}/release", $this->encrypt([]), $this->auth($admin))->assertStatus(200);
        $this->postJson("/api/borrow-requests/{$req->id}/pickup", $this->encrypt([]), $this->auth($admin))->assertStatus(200);

        $rows = Notification::where('user_id', $admin->id)->get();

        // One row per step, not a second copy addressed to them as a custodian.
        $this->assertCount(2, $rows);
        $this->assertSame(['admin'], $rows->pluck('audience_role')->unique()->values()->all());
    }
}
