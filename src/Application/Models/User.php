<?php

declare(strict_types=1);

namespace App\Application\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $password
 * @property string|null $phone_number
 * @property string|null $date_of_birth
 * @property string|null $gender
 * @property string|null $city
 * @property string|null $state
 * @property string|null $country
 * @property string|null $zip_code
 * @property string|null $address
 * @property string|null $profile_picture
 * @property string $role
 * @property string $status
 * @property int $auth_version
 * @property mixed $email_verified_at
 * @property mixed $last_login_at
 * @method static User|null find(mixed $id, array $columns = ['*'])
 * @method static Builder<User> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static LengthAwarePaginator paginate(int $perPage, array $columns, string $pageName, int $page)
 * @method static Builder<User> active()
 * @method static Builder<User> inactive()
 * @method static Builder<User> banned()
 */
class User extends Model
{
    use SoftDeletes;

    public const BANNED = 'banned';

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    protected $table = 'users';

    protected $hidden = ['password'];

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', self::INACTIVE);
    }

    public function scopeBanned($query)
    {
        return $query->where('status', self::BANNED);
    }

    public function setPasswordAttribute(string $value): void
    {
        if (!empty($value)) {
            $this->attributes['password'] = password_hash($value, PASSWORD_BCRYPT);
        }
    }
}
