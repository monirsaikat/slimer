<?php

namespace App\Application\Controllers;

use App\Application\Models\User;
use App\Application\Services\TokenService;
use App\Application\Services\AuditService;
use Illuminate\Support\Facades\Hash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ProfileController extends Controller
{
    public function __construct(
        private TokenService $tokens,
        private AuditService $audit,
    ) {
    }

    public function show(Request $request): Response
    {
        return $this->respondWithData([
            'user' => $this->authenticatedUser($request),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'first_name'    => ['sometimes', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u"],
            'last_name'     => ['sometimes', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u"],
            'phone_number'  => ['nullable', 'string', 'max:20', 'regex:/^\\+?[0-9 ()-]{7,20}$/'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'gender'        => ['nullable', 'in:male,female,other'],
            'address'       => ['nullable', 'string', 'max:500'],
            'city'          => ['nullable', 'string', 'max:50'],
            'state'         => ['nullable', 'string', 'max:50'],
            'country'       => ['nullable', 'string', 'max:50'],
            'zip_code'      => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9 -]+$/'],
        ]);
        if ($data instanceof Response) {
            return $data;
        }

        if ($data === []) {
            return $this->respondWithValidationError([
                'body' => ['Provide at least one profile field to update.'],
            ]);
        }

        $user = $this->authenticatedUser($request);
        $oldValues = $user->toArray();
        foreach ($data as $field => $value) {
            $user->{$field} = is_string($value) ? trim($value) : $value;
        }
        $user->save();
        $this->audit->record(
            $request,
            'profile.updated',
            User::class,
            $user->id,
            $oldValues,
            $user->fresh()?->toArray() ?? $user->toArray(),
        );

        return $this->respondWithData([
            'status'  => 'success',
            'message' => 'Profile updated successfully',
            'user' => $user,
        ]);
    }

    public function changePassword(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'current_password'      => ['required', 'string', 'max:72'],
            'password'              => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $user = $this->authenticatedUser($request);
        if (!Hash::check($data['current_password'], $user->password)) {
            return $this->respondWithValidationError([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        if (Hash::check($data['password'], $user->password)) {
            return $this->respondWithValidationError([
                'password' => ['The new password must be different from the current password.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();
        $this->tokens->revokeAll($user);
        $this->audit->record(
            $request,
            'auth.password_changed',
            User::class,
            $user->id,
            null,
            ['auth_version' => $user->auth_version],
            'Password changed and all sessions revoked.',
        );

        return $this->respondWithMessage('Password changed successfully. Sign in again on all devices.');
    }

    public function destroy(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'password' => ['required', 'string', 'max:72'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $user = $this->authenticatedUser($request);
        if (!Hash::check($data['password'], $user->password)) {
            return $this->respondWithValidationError([
                'password' => ['The password is incorrect.'],
            ]);
        }

        $this->tokens->revokeAll($user);
        $this->audit->record(
            $request,
            'account.deactivated',
            User::class,
            $user->id,
            $user->toArray(),
            null,
        );
        $user->delete();

        return $this->respondWithMessage('Account deactivated successfully');
    }

    private function authenticatedUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->getAttribute('user');

        return $user;
    }
}
