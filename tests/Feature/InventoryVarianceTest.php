<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\BorrowRequest;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\ReplacementObligation;
use App\Models\Donation;
use App\Services\InventoryVariance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inventory variance: Over lists stock gained with its source, Under lists
 * units out or owed with the person responsible, and the month-end close
 * absorbs Over into the starting count while Under carries forward.
 */
class InventoryVarianceTest extends TestCase
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

    private function item(): InventoryItem
    {
        return InventoryItem::find($this->itemId);
    }

    private function variance(?string $month = null): array
    {
        $response = $this->getJson(
            '/api/reports/inventory-variance' . ($month ? "?month={$month}" : ''),
            $this->auth($this->user('custodian'))
        );
        $response->assertStatus(200);

        return $this->decrypt($response);
    }

    private function inspect(BorrowRequest $req, array $line)
    {
        return $this->postJson("/api/borrow-requests/{$req->id}/inspect-items", $this->encrypt([
            'items' => [array_merge(['itemId' => $this->itemId], $line)],
        ]), $this->auth($this->user('custodian')));
    }

    /** Hand the items over, the way pickup() does. */
    private function lend(int $quantity, array $attrs = []): BorrowRequest
    {
        $req = $this->request('ready_for_pickup', $attrs, $quantity);
        $this->postJson("/api/borrow-requests/{$req->id}/pickup", $this->encrypt([]), $this->auth($this->user('custodian')))
            ->assertStatus(200);

        return $req->fresh();
    }

    public function test_first_close_restarts_every_starting_count(): void
    {
        // A hand-kept starting count that no longer matches the shelf.
        DB::table('inventory_items')->where('id', $this->itemId)->update(['eom_count' => 99]);
        $this->lend(3);

        $this->assertTrue(InventoryVariance::closeDue());
        $this->assertFalse(InventoryVariance::closeDue(), 'a month closes once');

        // 7 on the shelf + 3 with a borrower.
        $this->assertSame(10, $this->item()->eom_count);

        $closing = DB::table('inventory_period_closings')->first();
        $this->assertSame(Carbon::now()->startOfMonth()->subMonth()->format('Y-m'), $closing->period);
        $this->assertSame(1, (int) $closing->is_restart);
        $this->assertSame(99, (int) DB::table('inventory_period_closing_items')->value('baseline_before'));
    }

    public function test_under_lists_who_has_or_owes_each_unit(): void
    {
        InventoryVariance::closeDue();
        $late = $this->lend(2, ['return_date' => Carbon::now()->subDays(5)->addHours(2)]);
        $broken = $this->lend(4, ['student_id' => $this->user('student')->id]);
        $this->inspect($broken, [
            'status' => 'damaged', 'damagedQuantity' => 1, 'missingQuantity' => 0,
            'remarks' => 'Cracked base', 'notes' => 'Cracked base',
        ])->assertStatus(200);

        $report = $this->variance();
        $under = collect($report['under'])->keyBy('kind');

        $this->assertSame(['damaged', 'unreturned'], $under->keys()->sort()->values()->all());

        $this->assertSame(2, $under['unreturned']['quantity']);
        $this->assertSame('REQ-' . $late->id, $under['unreturned']['reference']);
        $this->assertSame(5, $under['unreturned']['daysOverdue']);
        $this->assertNotEmpty($under['unreturned']['person']);

        $this->assertSame(1, $under['damaged']['quantity']);
        $this->assertSame('Cracked base', $under['damaged']['reason']);

        $this->assertSame(3, $report['summary']['underOpenUnits']);
        $this->assertSame([], $report['unexplained']);
    }

    public function test_over_names_the_donor_and_the_replacement_surplus(): void
    {
        InventoryVariance::closeDue();
        $custodian = $this->user('custodian');

        $this->postJson('/api/donations', $this->encrypt([
            'donorName' => 'Hotel Sample Inc.', 'itemName' => 'Chef Knife', 'quantity' => 50,
            'purpose' => 'Laboratory use', 'date' => Carbon::now()->toIso8601String(),
            'inventoryAction' => 'add_to_existing', 'inventoryItemId' => $this->itemId,
        ]), $this->auth($custodian))->assertSuccessful();

        // The donation does not raise the starting count.
        $this->assertSame(10, $this->item()->eom_count);

        $req = $this->lend(1);
        $this->inspect($req, ['status' => 'damaged', 'damagedQuantity' => 1, 'missingQuantity' => 0])->assertStatus(200);
        $ob = ReplacementObligation::firstOrFail();
        $this->patchJson("/api/replacement-obligations/{$ob->id}", $this->encrypt([
            'resolutionType' => 'replacement', 'amountPaid' => 3,
        ]), $this->auth($custodian))->assertStatus(200);

        $report = $this->variance();
        $over = collect($report['over'])->keyBy('source');

        $this->assertSame(50, $over['donation']['quantity']);
        $this->assertSame('Hotel Sample Inc.', $over['donation']['person']);
        $this->assertSame(2, $over['replacement_surplus']['quantity']);
        $this->assertSame('Returned 3 for 1 damaged', $over['replacement_surplus']['reason']);
        $this->assertSame(52, $report['summary']['overUnits']);
        $this->assertSame(0, $report['summary']['underOpenUnits']);
        $this->assertSame([], $report['unexplained']);
    }

    public function test_a_count_change_without_a_reason_is_flagged_as_unexplained(): void
    {
        InventoryVariance::closeDue();
        DB::table('inventory_items')->where('id', $this->itemId)->update(['quantity' => 8]);

        $unexplained = $this->variance()['unexplained'];

        $this->assertCount(1, $unexplained);
        $this->assertSame(-2, $unexplained[0]['difference']);
    }

    public function test_month_end_absorbs_over_and_carries_under(): void
    {
        InventoryVariance::closeDue();
        Donation::create([
            'receipt_number' => 'DON-TEST-0001', 'donor_name' => 'Alumni', 'item_name' => 'Chef Knife',
            'quantity' => 5, 'purpose' => 'Gift', 'date' => now(), 'inventory_action' => 'add_to_existing',
            'inventory_item_id' => $this->itemId, 'created_by' => $this->student->id,
        ]);
        DB::table('inventory_items')->where('id', $this->itemId)->increment('donations', 5);
        $this->lend(2);

        $thisMonth = Carbon::now()->format('Y-m');
        Carbon::setTestNow(Carbon::now()->addMonthNoOverflow()->startOfMonth()->addHours(2));

        try {
            $this->assertTrue(InventoryVariance::closeDue());

            // 8 + 5 donated on the shelf, 2 still with the borrower.
            $this->assertSame(15, $this->item()->eom_count);

            $closing = DB::table('inventory_period_closings')->where('period', $thisMonth)->first();
            $this->assertSame(5, (int) $closing->over_absorbed);
            $this->assertSame(2, (int) $closing->under_carried);

            $next = $this->variance();
            $this->assertSame([], $next['over'], 'Over starts empty in the new month');
            $this->assertSame(2, $next['summary']['underOpenUnits'], 'Under keeps running');
            $this->assertSame(15, $next['summary']['startingCount']);
            $this->assertNotNull(collect($next['under'])->first()['carriedFrom']);

            // The closed month is still viewable, with its donation.
            $past = $this->variance($thisMonth);
            $this->assertSame(5, $past['summary']['overUnits']);
            $this->assertSame(5, $past['closing']['overAbsorbed']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_returned_items_report_gives_condition_and_days(): void
    {
        $req = $this->lend(4, ['return_date' => Carbon::now()->subDays(2)]);
        DB::table('borrow_requests')->where('id', $req->id)->update(['picked_up_at' => Carbon::now()->subDays(6)]);
        $this->inspect($req, [
            'status' => 'damaged', 'damagedQuantity' => 1, 'missingQuantity' => 0, 'remarks' => 'Chipped',
        ])->assertStatus(200);

        $response = $this->getJson('/api/reports/returned-items', $this->auth($this->user('admin')));
        $response->assertStatus(200);
        $data = $this->decrypt($response);

        $this->assertSame(1, $data['summary']['lines']);
        $this->assertSame(3, $data['summary']['good']);
        $this->assertSame(1, $data['summary']['damaged']);
        $this->assertSame(1, $data['summary']['lateRequests']);

        $row = $data['rows'][0];
        $this->assertSame(7, $row['daysKept']);
        $this->assertSame(2, $row['daysLate']);
        $this->assertSame('Chipped', $row['remarks']);
        $this->assertNotEmpty($row['inspectedBy']);

        // An instructor of another class sees none of it.
        $outsider = $this->getJson('/api/reports/returned-items', $this->auth($this->user('instructor')));
        $this->assertSame(0, $this->decrypt($outsider)['summary']['lines']);
    }

    public function test_donation_declares_cost_and_deed_is_pending_until_signed(): void
    {
        $custodian = $this->user('custodian');

        $response = $this->postJson('/api/donations', $this->encrypt([
            'donorName' => 'Hotel Sample Inc.', 'itemName' => 'Chef Knife', 'quantity' => 4, 'unitCost' => 150.50,
            'purpose' => 'Laboratory use', 'date' => Carbon::now()->toIso8601String(),
            'inventoryAction' => 'add_to_existing', 'inventoryItemId' => $this->itemId,
        ]), $this->auth($custodian));
        $response->assertSuccessful();
        $donation = $this->decrypt($response);

        $this->assertSame(150.5, (float) $donation['unitCost']);
        $this->assertSame(602.0, (float) $donation['totalValue']);
        $this->assertSame('pending', $donation['deedStatus']);

        // Admins view donations but do not manage them.
        $this->postJson("/api/donations/{$donation['id']}/deed", $this->encrypt([
            'fileUrl' => 'https://res.cloudinary.com/demo/image/upload/deed.jpg',
        ]), $this->auth($this->user('admin')))->assertStatus(403);

        $signed = $this->postJson("/api/donations/{$donation['id']}/deed", $this->encrypt([
            'fileUrl' => 'https://res.cloudinary.com/demo/image/upload/deed.jpg',
        ]), $this->auth($custodian));
        $signed->assertStatus(200);
        $this->assertSame('signed', $this->decrypt($signed)['deedStatus']);
    }

    public function test_donation_import_is_all_or_nothing(): void
    {
        $custodian = $this->user('custodian');
        $rows = [
            ['donorName' => 'Alumni Association', 'itemName' => 'chef knife', 'quantity' => 10, 'unitCost' => 200],
            ['donorName' => 'Alumni Association', 'itemName' => 'Soup Ladle', 'quantity' => 6, 'unitCost' => 80, 'category' => 'Utensils'],
        ];

        // One bad row: nothing is written, and the row is named.
        $bad = $this->postJson('/api/donations/import', $this->encrypt([
            'rows' => array_merge($rows, [['donorName' => '', 'itemName' => 'Whisk', 'quantity' => 0]]),
        ]), $this->auth($custodian));
        $bad->assertStatus(422);
        $this->assertSame(4, $this->decrypt($bad)['rowErrors'][0]['row']);
        $this->assertSame(0, Donation::count());

        $ok = $this->postJson('/api/donations/import', $this->encrypt(['rows' => $rows]), $this->auth($custodian));
        $ok->assertStatus(200);
        $summary = $this->decrypt($ok);

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(1, $summary['newItems']);
        $this->assertSame(16, $summary['units']);
        $this->assertSame(2480.0, (float) $summary['totalValue']);

        // The existing item was matched by name, whatever the letter case.
        $this->assertSame(10, $this->item()->donations);
        $this->assertSame(6, InventoryItem::where('name', 'Soup Ladle')->value('donations'));
    }
}
