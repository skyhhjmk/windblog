<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use support\Model;

/**
 * 广告模型
 *
 * @property int         $id
 * @property string      $title
 * @property string      $type           image|google|html
 * @property bool        $enabled
 * @property string|null $image_url
 * @property string|null $link_url
 * @property string|null $link_target
 * @property string|null $html
 * @property string|null $google_ad_client
 * @property string|null $google_ad_slot
 * @property array|null  $placements
 * @property int         $weight
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property string|null $deleted_at
 */
class Ad extends Model
{
    public $timestamps = true;

    protected $table = 'ads';

    protected $fillable = [
        'title',
        'type',
        'enabled',
        'image_url',
        'link_url',
        'link_target',
        'html',
        'google_ad_client',
        'google_ad_slot',
        'placements',
        'weight',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'placements' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted()
    {
        // 仅查询未软删除的记录
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->whereNull('deleted_at');
        });
    }
}
