<?php

namespace App\Helpers;

class JwtHelper
{
    /**
     * Encode string to base64url standard
     */
    private static function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Decode base64url encoded string
     */
    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $data .= str_repeat('=', $padlen);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    /**
     * Create a signed JWT token
     */
    public static function sign(array $payload, string $secret, string $expiresIn = '1h'): string
    {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        
        $exp = time() + self::parseExpiresIn($expiresIn);
        $payload['exp'] = $exp;
        $payload['iat'] = time();

        $base64UrlHeader = self::base64UrlEncode($header);
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Verify and decode a JWT token. Returns null if invalid or expired.
     */
    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($header, $payload, $signature) = $parts;

        // Verify signature
        $validSignature = hash_hmac('sha256', $header . "." . $payload, $secret, true);
        if (!hash_equals(self::base64UrlDecode($signature), $validSignature)) {
            return null;
        }

        $decodedPayload = json_decode(self::base64UrlDecode($payload), true);
        if (!$decodedPayload) {
            return null;
        }

        // Verify expiration
        if (isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) {
            return null;
        }

        return $decodedPayload;
    }

    /**
     * Parse human-readable expiration (e.g. '1h', '12h', '7d') to seconds
     */
    private static function parseExpiresIn(string $expiresIn): int
    {
        $val = intval(substr($expiresIn, 0, -1));
        $unit = strtolower(substr($expiresIn, -1));
        
        switch ($unit) {
            case 'h': return $val * 3600;
            case 'd': return $val * 86400;
            case 'm': return $val * 60;
            case 's': return $val;
            default: return 3600;
        }
    }
}
