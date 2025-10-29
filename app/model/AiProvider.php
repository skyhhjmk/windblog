<?php

declare(strict_types=1);

namespace app\model;

use support\Model;

/**
 * AI提供方模型
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $template
 * @property string      $type
 * @property string|null $config
 * @property int         $weight
 * @property bool        $enabled
 * @property string      $created_at
 * @property string      $updated_at
 */
class AiProvider extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_providers';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'name',
        'template',
        'type',
        'config',
        'weight',
        'enabled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'weight' => 'integer',
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 获取配置数组
     */
    public function getConfigArray(): array
    {
        if (empty($this->config)) {
            return [];
        }

        $decoded = json_decode($this->config, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 设置配置数组
     */
    public function setConfigArray(array $config): void
    {
        $this->config = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 获取使用该提供方的轮询组
     */
    public function pollingGroups()
    {
        return $this->belongsToMany(
            AiPollingGroup::class,
            'ai_polling_group_providers',
            'provider_id',
            'group_id',
            'id',
            'id'
        );
    }

    /**
     * 生成唯一ID（基于时间戳和随机数）
     */
    public static function generateId(): string
    {
        return 'ai_' . time() . '_' . bin2hex(random_bytes(4));
    }
}
