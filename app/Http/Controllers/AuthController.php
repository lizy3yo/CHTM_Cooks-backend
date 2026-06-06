<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Helpers\JwtHelper;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;
use DB;
use App\Services\EmailService;

class AuthController extends Controller
{
    /**
     * Helper to generate Access and Refresh tokens for a user
     */
    private function generateTokens(User $user)
    {
        $payload = [
            'userId' => (string) $user->id,
            'email' => $user->email,
            'role' => $user->role,
        ];

        // Access token expires in 1h (12h for custodians/instructors)
        $expiresIn = '1h';
        if ($user->role === 'custodian' || $user->role === 'instructor') {
            $expiresIn = '12h';
        }

        $accessToken = JwtHelper::sign($payload, env('JWT_SECRET'), $expiresIn);
        $refreshToken = JwtHelper::sign($payload, env('REFRESH_SECRET'), '7d');

        return [
            'accessToken' => $accessToken,
            'refreshToken' => $refreshToken,
        ];
    }

    /**
     * Create a remember me token
     */
    private function createRememberToken(User $user, Request $request)
    {
        $selector = Str::random(16);
        $validator = Str::random(32);
        
        $plainToken = $selector . ':' . $validator;
        $tokenHash = Hash::make($validator);
        $expiresAt = Carbon::now()->addDays(30);

        // Store remember token
        DB::table('remember_tokens')->insert([
            'user_id' => $user->id,
            'token_hash' => $tokenHash,
            'selector' => $selector,
            'device_fingerprint' => md5($request->header('User-Agent', '')),
            'device_name' => $request->header('User-Agent', 'Unknown Device'),
            'ip_address' => $request->ip(),
            'last_used_ip' => $request->ip(),
            'expires_at' => $expiresAt,
            'last_used_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return [
            'plainToken' => $plainToken,
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    /**
     * Login User
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
            'rememberMe' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid inputs', 'details' => $validator->errors()], 400);
        }

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$user->is_active) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Check password
        if (!Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Email verification for students
        if ($user->role === 'student' && !$user->email_verified) {
            return response()->json(['error' => 'Email not verified'], 401);
        }

        // Update last login
        $user->last_login = Carbon::now();
        $user->save();

        // Generate JWTs
        $tokens = $this->generateTokens($user);

        $response = [
            'success' => true,
            'user' => [
                'id' => (string) $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'profilePhotoUrl' => $user->profile_photo_url,
                'isActive' => $user->is_active,
                'createdAt' => $user->created_at->toIso8601String(),
            ]
        ];

        // Merge student fields if student
        if ($user->role === 'student') {
            $response['user']['yearLevel'] = $user->year_level;
            $response['user']['block'] = $user->block;
            $response['user']['agreedToTerms'] = $user->agreed_to_terms;
            $response['user']['trustScore'] = $user->trust_score;
        }

        $response['accessToken'] = $tokens['accessToken'];
        $response['refreshToken'] = $tokens['refreshToken'];

        // If rememberMe, generate remember_me token
        if ($request->rememberMe) {
            $rememberToken = $this->createRememberToken($user, $request);
            $response['rememberToken'] = $rememberToken;
        }

        return response()->json($response);
    }

    /**
     * Register User (Student)
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'role' => 'required|in:student,custodian,instructor,superadmin',
            'yearLevel' => 'required_if:role,student|string|nullable',
            'block' => 'required_if:role,student|string|nullable',
            'agreedToTerms' => 'required_if:role,student|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $email = strtolower(trim($request->email));

        // Create verification token for student
        $emailVerificationToken = null;
        $emailVerificationExpires = null;
        $emailVerified = false;

        if ($request->role === 'student') {
            $emailVerificationToken = Str::random(40);
            $emailVerificationExpires = Carbon::now()->addHours(24);
        } else {
            // Staff are pre-verified when created (typically by admin, but we support self-registration in local)
            $emailVerified = true;
        }

        $user = User::create([
            'email' => $email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'first_name' => $request->firstName,
            'last_name' => $request->lastName,
            'is_active' => true,
            'email_verified' => $emailVerified,
            'email_verification_token' => $emailVerificationToken,
            'email_verification_expires' => $emailVerificationExpires,
            'year_level' => $request->yearLevel,
            'block' => $request->block,
            'agreed_to_terms' => $request->agreedToTerms ?? false,
            'trust_score' => $request->role === 'student' ? 100 : null,
        ]);

        // Send verification email to student
        if ($user->role === 'student' && $emailVerificationToken) {
            EmailService::sendVerificationEmail($email, $user->first_name, $emailVerificationToken);
        }

        return response()->json([
            'success' => true,
            'message' => $user->role === 'student' ? 'Registration successful! Please check your email to verify your account.' : 'Registration successful!',
            'requiresEmailVerification' => ($user->role === 'student'),
            'user' => [
                'id' => (string) $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
            ]
        ], 201);
    }

    /**
     * Get Current User Details
     */
    public function me(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $userResponse = [
            'id' => (string) $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'profilePhotoUrl' => $user->profile_photo_url,
            'isActive' => $user->is_active,
            'createdAt' => $user->created_at->toIso8601String(),
        ];

        if ($user->role === 'student') {
            $userResponse['yearLevel'] = $user->year_level;
            $userResponse['block'] = $user->block;
            $userResponse['agreedToTerms'] = $user->agreed_to_terms;
            $userResponse['trustScore'] = $user->trust_score;
        }

        return response()->json(['user' => $userResponse]);
    }

    /**
     * Refresh JWT Token
     */
    public function refresh(Request $request)
    {
        // Refresh token is forwarded in Authorization header (as SvelteKit proxy does) or in request body
        $token = $request->bearerToken();
        if (!$token) {
            $token = $request->input('refreshToken');
        }

        if (!$token) {
            return response()->json(['error' => 'Refresh token missing'], 400);
        }

        $secret = env('REFRESH_SECRET');
        $payload = JwtHelper::verify($token, $secret);

        if (!$payload) {
            return response()->json(['error' => 'Invalid or expired refresh token'], 401);
        }

        $user = User::find($payload['userId']);
        if (!$user || !$user->is_active) {
            return response()->json(['error' => 'User account is inactive or not found'], 401);
        }

        // Generate new access and refresh tokens
        $tokens = $this->generateTokens($user);

        return response()->json([
            'success' => true,
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'user' => [
                'id' => (string) $user->id,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }

    /**
     * Auto Login using Remember Me cookie
     */
    public function autoLogin(Request $request)
    {
        // Cookie is sent as remember_me cookie in request cookies
        $rememberTokenStr = $request->cookie('remember_me');
        if (!$rememberTokenStr) {
            $rememberTokenStr = $request->header('X-Remember-Me');
        }

        if (!$rememberTokenStr) {
            return response()->json(['error' => 'Remember token missing'], 400);
        }

        $parts = explode(':', $rememberTokenStr);
        if (count($parts) !== 2) {
            return response()->json(['error' => 'Invalid remember token format'], 400);
        }

        list($selector, $validator) = $parts;

        $tokenDoc = DB::table('remember_tokens')
            ->where('selector', $selector)
            ->where('is_revoked', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$tokenDoc) {
            return response()->json(['error' => 'Remember token invalid or expired'], 401);
        }

        if (!Hash::check($validator, $tokenDoc->token_hash)) {
            // Potential theft attempt! Revoke all tokens for this user
            DB::table('remember_tokens')->where('user_id', $tokenDoc->user_id)->update(['is_revoked' => true]);
            return response()->json(['error' => 'Security breach detected: sessions revoked'], 401);
        }

        $user = User::find($tokenDoc->user_id);
        if (!$user || !$user->is_active) {
            return response()->json(['error' => 'Account inactive or user not found'], 401);
        }

        // Rotate token: delete old one, create new one
        DB::table('remember_tokens')->where('id', $tokenDoc->id)->delete();
        $newRememberToken = $this->createRememberToken($user, $request);

        // Generate JWT tokens
        $tokens = $this->generateTokens($user);

        $userResponse = [
            'id' => (string) $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
        ];

        return response()->json([
            'success' => true,
            'user' => $userResponse,
            'accessToken' => $tokens['accessToken'],
            'refreshToken' => $tokens['refreshToken'],
            'rememberToken' => $newRememberToken
        ]);
    }

    /**
     * Verify Student Email
     */
    public function verifyEmail(Request $request)
    {
        $token = $request->query('token');
        if (!$token) {
            return response()->json(['error' => 'Verification token missing'], 400);
        }

        $user = User::where('email_verification_token', $token)
            ->where('email_verification_expires', '>', Carbon::now())
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid or expired verification token'], 400);
        }

        $user->email_verified = true;
        $user->email_verification_token = null;
        $user->email_verification_expires = null;
        $user->save();

        EmailService::sendVerificationSuccessEmail($user->email, $user->first_name);

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully! You can now log in.'
        ]);
    }

    /**
     * Resend verification email
     */
    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($request->email));

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        if ($user->email_verified) {
            return response()->json(['error' => 'Email is already verified'], 400);
        }

        $token = Str::random(40);
        $user->email_verification_token = $token;
        $user->email_verification_expires = Carbon::now()->addHours(24);
        $user->save();

        $sent = EmailService::sendVerificationEmail($email, $user->first_name, $token);
        if (!$sent) {
            if (config('app.env') === 'local') {
                return response()->json([
                    'message' => 'Verification email resent (Simulated in local logs)!',
                    'debug_token' => $token
                ]);
            }
            return response()->json(['error' => 'Failed to send email'], 500);
        }

        return response()->json(['message' => 'Verification email resent successfully! Check your inbox.']);
    }

    /**
     * Forgot password
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($request->email));

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $token = Str::random(40);
        $user->password_reset_token = $token;
        $user->password_reset_expires = Carbon::now()->addHours(1);
        $user->save();

        $sent = EmailService::sendPasswordResetEmail($email, $user->first_name, $token);
        if (!$sent) {
            if (config('app.env') === 'local') {
                return response()->json([
                    'message' => 'Password reset email sent (Simulated in local logs)!',
                    'debug_token' => $token
                ]);
            }
            return response()->json(['error' => 'Failed to send email'], 500);
        }

        return response()->json(['message' => 'Password reset email sent! Check your inbox.']);
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'password' => 'required|min:8'
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'details' => $validator->errors()], 400);
        }

        $user = User::where('password_reset_token', $request->token)
            ->where('password_reset_expires', '>', Carbon::now())
            ->first();

        if (!$user) {
            return response()->json(['error' => 'Invalid or expired reset token'], 400);
        }

        $user->password = Hash::make($request->password);
        $user->password_reset_token = null;
        $user->password_reset_expires = null;
        $user->save();

        EmailService::sendPasswordResetSuccessEmail($user->email, $user->first_name);

        return response()->json(['message' => 'Password has been reset successfully! You can now log in.']);
    }

    /**
     * Logout User
     */
    public function logout(Request $request)
    {
        $user = Auth::user();
        
        // Revoke the remember token from database if it's sent
        $rememberTokenStr = $request->cookie('remember_me');
        if ($rememberTokenStr) {
            $parts = explode(':', $rememberTokenStr);
            if (count($parts) === 2) {
                DB::table('remember_tokens')->where('selector', $parts[0])->delete();
            }
        }

        // Revoke remember tokens for user if requested
        if ($user && $request->input('userId')) {
            DB::table('remember_tokens')->where('user_id', $user->id)->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }
}
