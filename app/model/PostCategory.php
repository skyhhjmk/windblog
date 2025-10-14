<?php

namespace app\model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * post_category 文章-分类关联表
 * @property int $post_id 文章ID(主键)
 * @property int $category_id 分类ID(主键)
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property-read \app\model\Post $post 关联的文章
 * @property-read \app\model\Category $category 关联的分类
 */
class PostCategory extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'post_category';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'category_id';

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
        'category_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
     * 获取关联的分类
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id', 'id');
    }
}
