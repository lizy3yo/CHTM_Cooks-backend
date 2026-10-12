<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\BorrowRequest;
use App\Models\User;
use App\Services\ChangeSignatures;
use App\Services\StreamService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private int $classCodeId;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('API_ENCRYPTION_KEY')) {
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }

        $this->student = $this->user('student');

        $this->classCodeId = DB::table('class_codes')->insertGetId([
            'code' => 'CK-101', 'course_code' => 'CK101', 'course_name' => 'Kitchen Basics',
            'section' => 'A', 'academic_year' => '2026-2027', 'semester' => 'First',
            'max_enrollment' => 40, 'created_at' => now(), 'updated_at' => now(),
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

    private function request(User $student, string $status = 'pending_instructor', array $attrs = []): BorrowRequest
    {
        return BorrowRequest::create(array_merge([
            'student_id' => $student->id,
            'class_code_id' => $this->classCodeId,
            'purpose' => 'Practical exam',
            'borrow_date' => Carbon::tomorrow(),
            'return_date' => Carbon::now()->addDays(3),
            'status' => $status,
            'created_by' => $student->id,
        ], $attrs));
    }

    private function signatures(User $user, ?int $after = null): array
    {
        $url = '/api/stream/signature' . ($after !== null ? "?after={$after}" : '');

        return $this->decrypt($this->getJson($url, $this->auth($user))->assertStatus(200));
    }

    // ── Signature endpoint ──────────────────────────────────────────────────

    public function test_signature_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/stream/signature')->assertStatus(401);
    }

    public function test_staff_watch_every_topic_and_students_skip_staff_only_ones(): void
    {
        $staff = $this->signatures($this->user('custodian'));
        $student = $this->signatures($this->student);

        $this->assertArrayHasKey('walk_in_change', $staff['signatures']);
        $this->assertArrayHasKey('borrow_request_change', $staff['signatures']);
        $this->assertArrayNotHasKey('walk_in_change', $student['signatures']);
        $this->assertArrayHasKey('borrow_request_change', $student['signatures']);

        $this->assertIsInt($staff['at']);
        $this->assertSame([], $staff['recent']);
    }

    public function test_a_change_moves_only_its_own_topic(): void
    {
        $custodian = $this->user('custodian');
        $before = $this->signatures($custodian)['signatures'];

        $this->request($this->student);

        $after = $this->signatures($custodian)['signatures'];

        $this->assertNotSame($before['borrow_request_change'], $after['borrow_request_change']);
        $this->assertSame($before['inventory_change'], $after['inventory_change']);
        $this->assertSame($before['walk_in_change'], $after['walk_in_change']);
    }

    public function test_students_are_only_woken_by_their_own_requests(): void
    {
        $other = $this->user('student');
        $before = $this->signatures($this->student)['signatures'];

        $this->request($other);
        $this->assertSame(
            $before['borrow_request_change'],
            $this->signatures($this->student)['signatures']['borrow_request_change']
        );

        $this->request($this->student);
        $this->assertNotSame(
            $before['borrow_request_change'],
            $this->signatures($this->student)['signatures']['borrow_request_change']
        );
    }

    public function test_a_walk_in_moves_the_walk_in_topic(): void
    {
        $custodian = $this->user('custodian');
        $before = $this->signatures($custodian)['signatures'];

        DB::table('walk_in_transactions')->insert([
            'reference' => 'WI-0001', 'student_name' => 'Walk In', 'status' => 'borrowed',
            'created_by' => $custodian->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNotSame(
            $before['walk_in_change'],
            $this->signatures($custodian)['signatures']['walk_in_change']
        );
    }

    /**
     * Two writes in the same second leave `MAX(updated_at):COUNT(*)` unchanged
     * for the second one. `recent` is what stops that update being missed.
     */
    public function test_a_write_in_the_same_second_as_the_last_read_is_reported_as_recent(): void
    {
        $custodian = $this->user('custodian');
        $req = $this->request($this->student);

        $first = $this->signatures($custodian);

        // Same second, same row count: the fingerprint cannot tell.
        BorrowRequest::where('id', $req->id)->update(['purpose' => 'Changed', 'updated_at' => $req->updated_at]);

        $second = $this->signatures($custodian, $first['at']);

        $this->assertSame(
            $first['signatures']['borrow_request_change'],
            $second['signatures']['borrow_request_change']
        );
        $this->assertContains('borrow_request_change', $second['recent']);
    }

    public function test_quiet_topics_are_not_reported_as_recent(): void
    {
        $custodian = $this->user('custodian');
        $req = $this->request($this->student);
        DB::table('borrow_requests')->where('id', $req->id)->update(['updated_at' => now()->subHour()]);

        $snapshot = $this->signatures($custodian, time() - 5);

        $this->assertNotContains('borrow_request_change', $snapshot['recent']);
    }

    // ── Fingerprint helpers ─────────────────────────────────────────────────

    public function test_newest_stamp_reads_composite_fingerprints(): void
    {
        $this->assertSame(
            '2026-10-12 09:30:05',
            ChangeSignatures::newestStamp('2026-10-12 09:30:01:14|2026-10-12 09:30:05:230')
        );
        $this->assertNull(ChangeSignatures::newestStamp('0:0'));
    }

    public function test_stamps_in_the_future_never_count_as_recent(): void
    {
        $now = time();
        $future = date('Y-m-d H:i:s', $now + 8 * 3600) . ':3';
        $current = date('Y-m-d H:i:s', $now) . ':3';
        $old = date('Y-m-d H:i:s', $now - 60) . ':3';

        $this->assertFalse(ChangeSignatures::touchedSince($future, $now - 2, $now));
        $this->assertTrue(ChangeSignatures::touchedSince($current, $now - 2, $now));
        $this->assertFalse(ChangeSignatures::touchedSince($old, $now - 2, $now));
    }

    public function test_resume_point_rejects_implausible_ids(): void
    {
        $this->assertSame(time() - 3, StreamService::resumePoint((string) (time() - 3)));
        $this->assertNull(StreamService::resumePoint(null));
        $this->assertNull(StreamService::resumePoint('abc'));
        $this->assertNull(StreamService::resumePoint((string) (time() - 86400)));
        $this->assertNull(StreamService::resumePoint((string) (time() + 3600)));
    }

    // ── Stream ──────────────────────────────────────────────────────────────

    public function test_stream_opens_with_a_baseline_and_defers_to_polling_when_out_of_budget(): void
    {
        config(['realtime.max_streams' => 0]);
        $this->request($this->student);

        $response = $this->get('/api/stream', $this->auth($this->user('custodian')));
        $response->assertStatus(200);

        $body = $response->streamedContent();

        $this->assertStringContainsString('event: connected', $body);
        $this->assertMatchesRegularExpression('/^id: \d+$/m', $body);

        preg_match('/^data: (.+)$/m', $body, $match);
        $payload = json_decode($match[1], true);

        $this->assertFalse($payload['hold']);
        $this->assertArrayHasKey('borrow_request_change', $payload['signatures']);
        $this->assertArrayHasKey('walk_in_change', $payload['signatures']);
        $this->assertIsInt($payload['at']);
    }

    public function test_stream_emits_a_change_and_releases_its_slot(): void
    {
        config(['realtime.max_streams' => 1, 'realtime.poll_seconds' => 1, 'realtime.duration_seconds' => 1]);

        $custodian = $this->user('custodian');
        // Stamped in the current second, so the first tick re-announces it.
        $this->request($this->student);

        $body = $this->get('/api/stream', $this->auth($custodian))->streamedContent();

        $this->assertStringContainsString('"hold":true', $body);
        $this->assertStringContainsString('event: borrow_request_change', $body);

        // The slot was handed back, so the next stream can be held too.
        $again = $this->get('/api/stream', $this->auth($custodian))->streamedContent();
        $this->assertStringContainsString('"hold":true', $again);
    }

    // ── Read-path housekeeping ──────────────────────────────────────────────

    public function test_request_list_sweeps_at_most_once_per_interval(): void
    {
        $custodian = $this->user('custodian');
        $stale = fn () => $this->request($this->student, 'approved_instructor', [
            'borrow_date' => Carbon::yesterday(),
        ]);

        $first = $stale();
        $this->getJson('/api/borrow-requests', $this->auth($custodian))->assertStatus(200);
        $this->assertSame('expired', $first->fresh()->status);

        // Inside the interval the list is served without sweeping again.
        $second = $stale();
        $this->getJson('/api/borrow-requests', $this->auth($custodian))->assertStatus(200);
        $this->assertSame('approved_instructor', $second->fresh()->status);

        $this->travel(2)->minutes();
        $this->getJson('/api/borrow-requests', $this->auth($custodian))->assertStatus(200);
        $this->assertSame('expired', $second->fresh()->status);
    }

    // ── Indexes ─────────────────────────────────────────────────────────────

    public function test_hot_columns_are_indexed(): void
    {
        $leading = function (string $table): array {
            return array_map(
                fn (array $index) => implode(',', $index['columns']),
                Schema::getIndexes($table)
            );
        };

        $has = function (string $table, string $columns) use ($leading): bool {
            foreach ($leading($table) as $existing) {
                if (str_starts_with($existing . ',', $columns . ',')) {
                    return true;
                }
            }
            return false;
        };

        $this->assertTrue($has('borrow_requests', 'updated_at'));
        $this->assertTrue($has('borrow_requests', 'student_id'));
        $this->assertTrue($has('borrow_requests', 'status,return_date'));
        $this->assertTrue($has('borrow_request_items', 'borrow_request_id'));
        $this->assertTrue($has('notifications', 'user_id,created_at'));
        $this->assertTrue($has('inventory_history', 'timestamp'));
        $this->assertTrue($has('replacement_obligations', 'student_id'));
        $this->assertTrue($has('class_code_instructor', 'user_id'));
        $this->assertTrue($has('walk_in_transactions', 'updated_at'));
    }
}
