<?php

namespace app\model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * post_tag 文章-标签关联表
 *
 * @property int       $post_id    文章ID(主键)
 * @property int       $tag_id     标签ID(主键)
 * @property string    $created_at 创建时间
 * @property-read Post $post       关联的文章
 * @property-read Tag  $tag        关联的标签
 */
class PostTag extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'post_tag';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'tag_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'post_id' => 'integer',
        'tag_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * 获取关联的文章
     *
     * @return BelongsTo
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id', 'id');
    }

    /**
     * 获取关联的标签
     *
     * @return BelongsTo
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id', 'id');
    }
}
