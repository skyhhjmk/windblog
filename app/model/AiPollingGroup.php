<?php

declare(strict_types=1);

namespace app\model;

use support\Model;

/**
 * AI轮询组模型
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string      $strategy
 * @property bool        $enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiPollingGroup extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_polling_groups';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

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
        'name',
        'description',
        'strategy',
        'enabled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 获取该轮询组下的所有提供方
     */
    public function providers()
    {
        return $this->hasMany(AiPollingGroupProvider::class, 'group_id', 'id');
    }

    /**
     * 获取启用的提供方
     */
    public function enabledProviders()
    {
        return $this->hasMany(AiPollingGroupProvider::class, 'group_id', 'id')
            ->where('enabled', true);
    }

    /**
     * 获取提供方详情（包含AI提供方完整信息）
     */
    public function providersWithDetails()
    {
        return $this->hasManyThrough(
            AiProvider::class,
            AiPollingGroupProvider::class,
            'group_id',     // 轮询组关联表外键
            'id',           // AI提供方表主键
            'id',           // 轮询组表主键
            'provider_id'   // 轮询组关联表关联提供方的外键
        );
    }
}
