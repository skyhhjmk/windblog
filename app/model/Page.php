<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use support\Model;

/**
 * 页面模型
 *
 * @property int $id 页面ID
 * @property string $title 页面标题
 * @property string $slug 页面URL别名
 * @property string $content 页面内容
 * @property string $status 页面状态 (e.g., 'published', 'draft')
 * @property string $template 页面模板
 * @property int $sort_order 排序顺序
 * @property \Illuminate\Support\Carbon|null $created_at 创建时间
 * @property \Illuminate\Support\Carbon|null $updated_at 更新时间
 * @property \Illuminate\Support\Carbon|null $deleted_at 软删除时间
 *
 * @method static Builder|Page published() 只查询已发布的页面
 * @method static Builder|Page draft() 只查询草稿箱的页面
 */
class Page extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'pages';

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'slug',
        'content',
        'status',
        'template',
        'sort_order',
    ];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 指示是否自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 模型的"启动"方法
     */
    protected static function booted()
    {
        // 添加全局作用域，只查询未软删除的记录
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->whereNull('deleted_at');
        });
    }

    // -----------------------------------------------------
    // 查询作用域
    // -----------------------------------------------------

    /**
     * 查询作用域：只查询已发布的页面。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * 查询作用域：只查询草稿箱的页面。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * 查询作用域：包含软删除的记录。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeWithTrashed(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * 查询作用域：只查询软删除的记录。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOnlyTrashed(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')->whereNotNull('deleted_at');
    }
}
