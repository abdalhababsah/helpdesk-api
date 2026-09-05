<?php

namespace App\Http\Controllers;

use App\Actions\Passwords\RequestPasswordReset;
use App\Actions\Passwords\ResetPassword;
use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

final class PasswordResetController extends Controller
{
    /** Always 202. The body is identical whether or not the address is known. */
    public function forgot(ForgotPasswordRequest $request, RequestPasswordReset $action): JsonResponse
    {
        $action->handle($request->string('email')->toString());

        return response()->json(['data' => ['message' => 'If that address has an account, a reset link is on its way.']], 202);
    }

    public function reset(ResetPasswordRequest $request, ResetPassword $action): JsonResponse
    {
        $action->handle(
            $request->string('token')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        return response()->json(null, 204);
    }
}
