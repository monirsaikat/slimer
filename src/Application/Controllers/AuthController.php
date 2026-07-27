<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Models\AuthToken;
use App\Application\Models\User;
use App\Application\Services\EmailService;
use App\Application\Services\TokenService;
use App\Application\Services\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController extends Controller
{
    public function __construct(
        private TokenService $tokens,
        private EmailService $emails,
        private AuditService $audit,
    ) {
    }

    public function register(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'first_name' => [
                'required', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'last_name' => [
                'required', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:100', 'unique:users,email'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $user = new User();
        $user->first_name = trim($data['first_name']);
        $user->last_name = trim($data['last_name']);
        $user->email = strtolower(trim($data['email']));
        $user->password = $data['password'];
        $user->auth_version = 0;
        $user->save();

        $verificationToken = $this->tokens->createPurposeToken(
            $user,
            AuthToken::EMAIL_VERIFICATION,
            86400,
        );
        $emailSent = $this->emails->sendEmailVerification($user, $verificationToken);
        $this->audit->record(
            $request,
            'auth.registered',
            User::class,
            $user->id,
            null,
            $user->toArray(),
            'A new account was registered.',
            $user,
        );

        $payload = [
            'message' => 'Registration successful. Verify your email before signing in.',
            'email_sent' => $emailSent,
            'user' => $user,
        ];
        $this->includeLocalToken($payload, 'verification_token', $verificationToken);

        return $this->respondWithData($payload, 201);
    }

    public function login(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:100'],
            'password' => ['required', 'string', 'max:72'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        if (!$user instanceof User || !Hash::check($data['password'], $user->password)) {
            $this->audit->record(
                $request,
                'auth.login_failed',
                User::class,
                $user?->id,
                null,
                ['email' => strtolower(trim($data['email']))],
                'Invalid login credentials were supplied.',
                $user,
            );
            return $this->respondWithError('Invalid email or password', 401);
        }

        if ($user->status !== User::ACTIVE) {
            $this->audit->record(
                $request,
                'auth.login_blocked',
                User::class,
                $user->id,
                null,
                ['status' => $user->status],
                'Login was blocked for a non-active account.',
                $user,
            );
            return $this->respondWithError('This account is not active', 403);
        }

        if ($user->email_verified_at === null) {
            $verificationToken = $this->tokens->createPurposeToken(
                $user,
                AuthToken::EMAIL_VERIFICATION,
                86400,
            );
            $payload = [
                'error' => 'Email verification is required',
                'email_sent' => $this->emails->sendEmailVerification($user, $verificationToken),
            ];
            $this->includeLocalToken($payload, 'verification_token', $verificationToken);
            $this->audit->record(
                $request,
                'auth.verification_required',
                User::class,
                $user->id,
                null,
                null,
                'Login required email verification.',
                $user,
            );

            return $this->respondWithData($payload, 403);
        }

        $user->last_login_at = Carbon::now();
        $user->save();
        $this->audit->record(
            $request,
            'auth.login_succeeded',
            User::class,
            $user->id,
            null,
            ['last_login_at' => $user->last_login_at],
            'User signed in successfully.',
            $user,
        );

        return $this->respondWithData(array_merge(
            ['user' => $user],
            $this->tokens->issuePair($user, $this->ip($request), $this->userAgent($request)),
        ));
    }

    public function refresh(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'refresh_token' => ['required', 'string', 'min:80', 'max:200'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $tokens = $this->tokens->rotateRefreshToken(
            $data['refresh_token'],
            $this->ip($request),
            $this->userAgent($request),
        );

        $this->audit->record(
            $request,
            $tokens === null ? 'auth.refresh_failed' : 'auth.token_refreshed',
            AuthToken::class,
            null,
            null,
            null,
            $tokens === null ? 'Refresh token rotation failed.' : 'Refresh token rotated successfully.',
        );

        return $tokens === null
            ? $this->respondWithError('The refresh token is invalid or expired', 401)
            : $this->respondWithData($tokens);
    }

    public function logout(Request $request): Response
    {
        $body = $request->getParsedBody();
        $data = $this->validateData(is_array($body) ? $body : [], [
            'refresh_token' => ['sometimes', 'string', 'min:80', 'max:200'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $jwt = $request->getAttribute('jwt');
        if (is_object($jwt) && isset($jwt->jti)) {
            $this->tokens->revokeAccessToken((string) $jwt->jti);
        }
        if (isset($data['refresh_token'])) {
            $this->tokens->revokeRefreshToken($data['refresh_token']);
        }
        $this->audit->record($request, 'auth.logged_out', User::class, $request->getAttribute('user')->id);

        return $this->respondWithMessage('Logged out successfully');
    }

    public function logoutAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->getAttribute('user');
        $this->tokens->revokeAll($user);
        $this->audit->record(
            $request,
            'auth.logged_out_all',
            User::class,
            $user->id,
            null,
            ['auth_version' => $user->auth_version],
        );

        return $this->respondWithMessage('Logged out from all devices successfully');
    }

    public function forgotPassword(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:100'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $payload = [
            'message' => 'If that email exists, a password reset link has been sent.',
        ];
        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        if ($user instanceof User && $user->status === User::ACTIVE) {
            $resetToken = $this->tokens->createPurposeToken($user, AuthToken::PASSWORD_RESET, 3600);
            $this->emails->sendPasswordReset($user, $resetToken);
            $this->includeLocalToken($payload, 'reset_token', $resetToken);
        }
        $this->audit->record(
            $request,
            'auth.password_reset_requested',
            User::class,
            $user instanceof User ? $user->id : null,
            null,
            ['email' => strtolower(trim($data['email']))],
        );

        return $this->respondWithData($payload, 202);
    }

    public function resetPassword(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'token' => ['required', 'string', 'min:80', 'max:200'],
            'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $user = $this->tokens->consumePurposeToken($data['token'], AuthToken::PASSWORD_RESET);
        if (!$user) {
            return $this->respondWithError('The password reset token is invalid or expired', 400);
        }

        $user->password = $data['password'];
        $user->save();
        $this->tokens->revokeAll($user);
        $this->audit->record(
            $request,
            'auth.password_reset_completed',
            User::class,
            $user->id,
            null,
            ['auth_version' => $user->auth_version],
            'Password was reset and all sessions were revoked.',
            $user,
        );

        return $this->respondWithMessage('Password reset successfully');
    }

    public function verifyEmail(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'token' => ['required', 'string', 'min:80', 'max:200'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $user = $this->tokens->consumePurposeToken($data['token'], AuthToken::EMAIL_VERIFICATION);
        if (!$user) {
            return $this->respondWithError('The verification token is invalid or expired', 400);
        }

        $user->email_verified_at = Carbon::now();
        $user->save();
        $this->audit->record(
            $request,
            'auth.email_verified',
            User::class,
            $user->id,
            null,
            ['email_verified_at' => $user->email_verified_at],
            'Email address was verified.',
            $user,
        );

        return $this->respondWithData(array_merge(
            ['message' => 'Email verified successfully', 'user' => $user],
            $this->tokens->issuePair($user, $this->ip($request), $this->userAgent($request)),
        ));
    }

    public function resendVerification(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'email' => ['required', 'string', 'email:rfc', 'max:100'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $payload = [
            'message' => 'If the account exists and is unverified, a verification email has been sent.',
        ];
        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        if ($user instanceof User && $user->email_verified_at === null && $user->status === User::ACTIVE) {
            $token = $this->tokens->createPurposeToken($user, AuthToken::EMAIL_VERIFICATION, 86400);
            $this->emails->sendEmailVerification($user, $token);
            $this->includeLocalToken($payload, 'verification_token', $token);
        }
        $this->audit->record(
            $request,
            'auth.verification_resent',
            User::class,
            $user instanceof User ? $user->id : null,
            null,
            ['email' => strtolower(trim($data['email']))],
        );

        return $this->respondWithData($payload, 202);
    }

    /** @param array<string, mixed> $payload */
    private function includeLocalToken(array &$payload, string $key, string $token): void
    {
        if (env('APP_ENV', 'production') === 'local') {
            $payload[$key] = $token;
        }
    }

    private function ip(Request $request): ?string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        return is_string($ip) ? substr($ip, 0, 45) : null;
    }

    private function userAgent(Request $request): ?string
    {
        $userAgent = trim($request->getHeaderLine('User-Agent'));
        return $userAgent !== '' ? $userAgent : null;
    }
}
