<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 分类模型
 *
 * @property int $id 分类ID
 * @property string $name 分类名称
 * @property string $slug 分类别名 (用于URL)
 * @property string|null $description 分类描述
 * @property int $parent_id 父分类ID (0表示顶级分类)
 * @property int $sort_order 排序权重
 * @property Carbon|null $created_at 创建时间
 * @property Carbon|null $updated_at 更新时间
 *
 * @property-read Collection|Post[] $posts 该分类下的所有文章
 * @property-read Category|null $parent 父分类
 * @property-read Collection|Category[] $children 子分类
 */
class Category extends Model
{
    /**
     * 与模型关联的数据表。
     * 如果你的表名不是 'categories'，请在这里修改。
     * 例如：protected $table = 'my_categories';
     */
    protected $table = 'categories';

    /**
     * 可批量赋值的属性。
     * 这是为了防止批量赋值漏洞（Mass Assignment）。
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'sort_order',
    ];

    /**
     * 应该被转换为原生类型的属性。
     * 将 'parent_id' 和 'sort_order' 明确为整数类型。
     */
    protected $casts = [
        'parent_id' => 'integer',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // --- 模型关联 ---

    /**
     * 获取该分类下的所有文章。
     * 一个分类可以有多篇文章。
     *
     * @return HasMany
     */
    public function posts(): HasMany
    {
        // 第二个参数是 Post 模型中的外键，默认是 category_id
        return $this->hasMany(Post::class, 'category_id');
    }

    /**
     * 获取此分类的父分类。
     * 一个分类属于一个父分类。
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /**
     * 获取此分类的所有子分类。
     * 一个分类可以有多个子分类。
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    // --- 模型作用域 ---

    /**
     * 查询顶级分类（即没有父分类的分类）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->where('parent_id', 0);
    }

    /**
     * 按排序权重升序排列。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc');
    }

    // --- 访问器和修改器 ---

    /**
     * 获取分类的完整路径（例如：父分类/子分类）。
     * 这是一个访问器，可以像属性一样调用 $category->path。
     *
     * @return string
     */
    public function getPathAttribute(): string
    {
        $path = [$this->name];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($path, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' / ', $path);
    }
}
