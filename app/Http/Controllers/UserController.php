<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Carbon\Carbon;

class UserController extends Controller
{
    /**
     * @param \App\Models\User $u
     * @return array
     */
    private function transformUser($u)
    {
        return [
            'id' => (string) $u->id,
            'email' => $u->email,
            'role' => $u->role,
            'firstName' => $u->first_name,
            'lastName' => $u->last_name,
            'profilePhotoUrl' => $u->profile_photo_url,
            'isActive' => (bool) $u->is_active,
            'emailVerified' => (bool) $u->email_verified,
            'createdAt' => $u->created_at->toIso8601String(),
            'lastLogin' => $u->last_login ? $u->last_login->toIso8601String() : null,
            'yearLevel' => $u->year_level,
            'block' => $u->block
        ];
    }

    // ==========================================
    // USER MANAGEMENT CRUD (SUPERADMIN)
    // ==========================================

    public function getAll(Request $request)
    {
        $query = User::query();

        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('email', 'like', $search)
                  ->orWhere('first_name', 'like', $search)
                  ->orWhere('last_name', 'like', $search);
            });
        }

        $total = $query->count();
        $limit = $request->integer('limit', 50);
        $page = $request->integer('page', 1);
        $totalPages = max(1, ceil($total / $limit));

        $users = $query->orderBy('first_name')
                       ->skip(($page - 1) * $limit)
                       ->take($limit)
                       ->get();

        return response()->json([
            'users' => $users->map(fn(User $u) => $this->transformUser($u)),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'totalPages' => $totalPages
            ]
        ]);
    }

    public function getById($id)
    {
        $u = User::find($id);
        if (!$u) {
            return response()->json(['error' => 'User not found'], 404);
        }
        return response()->json([
            'user' => $this->transformUser($u)
        ]);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|in:student,custodian,instructor,superadmin',
            'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'yearLevel' => 'nullable|string',
            'block' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = User::create([
            'email' => strtolower(trim($request->email)),
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'first_name' => $request->firstName,
            'last_name' => $request->lastName,
            'is_active' => true,
            'email_verified' => true, // Admin-created accounts are pre-verified
            'year_level' => $request->yearLevel,
            'block' => $request->block,
            'trust_score' => 100
        ]);

        return response()->json([
            'user' => $this->transformUser($user)
        ], 201);
    }

    public function update(Request $request)
    {
        $userId = $request->query('userId');
        if (!$userId) {
            return response()->json(['error' => 'User ID is required'], 400);
        }

        $u = User::find($userId);
        if (!$u) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'firstName' => 'sometimes|required|string|max:100',
            'lastName' => 'sometimes|required|string|max:100',
            'isActive' => 'sometimes|boolean',
            'role' => 'sometimes|required|in:student,custodian,instructor,superadmin',
            'yearLevel' => 'nullable|string',
            'block' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $updateData = [];
        if ($request->has('firstName')) $updateData['first_name'] = $request->firstName;
        if ($request->has('lastName')) $updateData['last_name'] = $request->lastName;
        if ($request->has('isActive')) $updateData['is_active'] = $request->isActive;
        if ($request->has('role')) $updateData['role'] = $request->role;
        if ($request->has('yearLevel')) $updateData['year_level'] = $request->yearLevel;
        if ($request->has('block')) $updateData['block'] = $request->block;

        $u->update($updateData);

        return response()->json([
            'user' => $this->transformUser($u)
        ]);
    }

    public function delete(Request $request)
    {
        $userId = $request->query('userId');
        if (!$userId) {
            return response()->json(['error' => 'User ID is required'], 400);
        }

        $u = User::find($userId);
        if (!$u) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $u->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully'
        ]);
    }

    public function stream()
    {
        return new StreamedResponse(function () {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo "retry: 15000\n";
            echo "event: connected\n";
            echo "data: {}\n\n";
            flush();

            $hasMultipleWorkers = function_exists('pcntl_fork') && getenv('PHP_CLI_SERVER_WORKERS') && intval(getenv('PHP_CLI_SERVER_WORKERS')) > 1;
            if (php_sapi_name() !== 'cli-server' || $hasMultipleWorkers) {
                // Heartbeat
                $start = time();
                while (time() - $start < 30) {
                    echo "event: heartbeat\n";
                    echo "data: {}\n\n";
                    flush();
                    sleep(10);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    // ==========================================
    // PROFILE MANAGEMENT (LOGGED-IN USER)
    // ==========================================

    public function getProfile()
    {
        $user = auth()->user();
        return response()->json([
            'user' => $this->transformUser($user)
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'yearLevel' => 'nullable|string',
            'block' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user->first_name = $request->firstName;
        $user->last_name = $request->lastName;
        $user->year_level = $request->yearLevel;
        $user->block = $request->block;
        $user->save();

        return response()->json([
            'user' => $this->transformUser($user)
        ]);
    }

    public function uploadProfilePhoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|image|max:5120' // Max 5MB
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = auth()->user();
        $file = $request->file('file');

        $cloudName = env('CLOUDINARY_CLOUD_NAME');
        $apiKey = env('CLOUDINARY_API_KEY');
        $apiSecret = env('CLOUDINARY_API_SECRET');
        $folder = 'profiles';

        if ($cloudName && $apiKey && $apiSecret) {
            try {
                // If old photo exists on Cloudinary, delete it first
                if ($user->profile_photo_public_id) {
                    $timestamp = time();
                    $signatureStr = "public_id={$user->profile_photo_public_id}&timestamp={$timestamp}{$apiSecret}";
                    $signature = sha1($signatureStr);

                    Http::post("https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy", [
                        'public_id' => $user->profile_photo_public_id,
                        'api_key' => $apiKey,
                        'timestamp' => $timestamp,
                        'signature' => $signature
                    ]);
                }

                $timestamp = time();
                $params = [
                    'folder' => $folder,
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
                    'folder' => $folder,
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    $user->profile_photo_url = $data['secure_url'];
                    $user->profile_photo_public_id = $data['public_id'];
                    $user->save();

                    return response()->json([
                        'user' => $this->transformUser($user)
                    ]);
                }
            } catch (\Exception $e) {
                \Log::error('Cloudinary photo upload exception: ' . $e->getMessage());
            }
        }

        // Local storage fallback
        $path = $file->store('profiles', 'public');
        $user->profile_photo_url = asset('storage/' . $path);
        $user->profile_photo_public_id = basename($path);
        $user->save();

        return response()->json([
            'user' => $this->transformUser($user)
        ]);
    }

    public function removeProfilePhoto()
    {
        $user = auth()->user();
        
        $cloudName = env('CLOUDINARY_CLOUD_NAME');
        $apiKey = env('CLOUDINARY_API_KEY');
        $apiSecret = env('CLOUDINARY_API_SECRET');

        if ($user->profile_photo_public_id && $cloudName && $apiKey && $apiSecret) {
            try {
                $timestamp = time();
                $signatureStr = "public_id={$user->profile_photo_public_id}&timestamp={$timestamp}{$apiSecret}";
                $signature = sha1($signatureStr);

                Http::post("https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy", [
                    'public_id' => $user->profile_photo_public_id,
                    'api_key' => $apiKey,
                    'timestamp' => $timestamp,
                    'signature' => $signature
                ]);
            } catch (\Exception $e) {
                \Log::error('Cloudinary destroy exception: ' . $e->getMessage());
            }
        }

        $user->profile_photo_url = null;
        $user->profile_photo_public_id = null;
        $user->save();

        return response()->json([
            'user' => $this->transformUser($user)
        ]);
    }

    public function changePassword(Request $request)
    {
        $user = auth()->user();

        $validator = Validator::make($request->all(), [
            'currentPassword' => 'required|string',
            'newPassword' => 'required|string|min:8'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        if (!Hash::check($request->currentPassword, $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 400);
        }

        $user->password = Hash::make($request->newPassword);
        $user->save();

        return response()->json(['success' => true]);
    }

    public function profileStream()
    {
        return new StreamedResponse(function () {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            echo "retry: 15000\n";
            echo "event: connected\n";
            echo "data: {}\n\n";
            flush();

            $hasMultipleWorkers = function_exists('pcntl_fork') && getenv('PHP_CLI_SERVER_WORKERS') && intval(getenv('PHP_CLI_SERVER_WORKERS')) > 1;
            if (php_sapi_name() !== 'cli-server' || $hasMultipleWorkers) {
                // Heartbeat
                $start = time();
                while (time() - $start < 30) {
                    echo ": keepalive\n\n";
                    flush();
                    sleep(10);
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
