<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\BorrowRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $classCodeId;
    private int $itemId;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('API_ENCRYPTION_KEY')) {
            config(['app.api_encryption_key' => 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=']);
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }

        $this->student = $this->user('student');

        $this->classCodeId = DB::table('class_codes')->insertGetId([
            'code' => 'CK-101', 'course_code' => 'CK101', 'course_name' => 'Kitchen Basics',
            'section' => 'A', 'academic_year' => '2026-2027', 'semester' => 'First',
            'max_enrollment' => 40, 'created_at' => now(), 'updated_at' => now(),
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

    private function request(string $status, array $attrs = [], int $quantity = 1): BorrowRequest
    {
        $req = BorrowRequest::create(array_merge([
            'student_id' => $this->student->id,
            'class_code_id' => $this->classCodeId,
            'purpose' => 'Practical exam',
            'borrow_date' => Carbon::tomorrow(),
            'return_date' => Carbon::now()->addDays(3),
            'status' => $status,
            'created_by' => $this->student->id,
        ], $attrs));

        DB::table('borrow_request_items')->insert([
            'borrow_request_id' => $req->id, 'item_id' => $this->itemId,
            'name' => 'Chef Knife', 'quantity' => $quantity, 'category' => 'Knives',
        ]);

        return $req;
    }

    // ── Access ──────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_blocked(): void
    {
        $this->getJson('/api/dashboard/operations')->assertStatus(401);
    }

    public function test_students_and_instructors_are_forbidden(): void
    {
        $this->getJson('/api/dashboard/operations', $this->auth($this->student))->assertStatus(403);
        $this->getJson('/api/dashboard/operations', $this->auth($this->user('instructor')))->assertStatus(403);
    }

    // ── Live counts ─────────────────────────────────────────────────────────

    public function test_counts_reflect_current_state_regardless_of_creation_date(): void
    {
        $this->request('pending_instructor');
        $this->request('pending_appeal');
        $this->request('approved_instructor');
        $this->request('ready_for_pickup');
        // Created two months ago and still out — must be counted.
        $old = $this->request('borrowed', [], 2);
        $old->created_at = Carbon::now()->subMonths(2);
        $old->save();
        $this->request('borrowed', ['return_date' => Carbon::now()->subDay()], 3);       // overdue
        $this->request('pending_return', ['return_date' => Carbon::now()->subDay()], 4); // overdue
        $this->request('returned');

        $response = $this->getJson('/api/dashboard/operations', $this->auth($this->user('admin')));
        $response->assertStatus(200);
        $data = $this->decrypt($response);

        $this->assertSame(2, $data['pipeline']['underReview']);
        $this->assertSame(1, $data['pipeline']['appeals']);
        $this->assertSame(1, $data['pipeline']['approved']);
        $this->assertSame(1, $data['pipeline']['readyForPickup']);
        $this->assertSame(2, $data['pipeline']['borrowed']);
        $this->assertSame(1, $data['pipeline']['pendingReturn']);
        $this->assertSame(2, $data['pipeline']['overdue']);
        $this->assertSame(9, $data['itemsOut']);

        $this->assertCount(2, $data['queues']['underReview']);
        $this->assertCount(3, $data['queues']['borrowed']);
        // Earliest due first, so overdue requests lead the borrowed queue.
        $this->assertTrue($data['queues']['borrowed'][0]['isOverdue']);
    }

    public function test_requests_whose_booking_day_passed_are_expired_not_counted(): void
    {
        $this->request('approved_instructor', ['borrow_date' => Carbon::now()->subDays(2)]);

        $data = $this->decrypt(
            $this->getJson('/api/dashboard/operations', $this->auth($this->user('custodian')))
        );

        $this->assertSame(0, $data['pipeline']['approved']);
    }

    public function test_analytics_overdue_count_uses_the_same_rule(): void
    {
        $this->request('borrowed', ['return_date' => Carbon::now()->subDay()]);
        $this->request('pending_return', ['return_date' => Carbon::now()->subDay()]);

        $data = $this->decrypt(
            $this->getJson('/api/reports/analytics?period=month', $this->auth($this->user('admin')))
        );

        $this->assertSame(2, $data['borrowRequests']['overdueCount']);
    }

    // ── Action authorization ────────────────────────────────────────────────

    public function test_only_the_responsible_instructor_or_superadmin_can_approve(): void
    {
        $instructor = $this->user('instructor');
        $req = $this->request('pending_instructor', ['instructor_id' => $instructor->id, 'borrow_date' => null]);

        foreach (['student' => $this->student, 'custodian' => $this->user('custodian'),
                  'admin' => $this->user('admin'), 'other instructor' => $this->user('instructor')] as $who => $user) {
            $this->postJson("/api/borrow-requests/{$req->id}/approve", $this->encrypt([]), $this->auth($user))
                ->assertStatus(403, "{$who} should not be able to approve");
        }
        $this->assertSame('pending_instructor', $req->fresh()->status);

        $this->postJson("/api/borrow-requests/{$req->id}/approve", $this->encrypt([]), $this->auth($instructor))
            ->assertStatus(200);
        $this->assertSame('approved_instructor', $req->fresh()->status);
    }

    public function test_class_instructor_can_reject(): void
    {
        $instructor = $this->user('instructor');
        DB::table('class_code_instructor')->insert(['class_code_id' => $this->classCodeId, 'user_id' => $instructor->id]);
        $req = $this->request('pending_instructor');

        $this->postJson("/api/borrow-requests/{$req->id}/reject", $this->encrypt(['reason' => 'Out of scope']), $this->auth($instructor))
            ->assertStatus(200);
        $this->assertSame('rejected', $req->fresh()->status);
    }

    public function test_storeroom_actions_require_staff(): void
    {
        $req = $this->request('approved_instructor');

        $this->postJson("/api/borrow-requests/{$req->id}/release", $this->encrypt([]), $this->auth($this->student))->assertStatus(403);
        $this->postJson("/api/borrow-requests/{$req->id}/release", $this->encrypt([]), $this->auth($this->user('instructor')))->assertStatus(403);
        $this->assertSame('approved_instructor', $req->fresh()->status);

        $this->postJson("/api/borrow-requests/{$req->id}/release", $this->encrypt([]), $this->auth($this->user('admin')))->assertStatus(200);
        $this->assertSame('ready_for_pickup', $req->fresh()->status);
    }

    public function test_students_cannot_read_or_cancel_another_students_request(): void
    {
        $req = $this->request('pending_instructor');
        $other = $this->user('student');

        $this->getJson("/api/borrow-requests/{$req->id}", $this->auth($other))->assertStatus(403);
        $this->deleteJson("/api/borrow-requests/{$req->id}", $this->encrypt([]), $this->auth($other))->assertStatus(403);
        $this->assertSame('pending_instructor', $req->fresh()->status);

        $this->getJson("/api/borrow-requests/{$req->id}", $this->auth($this->student))->assertStatus(200);
        $response = $this->deleteJson("/api/borrow-requests/{$req->id}", $this->encrypt([]), $this->auth($this->student));
        $response->assertStatus(200);
        $this->assertSame('cancelled', $req->fresh()->status);
    }

    public function test_cancellation_records_when_and_who(): void
    {
        $req = $this->request('approved_instructor');
        $superadmin = $this->user('superadmin');

        Carbon::setTestNow('2026-10-03 09:30:00');
        $response = $this->deleteJson("/api/borrow-requests/{$req->id}", $this->encrypt([]), $this->auth($superadmin));
        Carbon::setTestNow();

        $response->assertStatus(200);
        $fresh = $req->fresh();
        $this->assertSame('2026-10-03 09:30:00', $fresh->cancelled_at->format('Y-m-d H:i:s'));
        $this->assertSame((string) $superadmin->id, (string) $fresh->cancelled_by);

        $data = $this->decrypt($response);
        $this->assertNotNull($data['cancelledAt']);
        $this->assertSame((string) $superadmin->id, $data['cancelledBy']['id']);

        // Never-cancelled requests expose nulls rather than omitting the fields.
        $other = $this->decrypt($this->getJson("/api/borrow-requests/{$this->request('pending_instructor')->id}", $this->auth($this->student)));
        $this->assertNull($other['cancelledAt']);
        $this->assertNull($other['cancelledBy']);
    }
}
