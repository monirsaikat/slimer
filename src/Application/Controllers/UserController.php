<?php

namespace App\Application\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use App\Application\Models\User;
use App\Application\Services\TokenService;
use App\Application\Services\AuditService;
use Illuminate\Support\Carbon;
use Psr\Http\Message\ServerRequestInterface as Request;

class UserController extends Controller
{
    public function __construct(
        private TokenService $tokens,
        private AuditService $audit,
    ) {
    }

    public function dashboard(Request $request): Response
    {
        $data = [
            'user' => $request->getAttribute('user'),
        ];

        return $this->respondWithData($data);
    }

    public function list(Request $request): Response
    {
        $query = $this->validateData($request->getQueryParams(), [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
            'role' => ['sometimes', 'in:admin,user,moderator'],
            'status' => ['sometimes', 'in:active,inactive,banned'],
            'sort' => ['sometimes', 'in:id,first_name,last_name,email,created_at,last_login_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
        ]);
        if ($query instanceof Response) {
            return $query;
        }

        $page = (int) ($query['page'] ?? 1);
        $perPage = (int) ($query['per_page'] ?? 15);
        $sort = $query['sort'] ?? 'created_at';
        $direction = $query['direction'] ?? 'desc';

        $usersQuery = User::query();
        if (isset($query['search'])) {
            $search = addcslashes(trim($query['search']), '%_\\');
            $usersQuery->where(function ($builder) use ($search): void {
                $builder->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (isset($query['role'])) {
            $usersQuery->where('role', $query['role']);
        }

        if (isset($query['status'])) {
            $usersQuery->where('status', $query['status']);
        }

        $users = $usersQuery->orderBy($sort, $direction)
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->respondWithData([
            'users' => $users,
        ]);
    }

    public function stats(): Response
    {
        return $this->respondWithData([
            'total' => User::query()->count(),
            'by_status' => [
                User::ACTIVE => User::query()->where('status', User::ACTIVE)->count(),
                User::INACTIVE => User::query()->where('status', User::INACTIVE)->count(),
                User::BANNED => User::query()->where('status', User::BANNED)->count(),
            ],
            'by_role' => [
                'admin' => User::query()->where('role', 'admin')->count(),
                'moderator' => User::query()->where('role', 'moderator')->count(),
                'user' => User::query()->where('role', 'user')->count(),
            ],
        ]);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];

        $user = User::find($userId);
        if (!$user) {
            return $this->respondWithError('User not found', 404);
        }

        return $this->respondWithData($user);
    }

    public function store(Request $request): Response
    {
        $data = $this->validateRequest($request, [
            'first_name'           => [
                'required', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'last_name'            => [
                'required', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'email'                => ['bail', 'required', 'string', 'email:rfc', 'max:100', 'unique:users,email'],
            'password'             => ['required', 'string', 'min:12', 'max:72', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'role'                 => ['sometimes', 'in:admin,user,moderator'],
            'status'               => ['sometimes', 'in:active,inactive,banned'],
        ]);
        if ($data instanceof Response) {
            return $data;
        }

        try {
            $user = new User();
            $user->first_name = trim($data['first_name']);
            $user->last_name  = trim($data['last_name']);
            $user->email      = strtolower(trim($data['email']));
            $user->password   = $data['password'];
            $user->role       = $data['role'] ?? 'user';
            $user->status     = $data['status'] ?? User::ACTIVE;
            $user->email_verified_at = Carbon::now();
            $user->auth_version = 0;
            $user->save();
            $this->audit->record(
                $request,
                'user.created',
                User::class,
                $user->id,
                null,
                $user->toArray(),
            );
            return $this->respondWithData(['message' => 'User created successfully', 'data' => $user], 201);
        } catch (\Throwable $e) {
            return $this->respondWithData([
                'error' => 'Unable to create user',
            ], 500);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $user = $this->findUser((int) $args['id']);
        if (!$user) {
            return $this->respondWithError('User not found', 404);
        }

        $oldValues = $user->toArray();

        $data = $this->validateRequest($request, [
            'first_name' => [
                'sometimes', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'last_name' => [
                'sometimes', 'string', 'min:2', 'max:50', "regex:/^[\\pL\\pM][\\pL\\pM\\s.'-]*$/u",
            ],
            'email' => [
                'bail', 'sometimes', 'string', 'email:rfc', 'max:100', "unique:users,email,{$user->id}",
            ],
            'password' => ['sometimes', 'string', 'min:12', 'max:72', 'confirmed'],
            'password_confirmation' => ['required_with:password', 'string'],
            'role' => ['sometimes', 'in:admin,user,moderator'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $passwordChanged = array_key_exists('password', $data);
        unset($data['password_confirmation']);
        if ($data === []) {
            return $this->respondWithValidationError([
                'body' => ['Provide at least one user field to update.'],
            ]);
        }

        foreach ($data as $field => $value) {
            $user->{$field} = is_string($value) ? trim($value) : $value;
        }
        $user->save();
        if ($passwordChanged) {
            $this->tokens->revokeAll($user);
        }
        $this->audit->record(
            $request,
            'user.updated',
            User::class,
            $user->id,
            $oldValues,
            $user->fresh()?->toArray() ?? $user->toArray(),
        );

        return $this->respondWithData([
            'message' => 'User updated successfully',
            'user' => $user,
        ]);
    }

    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $user = $this->findUser((int) $args['id']);
        if (!$user) {
            return $this->respondWithError('User not found', 404);
        }

        /** @var User $currentUser */
        $currentUser = $request->getAttribute('user');
        if ($currentUser->id === $user->id) {
            return $this->respondWithError('You cannot change your own account status', 409);
        }

        $data = $this->validateRequest($request, [
            'status' => ['required', 'in:active,inactive,banned'],
        ]);

        if ($data instanceof Response) {
            return $data;
        }

        $oldStatus = $user->status;
        $user->status = $data['status'];
        $user->save();
        $this->audit->record(
            $request,
            'user.status_changed',
            User::class,
            $user->id,
            ['status' => $oldStatus],
            ['status' => $user->status],
        );

        return $this->respondWithData([
            'message' => 'User status updated successfully',
            'user' => $user,
        ]);
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $user = $this->findUser((int) $args['id']);
        if (!$user) {
            return $this->respondWithError('User not found', 404);
        }

        /** @var User $currentUser */
        $currentUser = $request->getAttribute('user');
        if ($currentUser->id === $user->id) {
            return $this->respondWithError('You cannot delete your own admin account', 409);
        }

        $oldValues = $user->toArray();
        $user->delete();
        $this->audit->record(
            $request,
            'user.deleted',
            User::class,
            $user->id,
            $oldValues,
            null,
        );

        return $this->respondWithMessage('User deleted successfully');
    }

    private function findUser(int $userId): ?User
    {
        return User::find($userId);
    }
}
