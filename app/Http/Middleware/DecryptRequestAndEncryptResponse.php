<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\JsonResponse;

class DecryptRequestAndEncryptResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = env('API_ENCRYPTION_KEY');
        if (!$key) {
            return response()->json(['error' => 'API Encryption Key not configured'], 500);
        }
        
        $keyBytes = base64_decode($key);
        if (strlen($keyBytes) !== 32) {
            return response()->json(['error' => 'API Encryption Key must be 32 bytes when base64 decoded'], 500);
        }

        // Decrypt incoming request if it is not a safe method (POST, PUT, PATCH, DELETE) and has content
        if (!$request->isMethodSafe() && $request->getContent() !== '') {
            $data = $request->json()->all();
            
            if (!isset($data['payload']) || !isset($data['iv']) || !isset($data['tag']) || !isset($data['timestamp'])) {
                return response()->json(['error' => 'Missing encrypted payload components'], 400);
            }

            // Replay attack prevention: verify timestamp (allow 120 seconds clock skew)
            $timestamp = intval($data['timestamp']);
            if (abs(time() - $timestamp) > 120) {
                return response()->json(['error' => 'Request timestamp expired or clock out of sync'], 400);
            }

            // Decode GCM parts
            $ciphertext = base64_decode($data['payload']);
            $iv = base64_decode($data['iv']);
            $tag = base64_decode($data['tag']);

            // Decrypt raw ciphertext
            $decryptedRaw = openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag);
            if ($decryptedRaw === false) {
                return response()->json(['error' => 'Decryption failed: bad payload or key'], 400);
            }

            $decrypted = json_decode($decryptedRaw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return response()->json(['error' => 'Decrypted content is not valid JSON'], 400);
            }

            // Replace request input with decrypted data
            $request->replace($decrypted);
        }

        // Process request through next middlewares / controllers
        $response = $next($request);

        // Encrypt outgoing response if it is a JSON response
        if ($response instanceof JsonResponse) {
            $originalData = $response->getData(true);
            $plainText = json_encode($originalData);
            
            $iv = openssl_random_pseudo_bytes(12);
            $tag = '';
            
            // Encrypt using AES-256-GCM
            $ciphertext = openssl_encrypt($plainText, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            
            $encryptedData = [
                'payload' => base64_encode($ciphertext),
                'iv' => base64_encode($iv),
                'tag' => base64_encode($tag),
                'timestamp' => time()
            ];
            
            $response->setData($encryptedData);
            $response->headers->set('Content-Type', 'application/json');
            $response->headers->set('X-Response-Encrypted', 'true');
        }

        return $response;
    }
}
