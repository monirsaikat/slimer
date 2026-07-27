<?php

declare(strict_types=1);

namespace App\Application\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string $original_name
 * @property string $stored_path
 * @property string $mime_type
 * @property int $size
 * @property string $category
 * @property mixed $created_at
 * @property mixed $deleted_at
 * @method static FileUpload|null find(mixed $id, array $columns = ['*'])
 * @method static Builder<FileUpload> query()
 */
class FileUpload extends Model
{
    use SoftDeletes;

    protected $table = 'file_uploads';
}
