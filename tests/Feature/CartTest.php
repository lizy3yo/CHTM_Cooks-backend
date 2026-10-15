<?php

namespace Tests\Feature;

use App\Helpers\JwtHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Adding to the cart never raises a quantity the student did not ask for:
 * `ifAbsent` and the batch endpoint leave items already in the cart alone.
 */
class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        if (!env('API_ENCRYPTION_KEY')) {
            config(['app.api_encryption_key' => 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=']);
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }

        $this->student = User::factory()->create(['role' => 'student', 'is_active' => true]);
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

    private function add(array $body): array
    {
        return $this->decrypt(
            $this->postJson('/api/cart', $this->encrypt($body), $this->auth($this->student))->assertStatus(200)
        );
    }

    private function quantityOf(string $itemId): ?int
    {
        $row = DB::table('student_carts')
            ->where('user_id', $this->student->id)
            ->where('item_id', $itemId)
            ->first();

        return $row ? (int) $row->quantity : null;
    }

    public function test_plain_add_still_increments_an_existing_item(): void
    {
        $item = ['itemId' => '7', 'name' => 'Tong', 'maxQuantity' => 5];

        $this->assertSame('added', $this->add($item)['result']);
        $this->assertSame('incremented', $this->add($item)['result']);
        $this->assertSame(2, $this->quantityOf('7'));
    }

    public function test_if_absent_add_leaves_an_existing_item_untouched(): void
    {
        $item = ['itemId' => '7', 'name' => 'Tong', 'maxQuantity' => 5];

        $this->add($item + ['quantity' => 3]);
        $result = $this->add($item + ['ifAbsent' => true]);

        $this->assertSame('unchanged', $result['result']);
        $this->assertSame(3, $this->quantityOf('7'));
    }

    public function test_if_absent_add_inserts_a_missing_item(): void
    {
        $result = $this->add(['itemId' => '7', 'name' => 'Tong', 'maxQuantity' => 5, 'ifAbsent' => true]);

        $this->assertSame('added', $result['result']);
        $this->assertSame(1, $this->quantityOf('7'));
    }

    public function test_batch_adds_only_missing_items_and_is_idempotent(): void
    {
        $this->add(['itemId' => '1', 'name' => 'Rolling Pin', 'maxQuantity' => 5, 'quantity' => 4]);

        $batch = ['items' => [
            ['itemId' => '1', 'name' => 'Rolling Pin', 'maxQuantity' => 5],
            ['itemId' => '2', 'name' => 'Hand Whisk', 'maxQuantity' => 3],
            ['itemId' => '3', 'name' => 'Wooden Spoon', 'maxQuantity' => 8],
        ]];

        $first = $this->decrypt(
            $this->postJson('/api/cart/batch', $this->encrypt($batch), $this->auth($this->student))->assertStatus(200)
        );
        $this->assertSame(2, $first['added']);
        $this->assertCount(3, $first['items']);

        // A retry or double click changes nothing.
        $second = $this->decrypt(
            $this->postJson('/api/cart/batch', $this->encrypt($batch), $this->auth($this->student))->assertStatus(200)
        );
        $this->assertSame(0, $second['added']);

        $this->assertSame(4, $this->quantityOf('1'));
        $this->assertSame(1, $this->quantityOf('2'));
        $this->assertSame(1, $this->quantityOf('3'));
    }

    private function inventoryItem(bool $required, bool $archived = false): string
    {
        return (string) DB::table('inventory_items')->insertGetId([
            'name' => $required ? 'Rolling Pin' : 'Bar Spoon', 'category' => 'Tools',
            'tools_or_equipment' => 'Tools', 'quantity' => 5, 'is_required' => $required,
            'archived' => $archived, 'created_by' => $this->student->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function remove(string $itemId)
    {
        return $this->deleteJson('/api/cart?itemId=' . $itemId, $this->encrypt([]), $this->auth($this->student));
    }

    public function test_a_required_item_cannot_be_removed_on_its_own(): void
    {
        $required = $this->inventoryItem(true);
        $this->add(['itemId' => $required, 'name' => 'Rolling Pin', 'maxQuantity' => 5]);

        $this->remove($required)->assertStatus(422);
        $this->assertSame(1, $this->quantityOf($required));
    }

    public function test_an_optional_item_can_be_removed(): void
    {
        $optional = $this->inventoryItem(false);
        $this->add(['itemId' => $optional, 'name' => 'Bar Spoon', 'maxQuantity' => 5]);

        $this->remove($optional)->assertStatus(200);
        $this->assertNull($this->quantityOf($optional));
    }

    public function test_an_archived_required_item_can_be_removed(): void
    {
        $archived = $this->inventoryItem(true, archived: true);
        $this->add(['itemId' => $archived, 'name' => 'Rolling Pin', 'maxQuantity' => 5]);

        $this->remove($archived)->assertStatus(200);
    }

    public function test_clearing_the_whole_cart_still_removes_required_items(): void
    {
        $required = $this->inventoryItem(true);
        $this->add(['itemId' => $required, 'name' => 'Rolling Pin', 'maxQuantity' => 5]);

        $this->deleteJson('/api/cart', $this->encrypt([]), $this->auth($this->student))->assertStatus(200);
        $this->assertNull($this->quantityOf($required));
    }

    public function test_batch_rejects_an_empty_list(): void
    {
        $this->postJson('/api/cart/batch', $this->encrypt(['items' => []]), $this->auth($this->student))
            ->assertStatus(400);
    }
}
