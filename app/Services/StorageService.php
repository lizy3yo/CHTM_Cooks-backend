<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class StorageService
{
    /**
     * Upload a file to Cloudinary (if configured) or fallback to local public disk storage.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     * @param  string  $folder
     * @return array  ['url' => string, 'filename' => string]
     */
    public static function upload(UploadedFile $file, string $folder = 'inventory'): array
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $cloudinaryFolder = config('services.cloudinary.folder') ?: $folder;

        if ($cloudName && $apiKey && $apiSecret) {
            try {
                $timestamp = time();
                $params = [
                    'folder' => $cloudinaryFolder,
                    'timestamp' => $timestamp,
                ];
                ksort($params);
                
                $signatureStr = "";
                foreach ($params as $k => $v) {
                    $signatureStr .= "$k=$v&";
                }
                $signatureStr = rtrim($signatureStr, '&') . $apiSecret;
                $signature = sha1($signatureStr);

                $uploadCall = function($verifySsl = true) use ($cloudName, $file, $apiKey, $timestamp, $signature, $cloudinaryFolder) {
                    $client = Http::asMultipart();
                    if (!$verifySsl) {
                        $client = $client->withoutVerifying();
                    }
                    return $client->attach(
                        'file',
                        file_get_contents($file->getRealPath()),
                        $file->getClientOriginalName()
                    )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
                        'api_key' => $apiKey,
                        'timestamp' => $timestamp,
                        'signature' => $signature,
                        'folder' => $cloudinaryFolder,
                    ]);
                };

                try {
                    $response = $uploadCall(true);
                } catch (\Exception $e) {
                    $errStr = strtolower($e->getMessage());
                    if (str_contains($errStr, 'ssl') || str_contains($errStr, 'certificate') || str_contains($errStr, 'issuer')) {
                        Log::warning('Cloudinary upload SSL error detected. Retrying without SSL verification: ' . $e->getMessage());
                        $response = $uploadCall(false);
                    } else {
                        throw $e;
                    }
                }

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'success' => true,
                        'url' => $data['secure_url'],
                        'filename' => $data['public_id'],
                    ];
                } else {
                    $cloudinaryError = 'Cloudinary upload error response: ' . $response->body();
                    Log::error($cloudinaryError);
                }
            } catch (\Exception $e) {
                $cloudinaryError = 'Cloudinary upload exception: ' . $e->getMessage();
                Log::error($cloudinaryError);
            }
        } else {
            $cloudinaryError = 'Cloudinary is not configured (missing cloud_name, api_key, or api_secret).';
        }

        // Fallback to local disk storage
        try {
            $path = $file->store($folder, 'public');
            $url = asset('storage/' . $path);

            return [
                'success' => true,
                'url' => $url,
                'filename' => basename($path),
            ];
        } catch (\Exception $e) {
            Log::error('Local storage fallback failed: ' . $e->getMessage());
            $detailedError = 'Failed to upload file. Local storage fallback failed: ' . $e->getMessage();
            if ($cloudinaryError) {
                $detailedError .= ' | Cloudinary Error: ' . $cloudinaryError;
            }
            throw new \Exception($detailedError);
        }
    }

    /**
     * Upload raw data as an image to Cloudinary or fallback to local storage
     * Used for QR code images.
     *
     * @param string $binaryData Base64 or raw image binary data
     * @param string $filename Public ID / filename
     * @param string $folder Destination folder
     * @return string URL of the uploaded image
     */
    public static function uploadRaw(string $binaryData, string $filename, string $folder = 'emails'): ?string
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $cloudinaryFolder = config('services.cloudinary.folder') ?: 'chtm_cooks';

        if ($cloudName && $apiKey && $apiSecret) {
            try {
                $timestamp = time();
                $params = [
                    'folder' => "{$cloudinaryFolder}/{$folder}",
                    'public_id' => $filename,
                    'timestamp' => $timestamp,
                ];
                ksort($params);
                
                $signatureStr = "";
                foreach ($params as $k => $v) {
                    $signatureStr .= "$k=$v&";
                }
                $signatureStr = rtrim($signatureStr, '&') . $apiSecret;
                $signature = sha1($signatureStr);

                $uploadCall = function($verifySsl = true) use ($cloudName, $binaryData, $filename, $apiKey, $timestamp, $signature, $cloudinaryFolder, $folder) {
                    $client = Http::asMultipart();
                    if (!$verifySsl) {
                        $client = $client->withoutVerifying();
                    }
                    return $client->attach(
                        'file',
                        $binaryData,
                        "{$filename}.png"
                    )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
                        'api_key' => $apiKey,
                        'timestamp' => $timestamp,
                        'signature' => $signature,
                        'folder' => "{$cloudinaryFolder}/{$folder}",
                        'public_id' => $filename,
                        'overwrite' => 'true',
                    ]);
                };

                try {
                    $response = $uploadCall(true);
                } catch (\Exception $e) {
                    $errStr = strtolower($e->getMessage());
                    if (str_contains($errStr, 'ssl') || str_contains($errStr, 'certificate') || str_contains($errStr, 'issuer')) {
                        Log::warning('Cloudinary raw upload SSL error detected. Retrying without SSL verification: ' . $e->getMessage());
                        $response = $uploadCall(false);
                    } else {
                        throw $e;
                    }
                }

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['secure_url'];
                } else {
                    Log::error('Cloudinary raw upload error response: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::error('Cloudinary raw upload exception: ' . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Delete a file from Cloudinary (if configured) or from local public disk storage.
     *
     * @param string $publicId
     * @param string $folder Only used for local storage fallback
     * @return bool
     */
    public static function delete(string $publicId, string $folder = 'profiles'): bool
    {
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if ($publicId && $cloudName && $apiKey && $apiSecret) {
            try {
                $timestamp = time();
                $signatureStr = "public_id={$publicId}&timestamp={$timestamp}{$apiSecret}";
                $signature = sha1($signatureStr);

                $destroyCall = function($verifySsl = true) use ($cloudName, $publicId, $apiKey, $timestamp, $signature) {
                    $client = Http::asForm();
                    if (!$verifySsl) {
                        $client = $client->withoutVerifying();
                    }
                    return $client->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy", [
                        'public_id' => $publicId,
                        'api_key' => $apiKey,
                        'timestamp' => $timestamp,
                        'signature' => $signature
                    ]);
                };

                try {
                    $response = $destroyCall(true);
                } catch (\Exception $e) {
                    $errStr = strtolower($e->getMessage());
                    if (str_contains($errStr, 'ssl') || str_contains($errStr, 'certificate') || str_contains($errStr, 'issuer')) {
                        Log::warning('Cloudinary destroy SSL error detected. Retrying without SSL verification: ' . $e->getMessage());
                        $response = $destroyCall(false);
                    } else {
                        throw $e;
                    }
                }

                if ($response->successful() && $response->json('result') === 'ok') {
                    return true;
                }
            } catch (\Exception $e) {
                Log::error('Cloudinary destroy exception: ' . $e->getMessage());
            }
        }

        // Local storage delete fallback
        try {
            $localPath = $folder . '/' . $publicId;
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($localPath)) {
                return \Illuminate\Support\Facades\Storage::disk('public')->delete($localPath);
            }
        } catch (\Exception $e) {
            Log::error('Local storage delete failed: ' . $e->getMessage());
        }

        return false;
    }
}

