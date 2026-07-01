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

                $response = Http::attach(
                    'file',
                    file_get_contents($file->getRealPath()),
                    $file->getClientOriginalName()
                )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
                    'api_key' => $apiKey,
                    'timestamp' => $timestamp,
                    'signature' => $signature,
                    'folder' => $cloudinaryFolder,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'success' => true,
                        'url' => $data['secure_url'],
                        'filename' => $data['public_id'],
                    ];
                } else {
                    Log::error('Cloudinary upload error response: ' . $response->body());
                }
            } catch (\Exception $e) {
                Log::error('Cloudinary upload exception: ' . $e->getMessage());
            }
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
            throw new \Exception('Failed to upload file: both Cloudinary and local storage fallback failed.');
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

                $response = Http::attach(
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
}
