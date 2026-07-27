<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Models\AuthToken;
use App\Application\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;

class TokenService
{
    /** @return array<string, int|string> */
    public function issuePair(
        User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $familyId = null,
    ): array {
        $now = time();
        $accessTtl = max(300, min(3600, (int) env('JWT_TIME', 900)));
        $refreshTtl = max(86400, min(7776000, (int) env('REFRESH_TOKEN_TIME', 2592000)));
        $jti = bin2hex(random_bytes(16));
        $familyId ??= bin2hex(random_bytes(16));

        $accessToken = JWT::encode([
            'iss' => env('JWT_ISS', 'localhost'),
            'sub' => $user->id,
            'iat' => $now,
            'exp' => $now + $accessTtl,
            'jti' => $jti,
            'typ' => AuthToken::ACCESS,
            'ver' => $user->auth_version,
        ], (string) env('JWT_SECRET'), 'HS256');

        $this->storeToken(
            $user,
            AuthToken::ACCESS,
            hash('sha256', $jti),
            Carbon::createFromTimestampUTC($now + $accessTtl),
            $familyId,
            $ipAddress,
            $userAgent,
        );

        $rawRefreshToken = $this->randomToken();
        $this->storeToken(
            $user,
            AuthToken::REFRESH,
            hash('sha256', $rawRefreshToken),
            Carbon::createFromTimestampUTC($now + $refreshTtl),
            $familyId,
            $ipAddress,
            $userAgent,
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $rawRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl,
            'refresh_expires_in' => $refreshTtl,
        ];
    }

    /** @return array<string, int|string>|null */
    public function rotateRefreshToken(
        string $rawToken,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ?array {
        $candidate = AuthToken::query()
            ->where('type', AuthToken::REFRESH)
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (!$candidate instanceof AuthToken) {
            return null;
        }

        return $candidate->getConnection()->transaction(function () use (
            $candidate,
            $ipAddress,
            $userAgent,
        ): ?array {
            $token = AuthToken::query()->where('id', $candidate->id)->lockForUpdate()->first();
            if (!$token instanceof AuthToken) {
                return null;
            }

            if (!$token->isActive()) {
                if ($token->used_at !== null || $token->revoked_at !== null) {
                    $hasActiveFamilyTokens = AuthToken::query()
                        ->where('family_id', $token->family_id)
                        ->whereNull('revoked_at')
                        ->exists();

                    if ($hasActiveFamilyTokens) {
                        $this->revokeFamily((string) $token->family_id);
                        $user = User::find($token->user_id);
                        if ($user) {
                            $this->revokeAll($user);
                        }
                    }
                }

                return null;
            }

            $user = User::find($token->user_id);
            if (!$user || $user->status !== User::ACTIVE || $user->email_verified_at === null) {
                $token->revoked_at = Carbon::now();
                $token->save();
                return null;
            }

            $token->used_at = Carbon::now();
            $token->save();

            return $this->issuePair($user, $ipAddress, $userAgent, $token->family_id);
        });
    }

    public function isAccessTokenActive(string $jti, User $user): bool
    {
        $token = AuthToken::query()
            ->where('user_id', $user->id)
            ->where('type', AuthToken::ACCESS)
            ->where('token_hash', hash('sha256', $jti))
            ->first();

        return $token instanceof AuthToken && $token->isActive();
    }

    public function revokeAccessToken(string $jti): void
    {
        AuthToken::query()
            ->where('type', AuthToken::ACCESS)
            ->where('token_hash', hash('sha256', $jti))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    public function revokeRefreshToken(string $rawToken): void
    {
        AuthToken::query()
            ->where('type', AuthToken::REFRESH)
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    public function revokeAll(User $user): void
    {
        AuthToken::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);

        $user->auth_version++;
        $user->save();
    }

    public function createPurposeToken(User $user, string $type, int $ttlSeconds): string
    {
        AuthToken::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);

        $rawToken = $this->randomToken();
        $this->storeToken(
            $user,
            $type,
            hash('sha256', $rawToken),
            Carbon::now()->addSeconds($ttlSeconds),
        );

        return $rawToken;
    }

    public function consumePurposeToken(string $rawToken, string $type): ?User
    {
        $token = AuthToken::query()
            ->where('type', $type)
            ->where('token_hash', hash('sha256', $rawToken))
            ->first();

        if (!$token instanceof AuthToken || !$token->isActive()) {
            return null;
        }

        $user = User::find($token->user_id);
        if (!$user) {
            return null;
        }

        $token->used_at = Carbon::now();
        $token->save();

        return $user;
    }

    private function revokeFamily(string $familyId): void
    {
        if ($familyId === '') {
            return;
        }

        AuthToken::query()
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    private function storeToken(
        User $user,
        string $type,
        string $tokenHash,
        Carbon $expiresAt,
        ?string $familyId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuthToken {
        $token = new AuthToken();
        $token->user_id = $user->id;
        $token->type = $type;
        $token->token_hash = $tokenHash;
        $token->family_id = $familyId;
        $token->expires_at = $expiresAt;
        $token->created_ip = $ipAddress;
        $token->user_agent = $userAgent !== null ? substr($userAgent, 0, 255) : null;
        $token->save();

        return $token;
    }

    private function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }
}
