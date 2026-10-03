<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StudentIncidentReportTest extends TestCase
{
    use RefreshDatabase;

    private int $classA;
    private int $classB;
    private int $itemId;
    private User $staff;
    private User $alice; // in class A
    private User $bob;   // in class B

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('API_ENCRYPTION_KEY')) {
            config(['app.api_encryption_key' => 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=']);
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }
        Carbon::setTestNow('2026-10-03 12:00:00');

        $this->staff = $this->user('custodian');
        $this->alice = $this->user('student', 'Alice', 'Reyes');
        $this->bob = $this->user('student', 'Bob', 'Cruz');

        $this->classA = $this->classCode('CK-A');
        $this->classB = $this->classCode('CK-B');
        DB::table('class_code_student')->insert([
            ['class_code_id' => $this->classA, 'user_id' => $this->alice->id],
            ['class_code_id' => $this->classB, 'user_id' => $this->bob->id],
        ]);

        $this->itemId = DB::table('inventory_items')->insertGetId([
            'name' => 'Chef Knife', 'category' => 'Knives', 'tools_or_equipment' => 'Tools',
            'quantity' => 10, 'created_by' => $this->staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, string $first = 'Test', string $last = 'User'): User
    {
        return User::factory()->create(['role' => $role, 'is_active' => true, 'first_name' => $first, 'last_name' => $last]);
    }

    private function classCode(string $code): int
    {
        return DB::table('class_codes')->insertGetId([
            'code' => $code, 'course_code' => $code, 'course_name' => 'Kitchen', 'section' => 'A',
            'academic_year' => '2026-2027', 'semester' => 'First', 'max_enrollment' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

    /** A borrow-request incident: one replacement obligation. */
    private function obligation(User $student, int $classId, string $type, string $incidentDate, array $extra = []): void
    {
        $requestId = DB::table('borrow_requests')->insertGetId([
            'student_id' => $student->id, 'class_code_id' => $classId, 'purpose' => 'Lab',
            'status' => 'missing', 'created_by' => $student->id,
            'created_at' => $incidentDate, 'updated_at' => $incidentDate,
        ]);
        DB::table('replacement_obligations')->insert(array_merge([
            'borrow_request_id' => $requestId, 'student_id' => $student->id, 'item_id' => $this->itemId,
            'item_name' => 'Chef Knife', 'item_category' => 'Knives', 'quantity' => 1, 'type' => $type,
            'status' => 'pending', 'amount' => 2, 'amount_paid' => 0, 'incident_date' => $incidentDate,
            'created_by' => $this->staff->id, 'created_at' => $incidentDate, 'updated_at' => $incidentDate,
        ], $extra));
    }

    /** A walk-in incident: an inspected walk-in item. */
    private function walkIn(User $student, string $status, string $returnedAt): void
    {
        $txId = DB::table('walk_in_transactions')->insertGetId([
            'reference' => 'WI-' . uniqid(), 'student_id' => $student->id, 'student_name' => $student->first_name,
            'status' => 'missing', 'returned_at' => $returnedAt, 'created_by' => $this->staff->id,
            'created_at' => $returnedAt, 'updated_at' => $returnedAt,
        ]);
        DB::table('walk_in_transaction_items')->insert([
            'walk_in_transaction_id' => $txId, 'item_id' => $this->itemId, 'name' => 'Mixing Bowl',
            'quantity' => 1, 'inspection_status' => $status,
        ]);
    }

    public function test_access_is_limited_to_staff_and_instructors(): void
    {
        $this->getJson('/api/reports/student-incidents')->assertStatus(401);
        $this->getJson('/api/reports/student-incidents', $this->auth($this->alice))->assertStatus(403);
        $this->getJson('/api/reports/student-incidents', $this->auth($this->staff))->assertStatus(200);
    }

    public function test_history_is_all_time_and_includes_walk_ins(): void
    {
        $this->obligation($this->alice, $this->classA, 'damaged', '2026-05-10 09:00:00'); // months ago
        $this->obligation($this->alice, $this->classA, 'missing', '2026-09-28 09:00:00', ['status' => 'replaced', 'amount_paid' => 2]);
        $this->walkIn($this->alice, 'damaged', '2026-10-01 10:00:00');
        $this->obligation($this->bob, $this->classB, 'missing', '2026-10-02 09:00:00');

        $data = $this->decrypt($this->getJson('/api/reports/student-incidents', $this->auth($this->staff)));

        $this->assertSame(2, $data['totals']['students']);
        $this->assertSame(4, $data['totals']['incidents']);

        $alice = collect($data['students'])->firstWhere('studentId', (string) $this->alice->id);
        $this->assertSame('Alice Reyes', $alice['studentName']);
        $this->assertSame(3, $alice['total']);
        $this->assertSame(2, $alice['damaged']);
        $this->assertSame(1, $alice['missing']);
        $this->assertSame(1, $alice['walkIn']);
        $this->assertSame(1, $alice['pending']);          // the May damage; the missing one was replaced
        $this->assertSame(2, $alice['outstandingUnits']);
        // Most incidents first.
        $this->assertSame((string) $this->alice->id, $data['students'][0]['studentId']);
    }

    public function test_a_date_range_narrows_the_history(): void
    {
        $this->obligation($this->alice, $this->classA, 'damaged', '2026-05-10 09:00:00');
        $this->obligation($this->alice, $this->classA, 'missing', '2026-09-28 09:00:00');

        $data = $this->decrypt($this->getJson('/api/reports/student-incidents?from=2026-09-01&to=2026-09-30', $this->auth($this->staff)));

        $this->assertSame(1, $data['totals']['incidents']);
        $this->assertSame(1, $data['totals']['missing']);
    }

    public function test_student_detail_lists_every_incident_newest_first(): void
    {
        $this->obligation($this->alice, $this->classA, 'damaged', '2026-05-10 09:00:00');
        $this->walkIn($this->alice, 'missing', '2026-10-01 10:00:00');

        $data = $this->decrypt($this->getJson("/api/reports/student-incidents/{$this->alice->id}", $this->auth($this->staff)));

        $this->assertSame(2, $data['student']['total']);
        $this->assertCount(2, $data['incidents']);
        $this->assertSame('walk_in', $data['incidents'][0]['source']);
        $this->assertSame('recorded', $data['incidents'][0]['status']);
        $this->assertSame('request', $data['incidents'][1]['source']);
        $this->assertStringStartsWith('REQ-', $data['incidents'][1]['reference']);
    }

    public function test_instructors_only_see_students_in_their_classes(): void
    {
        $instructor = $this->user('instructor');
        DB::table('class_code_instructor')->insert(['class_code_id' => $this->classA, 'user_id' => $instructor->id]);
        $this->obligation($this->alice, $this->classA, 'damaged', '2026-09-28 09:00:00');
        $this->obligation($this->bob, $this->classB, 'missing', '2026-09-28 09:00:00');

        $data = $this->decrypt($this->getJson('/api/reports/student-incidents', $this->auth($instructor)));
        $this->assertSame([(string) $this->alice->id], collect($data['students'])->pluck('studentId')->all());

        $this->getJson("/api/reports/student-incidents/{$this->bob->id}", $this->auth($instructor))->assertStatus(403);
        $this->getJson("/api/reports/student-incidents/{$this->alice->id}", $this->auth($instructor))->assertStatus(200);
    }

    public function test_class_filter_limits_to_enrolled_students(): void
    {
        $this->obligation($this->alice, $this->classA, 'damaged', '2026-09-28 09:00:00');
        $this->obligation($this->bob, $this->classB, 'missing', '2026-09-28 09:00:00');

        $data = $this->decrypt($this->getJson("/api/reports/student-incidents?class_code_id={$this->classB}", $this->auth($this->staff)));

        $this->assertSame([(string) $this->bob->id], collect($data['students'])->pluck('studentId')->all());
    }

    public function test_replacement_aging_buckets_and_past_due(): void
    {
        $this->obligation($this->alice, $this->classA, 'damaged', '2026-10-01 09:00:00');                                  // 2 days
        $this->obligation($this->alice, $this->classA, 'missing', '2026-09-15 09:00:00', ['due_date' => '2026-09-22 09:00:00']); // 18 days, past due
        $this->obligation($this->bob, $this->classB, 'missing', '2026-07-01 09:00:00', ['due_date' => '2026-10-10 09:00:00']);   // 94 days, not yet due
        $this->obligation($this->bob, $this->classB, 'damaged', '2026-06-01 09:00:00', ['status' => 'replaced']);              // closed: ignored

        $aging = $this->decrypt($this->getJson('/api/reports/student-incidents', $this->auth($this->staff)))['replacementAging'];

        $this->assertSame(3, $aging['open']);
        $this->assertSame(1, $aging['within7Days']);
        $this->assertSame(1, $aging['within30Days']);
        $this->assertSame(1, $aging['over30Days']);
        $this->assertSame(1, $aging['pastDue']);
        $this->assertSame(11, $aging['pastDueItems'][0]['daysPastDue']);
        $this->assertSame('Alice Reyes', $aging['pastDueItems'][0]['studentName']);
    }

    public function test_walk_in_damage_counts_toward_high_incident_students(): void
    {
        $this->walkIn($this->alice, 'damaged', '2026-10-01 10:00:00');
        $this->obligation($this->alice, $this->classA, 'missing', '2026-10-02 09:00:00');

        $admin = $this->user('admin');
        $data = $this->decrypt($this->getJson('/api/reports/analytics?period=month', $this->auth($admin)));

        $alice = collect($data['studentRisk']['highIncidentStudents'])->firstWhere('_id', (string) $this->alice->id);
        $this->assertSame(2, $alice['incidents']);
        $this->assertSame(1, $alice['damagedCount']);
        $this->assertSame(1, $alice['missingCount']);
    }
}
