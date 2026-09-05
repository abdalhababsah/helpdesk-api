<?php

namespace App\Enums;

/**
 * Why a refresh token stopped being usable. Kept for forensics: distinguishing
 * a normal rotation from a detected replay is the difference between routine
 * traffic and a leaked token.
 */
enum RefreshRevokeReason: string
{
    case Rotated = 'rotated';
    case Logout = 'logout';
    case LogoutAll = 'logout_all';
    case ReuseDetected = 'reuse_detected';
    case UserDeactivated = 'user_deactivated';
    case RoleChanged = 'role_changed';
    case Expired = 'expired';
    case PasswordReset = 'password_reset';
    case UserDeleted = 'user_deleted';
}
