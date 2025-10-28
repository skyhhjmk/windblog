<?php

declare(strict_types=1);

namespace app\model;

use support\Model;

/**
 * AI轮询组提供方关系模型
 *
 * @property int    $id
 * @property int    $group_id
 * @property string $provider_id
 * @property int    $weight
 * @property bool   $enabled
 * @property string $created_at
 * @property string $updated_at
 */
class AiPollingGroupProvider extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ai_polling_group_providers';

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
        'group_id',
        'provider_id',
        'weight',
        'enabled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'group_id' => 'integer',
        'weight' => 'integer',
        'enabled' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 获取所属的轮询组
     */
    public function group()
    {
        return $this->belongsTo(AiPollingGroup::class, 'group_id', 'id');
    }

    /**
     * 获取关联的AI提供方
     */
    public function provider()
    {
        return $this->belongsTo(AiProvider::class, 'provider_id', 'id');
    }
}
