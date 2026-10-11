<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\BorrowRequest;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\ReplacementObligation;
use App\Services\OverdueEnforcement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Return inspection records unit counts, notes and photos; a replacement can
 * exceed the debt; and the 3-day rule notifies once and puts borrowing on hold.
 */
class ReturnInspectionTest extends TestCase
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

    private function request(string $status, array $attrs = [], int $quantity = 4): BorrowRequest
    {
        $req = BorrowRequest::create(array_merge([
            'student_id' => $this->student->id,
            'instructor_id' => $this->instructor->id,
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

    private function stock(): int
    {
        return (int) InventoryItem::find($this->itemId)->quantity;
    }

    private function inspect(BorrowRequest $req, array $line)
    {
        return $this->postJson("/api/borrow-requests/{$req->id}/inspect-items", $this->encrypt([
            'items' => [array_merge(['itemId' => $this->itemId], $line)],
        ]), $this->auth($this->user('custodian')));
    }

    public function test_mixed_line_stores_unit_counts_note_and_photos(): void
    {
        $req = $this->request('borrowed');
        $photo = 'https://res.cloudinary.com/demo/image/upload/crack.jpg';

        $this->inspect($req, [
            'status' => 'missing',
            'damagedQuantity' => 1,
            'missingQuantity' => 1,
            'additionalReturned' => 0,
            'notes' => '[Quantities - Good: 2, Damaged: 1, Missing: 1] | Remarks: Cracked rim',
            'remarks' => 'Cracked rim',
            'photos' => [$photo],
            'dueDate' => Carbon::now()->addDays(5)->toDateString(),
        ])->assertStatus(200);

        $response = $this->getJson("/api/borrow-requests/{$req->id}", $this->auth($this->user('admin')));
        $inspection = $this->decrypt($response)['items'][0]['inspection'];

        $this->assertSame(2, $inspection['goodQuantity']);
        $this->assertSame(1, $inspection['damagedQuantity']);
        $this->assertSame(1, $inspection['missingQuantity']);
        $this->assertSame('Cracked rim', $inspection['remarks']);
        $this->assertSame([$photo], $inspection['photos']);
        $this->assertNotEmpty($inspection['inspectedByName']);

        // One obligation per kind of loss, each for its own units.
        $obligations = ReplacementObligation::where('borrow_request_id', $req->id)->orderBy('type')->get();
        $this->assertSame(['damaged', 'missing'], $obligations->pluck('type')->all());
        $this->assertSame([1, 1], $obligations->pluck('amount')->all());
    }

    public function test_good_units_of_a_damaged_line_go_back_on_the_shelf(): void
    {
        $req = $this->request('borrowed');
        $before = $this->stock();

        $this->inspect($req, [
            'status' => 'damaged', 'damagedQuantity' => 1, 'missingQuantity' => 0, 'additionalReturned' => 2,
        ])->assertStatus(200);

        // 3 good units plus 2 handed in on top; the damaged one stays out.
        $this->assertSame($before + 5, $this->stock());
    }

    public function test_older_clients_sending_only_a_status_still_work(): void
    {
        $req = $this->request('borrowed');
        $before = $this->stock();

        $this->inspect($req, ['status' => 'good'])->assertStatus(200);

        $this->assertSame($before + 4, $this->stock());
        $this->assertSame('returned', $req->fresh()->status);
    }

    public function test_a_request_cannot_be_inspected_twice(): void
    {
        $req = $this->request('borrowed');
        $this->inspect($req, ['status' => 'good'])->assertStatus(200);
        $after = $this->stock();

        $this->inspect($req, ['status' => 'good'])->assertStatus(409);
        $this->assertSame($after, $this->stock());
    }

    public function test_replacing_more_than_owed_settles_the_debt_and_records_the_surplus(): void
    {
        $req = $this->request('borrowed');
        $this->inspect($req, ['status' => 'damaged', 'damagedQuantity' => 1, 'missingQuantity' => 0])->assertStatus(200);
        $ob = ReplacementObligation::where('borrow_request_id', $req->id)->firstOrFail();
        $before = $this->stock();

        // One glass broken, three handed in.
        $response = $this->patchJson("/api/replacement-obligations/{$ob->id}", $this->encrypt([
            'resolutionType' => 'replacement', 'amountPaid' => 3,
        ]), $this->auth($this->user('custodian')));
        $response->assertStatus(200);
        $data = $this->decrypt($response);

        $this->assertSame(1, $data['settled']);
        $this->assertSame(2, $data['surplus']);

        $ob->refresh();
        $this->assertSame('replaced', $ob->status);
        $this->assertSame(1, $ob->amount_paid);
        $this->assertSame(2, $ob->surplus_quantity);
        $this->assertSame($before + 3, $this->stock());
        $this->assertSame('resolved', $req->fresh()->status);
    }

    public function test_three_days_overdue_sends_one_notice_to_student_and_staff(): void
    {
        $admin = $this->user('admin');
        $custodian = $this->user('custodian');
        $late = $this->request('borrowed', ['return_date' => Carbon::now()->subDays(4)]);
        // Two days late: overdue, but not yet past the line.
        $this->request('borrowed', ['return_date' => Carbon::now()->subDays(2), 'student_id' => $this->user('student')->id]);

        $this->assertSame(1, OverdueEnforcement::sweep());
        $this->assertSame(0, OverdueEnforcement::sweep());

        $this->assertNotNull($late->fresh()->overdue_notice_sent_at);

        foreach ([$this->student, $this->instructor, $custodian, $admin] as $recipient) {
            $this->assertSame(
                1,
                Notification::where('user_id', $recipient->id)->where('type', 'borrow_request_overdue_notice')->count(),
                "{$recipient->role} should have exactly one notice"
            );
        }

        $response = $this->getJson("/api/borrow-requests/{$late->id}", $this->auth($admin));
        $events = collect($this->decrypt($response)['events'])->pluck('event')->all();
        $this->assertContains('overdue_notice', $events);
    }

    public function test_student_cannot_borrow_while_items_are_three_days_overdue(): void
    {
        DB::table('class_code_student')->insert([
            'class_code_id' => $this->classCodeId, 'user_id' => $this->student->id,
        ]);
        $late = $this->request('borrowed', ['return_date' => Carbon::now()->subDays(4)]);

        $submit = fn () => $this->postJson('/api/borrow-requests', $this->encrypt([
            'classCodeId' => $this->classCodeId,
            'purpose' => 'Lab',
            'borrowDate' => Carbon::tomorrow()->setTime(9, 0)->toIso8601String(),
            'returnDate' => Carbon::tomorrow()->setTime(17, 0)->toIso8601String(),
            'items' => [['itemId' => $this->itemId, 'quantity' => 1]],
        ]), $this->auth($this->student));

        $blocked = $submit();
        $blocked->assertStatus(403);
        $this->assertSame('OVERDUE_HOLD', $this->decrypt($blocked)['code']);

        // Once the items are checked back in, the hold lifts.
        $this->inspect($late, ['status' => 'good'])->assertStatus(200);
        $submit()->assertStatus(201);
    }

    public function test_replacement_due_date_can_be_moved(): void
    {
        $req = $this->request('borrowed');
        $this->inspect($req, [
            'status' => 'damaged', 'damagedQuantity' => 1, 'missingQuantity' => 0,
            'dueDate' => Carbon::now()->addDays(3)->toDateString(),
        ])->assertStatus(200);

        $newDate = Carbon::now()->addDays(10)->toDateString();

        $this->patchJson("/api/borrow-requests/{$req->id}/due-date", $this->encrypt([
            'itemId' => $this->itemId, 'dueDate' => $newDate,
        ]), $this->auth($this->student))->assertStatus(403);

        $this->patchJson("/api/borrow-requests/{$req->id}/due-date", $this->encrypt([
            'itemId' => $this->itemId, 'dueDate' => $newDate,
        ]), $this->auth($this->user('custodian')))->assertStatus(200);

        $this->assertSame($newDate, ReplacementObligation::where('borrow_request_id', $req->id)->first()->due_date->toDateString());
        $this->assertSame($newDate, DB::table('borrow_request_items')->where('borrow_request_id', $req->id)->value('due_date') ? Carbon::parse(DB::table('borrow_request_items')->where('borrow_request_id', $req->id)->value('due_date'))->toDateString() : null);
    }
}
