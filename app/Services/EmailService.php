<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EmailService
{
    private static function getAppUrl(): string
    {
        return rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');
    }

    /**
     * Base email template wrapper with consistent CHTM brand styling.
     */
    private static function emailTemplate(string $content): string
    {
        $logoUrl = 'https://res.cloudinary.com/dqvhbvqnw/image/upload/v1775488521/CHTM_LOGO_zkdl8h.png';
        $currentYear = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CHTM Cooks</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f4f5f8;
            color: #1f2937;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        .header {
            background: linear-gradient(135deg, #be185d 0%, #ec4899 100%);
            padding: 28px 20px;
            text-align: center;
        }
        .header img {
            width: 52px;
            height: 52px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            letter-spacing: 0.01em;
        }
        .content {
            padding: 34px 30px;
        }
        .content h2 {
            color: #111827;
            font-size: 26px;
            margin: 0 0 16px 0;
            font-weight: 600;
        }
        .content p {
            color: #475569;
            font-size: 16px;
            line-height: 1.6;
            margin: 0 0 14px 0;
        }
        .button {
            display: inline-block;
            padding: 14px 32px;
            background: linear-gradient(135deg, #be185d 0%, #ec4899 100%);
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s ease;
        }
        .button:hover {
            transform: translateY(-2px);
        }
        .token-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin: 20px 0;
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            word-break: break-all;
        }
        .meta-card {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 14px;
            margin: 18px 0;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
        }
        .meta-table td {
            padding: 6px 0;
            vertical-align: middle;
        }
        .meta-table td:last-child {
            text-align: right;
        }
        .meta-label {
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 11px;
        }
        .meta-value {
            color: #0f172a;
            font-weight: 700;
        }
        .reference-number {
            color: #ec4899;
            font-weight: 700;
            font-size: 18px;
            letter-spacing: 0.02em;
        }
        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 999px;
            background: #fce7f3;
            color: #be185d;
            font-weight: 700;
            font-size: 12px;
        }
        .qr-wrap {
            margin: 22px 0;
            padding: 16px;
            border: 1px solid #fbcfe8;
            border-radius: 12px;
            background: #fdf2f8;
            text-align: center;
        }
        .qr-wrap img {
            width: 180px;
            height: 180px;
            display: block;
            margin: 8px auto 12px auto;
            border-radius: 8px;
            background: #ffffff;
            padding: 8px;
            border: 1px solid #f9a8d4;
        }
        .qr-caption {
            margin: 0;
            font-size: 13px;
            color: #6b7280;
            line-height: 1.5;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 24px;
            text-align: center;
            border-top: 1px solid #dee2e6;
        }
        .footer p {
            color: #64748b;
            font-size: 13px;
            margin: 4px 0;
        }
        .warning {
            background-color: #fff7ed;
            border-left: 4px solid #fb923c;
            padding: 12px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .warning p {
            color: #9a3412;
            margin: 0;
            font-size: 14px;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 30px 20px;
            }
            .header h1 {
                font-size: 24px;
            }
            .content h2 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="{$logoUrl}" alt="CHTM Cooks" />
            <h1>CHTM Cooks</h1>
        </div>
        {$content}
        <div class="footer">
            <p>© {$currentYear} CHTM Cooks. All rights reserved.</p>
            <p>Gordon College - College of Hospitality and Tourism Management</p>
            <p>This is an automated email, please do not reply.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Send email verification link.
     */
    public static function sendVerificationEmail(string $email, string $firstName, string $token): bool
    {
        try {
            $verificationUrl = self::getAppUrl() . '/auth/verify-email?token=' . $token;
            
            $content = <<<HTML
<div class="content">
    <h2>Welcome to CHTM Cooks! 🎉</h2>
    <p>Hi <strong>{$firstName}</strong>,</p>
    <p>Thank you for registering with CHTM Cooks. We're excited to have you join our culinary community!</p>
    <p>To complete your registration and activate your account, please verify your email address by clicking the button below:</p>
    
    <center>
        <a href="{$verificationUrl}" class="button" style="color: #ffffff !important;">Verify Email Address</a>
    </center>
    
    <p>If the button doesn't work, you can also copy and paste this link into your browser:</p>
    <div class="token-box">{$verificationUrl}</div>
    
    <div class="warning">
        <p><strong>⏰ Important:</strong> This verification link will expire in 24 hours.</p>
    </div>
    
    <p>If you didn't create an account with CHTM Cooks, please ignore this email.</p>
    
    <p>Best regards,<br>The CHTM Cooks Team</p>
</div>
HTML;

            $html = self::emailTemplate($content);

            Mail::html($html, function ($message) use ($email) {
                $message->to($email)->subject('Verify Your CHTM Cooks Account');
            });

            Log::info("Verification email sent to: {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send verification email to {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send email verification success notification.
     */
    public static function sendVerificationSuccessEmail(string $email, string $firstName): bool
    {
        try {
            $loginUrl = self::getAppUrl() . '/auth/login';
            
            $content = <<<HTML
<div class="content">
    <h2>Email Verified Successfully! ✅</h2>
    <p>Hi <strong>{$firstName}</strong>,</p>
    <p>Great news! Your email address has been successfully verified.</p>
    <p>You now have full access to all CHTM Cooks features. Start exploring and enjoy your culinary journey!</p>
    
    <center>
        <a href="{$loginUrl}" class="button" style="color: #ffffff !important;">Get Started</a>
    </center>
    
    <p>Thank you for being part of our community!</p>
    
    <p>Best regards,<br>The CHTM Cooks Team</p>
</div>
HTML;

            $html = self::emailTemplate($content);

            Mail::html($html, function ($message) use ($email) {
                $message->to($email)->subject('Email Verified - Welcome to CHTM Cooks! 🎉');
            });

            Log::info("Verification success email sent to: {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send verification success email to {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send password reset token email.
     */
    public static function sendPasswordResetEmail(string $email, string $firstName, string $token): bool
    {
        try {
            $resetUrl = self::getAppUrl() . '/auth/reset-password?token=' . $token;
            
            $content = <<<HTML
<div class="content">
    <h2>Password Reset Request 🔐</h2>
    <p>Hi <strong>{$firstName}</strong>,</p>
    <p>We received a request to reset the password for your CHTM Cooks account.</p>
    <p>Click the button below to reset your password:</p>
    
    <center>
        <a href="{$resetUrl}" class="button" style="color: #ffffff !important;">Reset Password</a>
    </center>
    
    <p>If the button doesn't work, you can also copy and paste this link into your browser:</p>
    <div class="token-box">{$resetUrl}</div>
    
    <div class="warning">
        <p><strong>⏰ Important:</strong> This password reset link will expire in 1 hour for security reasons.</p>
    </div>
    
    <p><strong>Security Notice:</strong> If you didn't request a password reset, please ignore this email. Your password will remain unchanged.</p>
    
    <p>Best regards,<br>The CHTM Cooks Team</p>
</div>
HTML;

            $html = self::emailTemplate($content);

            Mail::html($html, function ($message) use ($email) {
                $message->to($email)->subject('Reset Your CHTM Cooks Password');
            });

            Log::info("Password reset email sent to: {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send password reset email to {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send password reset success confirmation email.
     */
    public static function sendPasswordResetSuccessEmail(string $email, string $firstName): bool
    {
        try {
            $loginUrl = self::getAppUrl() . '/auth/login';
            
            $content = <<<HTML
<div class="content">
    <h2>Password Successfully Reset ✅</h2>
    <p>Hi <strong>{$firstName}</strong>,</p>
    <p>Your password has been successfully reset. You can now log in to your CHTM Cooks account with your new password.</p>
    
    <center>
        <a href="{$loginUrl}" class="button" style="color: #ffffff !important;">Log In Now</a>
    </center>
    
    <div class="warning">
        <p><strong>🔒 Security Alert:</strong> If you didn't make this change, please contact our support team immediately.</p>
    </div>
    
    <p>Best regards,<br>The CHTM Cooks Team</p>
</div>
HTML;

            $html = self::emailTemplate($content);

            Mail::html($html, function ($message) use ($email) {
                $message->to($email)->subject('Password Successfully Reset ✅');
            });

            Log::info("Password reset success email sent to: {$email}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send password reset success email to {$email}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send Borrow Request Lifecycle Email updates.
     */
    public static function sendBorrowRequestLifecycleEmail(array $payload): bool
    {
        try {
            $to = $payload['to'];
            $firstName = $payload['firstName'];
            $title = $payload['title'];
            $summary = $payload['summary'];
            $requestCode = $payload['requestCode'];
            $statusLabel = $payload['statusLabel'];
            $roleLabel = $payload['roleLabel'];
            $ctaPath = $payload['ctaPath'];
            $items = $payload['items'];
            $notes = $payload['notes'] ?? null;
            $qrRawValue = $payload['qrRawValue'] ?? null;
            $qrCaption = $payload['qrCaption'] ?? 'Present this QR code to the custodian when processing your request.';

            $viewUrl = self::getAppUrl() . $ctaPath;
            
            $notesMarkup = '';
            if ($notes) {
                $notesMarkup = <<<HTML
<div class="warning">
    <p><strong>Details:</strong> {$notes}</p>
</div>
HTML;
            }

            $qrMarkup = '';
            if ($qrRawValue) {
                $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=10&data=' . urlencode($qrRawValue);
                $qrMarkup = <<<HTML
<div class="qr-wrap">
    <p style="margin: 0 0 8px 0; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; color: #9d174d;">Request QR Code</p>
    <img src="{$qrUrl}" alt="Borrow Request QR Code" />
    <p class="qr-caption">{$qrCaption}</p>
</div>
HTML;
            }

            $itemsMarkup = '';
            foreach ($items as $item) {
                $itemsMarkup .= "<li style=\"margin-bottom: 8px; color: #666666;\">{$item}</li>";
            }

            $content = <<<HTML
<div class="content">
    <h2>{$title}</h2>
    <p>Hi <strong>{$firstName}</strong>,</p>
    <p>{$summary}</p>

    <div class="meta-card">
        <table role="presentation" class="meta-table">
            <tr>
                <td><span class="meta-label">Reference Number</span></td>
                <td><span class="reference-number">{$requestCode}</span></td>
            </tr>
            <tr>
                <td><span class="meta-label">Status</span></td>
                <td><span class="badge">{$statusLabel}</span></td>
            </tr>
            <tr>
                <td><span class="meta-label">Portal</span></td>
                <td><span class="meta-value">{$roleLabel}</span></td>
            </tr>
        </table>
    </div>

    <p><strong>Items in this request:</strong></p>
    <ul style="padding-left: 20px; margin: 0 0 20px 0;">
        {$itemsMarkup}
    </ul>

    {$notesMarkup}
    {$qrMarkup}

    <center>
        <a href="{$viewUrl}" class="button" style="color: #ffffff !important;">Open Request</a>
    </center>

    <p>If the button doesn't work, copy this URL into your browser:</p>
    <div class="token-box">{$viewUrl}</div>

    <p>Best regards,<br>The CHTM Cooks Team</p>
</div>
HTML;

            $html = self::emailTemplate($content);

            Mail::html($html, function ($message) use ($to, $title) {
                $message->to($to)->subject("CHTM Cooks Update: {$title}");
            });

            Log::info("Lifecycle email sent to: {$to} with subject: CHTM Cooks Update: {$title}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to send lifecycle email: " . $e->getMessage());
            return false;
        }
    }
}
