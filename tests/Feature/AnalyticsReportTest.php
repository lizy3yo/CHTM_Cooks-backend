<?php

namespace Tests\Feature;

use App\Models\User;
use App\Helpers\JwtHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure API_ENCRYPTION_KEY is set for the testing environment if not loaded from .env
        if (!env('API_ENCRYPTION_KEY')) {
            config(['app.api_encryption_key' => 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=']);
            putenv('API_ENCRYPTION_KEY=Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        }
    }

    private function getAuthHeader(User $user): array
    {
        $secret = env('JWT_SECRET', 'test-secret-key-that-is-long-enough-for-jwt');
        $token = JwtHelper::sign(['userId' => $user->id], $secret);
        return [
            'Authorization' => "Bearer {$token}"
        ];
    }

    private function decryptResponse($response): array
    {
        $data = $response->json();
        $key = env('API_ENCRYPTION_KEY', 'Y6j3KOY25j9xxe2dQ88g3qFxP5rYKKsLz6jt0KRJqcE=');
        $keyBytes = base64_decode($key);
        $ciphertext = base64_decode($data['payload']);
        $iv = base64_decode($data['iv']);
        $tag = base64_decode($data['tag']);

        $decryptedRaw = openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag);
        return json_decode($decryptedRaw, true);
    }

    public function test_unauthenticated_request_is_blocked(): void
    {
        $response = $this->getJson('/api/reports/analytics/summary');
        $response->assertStatus(401);
    }

    public function test_forbidden_role_is_blocked(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'is_active' => true
        ]);

        $headers = $this->getAuthHeader($user);

        $response = $this->getJson('/api/reports/analytics/summary', $headers);
        $response->assertStatus(403);
    }

    public function test_authorized_role_can_fetch_summary(): void
    {
        $user = User::factory()->create([
            'role' => 'custodian',
            'is_active' => true
        ]);

        $headers = $this->getAuthHeader($user);

        $response = $this->getJson('/api/reports/analytics/summary?period=month', $headers);
        $response->assertStatus(200);

        $decrypted = $this->decryptResponse($response);
        $this->assertArrayHasKey('borrowRequests', $decrypted);
        $this->assertArrayHasKey('lossAndDamage', $decrypted);
        $this->assertArrayHasKey('inventory', $decrypted);
        $this->assertArrayHasKey('studentRisk', $decrypted);
    }

    public function test_authorized_role_can_fetch_full_report(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'is_active' => true
        ]);

        $headers = $this->getAuthHeader($user);

        $response = $this->getJson('/api/reports/analytics?period=month', $headers);
        $response->assertStatus(200);

        $decrypted = $this->decryptResponse($response);
        $this->assertArrayHasKey('borrowRequests', $decrypted);
        $this->assertArrayHasKey('lossAndDamage', $decrypted);
        $this->assertArrayHasKey('inventory', $decrypted);
        $this->assertArrayHasKey('studentRisk', $decrypted);
    }

    public function test_analytics_handles_query_filters_successfully(): void
    {
        $user = User::factory()->create([
            'role' => 'superadmin',
            'is_active' => true
        ]);

        $headers = $this->getAuthHeader($user);

        $filters = [
            'class_code_id' => '1',
            'instructor_id' => '2',
            'student_id' => '3',
            'custodian_id' => '4',
        ];

        $queryString = http_build_query(array_merge(['period' => 'month'], $filters));

        $response = $this->getJson("/api/reports/analytics?{$queryString}", $headers);
        $response->assertStatus(200);
        
        $summaryResponse = $this->getJson("/api/reports/analytics/summary?{$queryString}", $headers);
        $summaryResponse->assertStatus(200);
    }
}
