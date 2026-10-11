<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\BorrowRequest;
use App\Models\ClassCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Term setup imports: class codes, instructors and students land together or
 * not at all, with problems reported against their spreadsheet row.
 */
class DataImportTest extends TestCase
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

    public function test_only_superadmin_can_import(): void
    {
        $rows = [['firstName' => 'Ana', 'lastName' => 'Lopez', 'email' => 'ana@example.test']];

        foreach (['admin', 'custodian', 'instructor', 'student'] as $role) {
            $this->postJson('/api/users/import', $this->encrypt([
                'role' => 'instructor', 'temporaryPassword' => 'Temp-Pass-123', 'rows' => $rows,
            ]), $this->auth($this->user($role)))->assertStatus(403);
        }
    }

    public function test_class_codes_import_creates_updates_and_assigns_the_instructor(): void
    {
        $superadmin = $this->user('superadmin');
        $row = [
            'courseCode' => 'HM-201', 'courseName' => 'Food Production', 'section' => 'B',
            'academicYear' => '2026-2027', 'semester' => '1st Semester', 'maxEnrollment' => 35,
            'instructorEmail' => strtoupper($this->instructor->email),
        ];

        $first = $this->postJson('/api/class-codes/import', $this->encrypt(['rows' => [$row]]), $this->auth($superadmin));
        $first->assertStatus(200);
        $summary = $this->decrypt($first);
        $this->assertSame(1, $summary['created']);
        $this->assertSame(0, $summary['updated']);
        $this->assertSame(1, $summary['instructorsAssigned']);

        $class = ClassCode::where('course_code', 'HM-201')->firstOrFail();
        $this->assertSame('First', $class->semester);
        $this->assertTrue($class->instructors->contains($this->instructor));

        // The same class again is updated, not duplicated.
        $row['courseName'] = 'Food Production II';
        $second = $this->postJson('/api/class-codes/import', $this->encrypt(['rows' => [$row]]), $this->auth($superadmin));
        $this->assertSame(1, $this->decrypt($second)['updated']);
        $this->assertSame(1, ClassCode::where('course_code', 'HM-201')->count());
        $this->assertSame('Food Production II', $class->fresh()->course_name);
    }

    public function test_class_codes_import_names_the_row_with_an_unknown_instructor(): void
    {
        $response = $this->postJson('/api/class-codes/import', $this->encrypt(['rows' => [
            ['courseCode' => 'HM-301', 'courseName' => 'Baking', 'section' => 'A', 'academicYear' => '2026-2027', 'semester' => 'First'],
            ['courseCode' => 'HM-302', 'courseName' => 'Pastry', 'section' => 'A', 'academicYear' => '2026-2027', 'semester' => 'First',
                'instructorEmail' => 'nobody@example.test'],
        ]]), $this->auth($this->user('superadmin')));

        $response->assertStatus(422);
        $this->assertSame(3, $this->decrypt($response)['rowErrors'][0]['row']);
        $this->assertSame(0, ClassCode::whereIn('course_code', ['HM-301', 'HM-302'])->count(), 'nothing is saved');
    }

    public function test_students_import_creates_accounts_and_enrols_them(): void
    {
        $response = $this->postJson('/api/users/import', $this->encrypt([
            'role' => 'student',
            'temporaryPassword' => 'Temp-Pass-123',
            'rows' => [
                ['firstName' => 'Ana', 'lastName' => 'Lopez', 'email' => 'Ana.Lopez@Example.test', 'yearLevel' => '2nd Year', 'block' => 'A',
                    'courseCode' => 'ck101', 'section' => 'a'],
                ['firstName' => 'Ben', 'lastName' => 'Cruz', 'email' => 'ben.cruz@example.test', 'yearLevel' => '2nd Year', 'block' => 'A'],
            ],
        ]), $this->auth($this->user('superadmin')));

        $response->assertStatus(200);
        $this->assertSame(2, $this->decrypt($response)['imported']);
        $this->assertSame(1, $this->decrypt($response)['enrolled']);

        $ana = User::where('email', 'ana.lopez@example.test')->firstOrFail();
        $this->assertSame('student', $ana->role);
        $this->assertTrue((bool) $ana->email_verified);
        $this->assertTrue(DB::table('class_code_student')->where('user_id', $ana->id)->where('class_code_id', $this->classCodeId)->exists());
    }

    public function test_students_import_rejects_the_whole_file_on_a_duplicate_email(): void
    {
        $response = $this->postJson('/api/users/import', $this->encrypt([
            'role' => 'student',
            'temporaryPassword' => 'Temp-Pass-123',
            'rows' => [
                ['firstName' => 'Ana', 'lastName' => 'Lopez', 'email' => 'new.student@example.test', 'yearLevel' => '1st Year', 'block' => 'A'],
                ['firstName' => 'Dup', 'lastName' => 'Licate', 'email' => $this->student->email, 'yearLevel' => '1st Year', 'block' => 'A'],
                ['firstName' => 'No', 'lastName' => 'Block', 'email' => 'no.block@example.test', 'yearLevel' => '1st Year'],
            ],
        ]), $this->auth($this->user('superadmin')));

        $response->assertStatus(422);
        $errors = collect($this->decrypt($response)['rowErrors']);
        $this->assertSame([3, 4], $errors->pluck('row')->unique()->values()->all());
        $this->assertFalse(User::where('email', 'new.student@example.test')->exists(), 'nothing is saved');
    }
}
