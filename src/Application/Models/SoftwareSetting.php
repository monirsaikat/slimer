<?php

declare(strict_types=1);

namespace App\Application\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property string $setting_key
 * @property string|null $setting_value
 * @property string $type
 * @property string $setting_group
 * @property bool $is_public
 * @property string|null $description
 * @method static Builder<SoftwareSetting> query()
 */
class SoftwareSetting extends Model
{
    protected $table = 'software_settings';

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function typedValue(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->setting_value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->setting_value,
            'json' => json_decode((string) $this->setting_value, true),
            default => $this->setting_value,
        };
    }

    public function setTypedValue(mixed $value, string $type): void
    {
        $this->type = $type;
        $this->setting_value = match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string) $value,
            'json' => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };
    }
}
