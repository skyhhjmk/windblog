<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Throwable;

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
 * @property Carbon|null $deleted_at 软删除时间
 *
 * @property-read Collection|Post[] $posts 该分类下的所有文章
 * @property-read Category|null $parent 父分类
 * @property-read Collection|Category[] $children 子分类
 * 
 * @method static Builder|Category withTrashed() 包含软删除的记录
 * @method static Builder|Category onlyTrashed() 只查询软删除的记录
 */
class Category extends Model
{
    /**
     * 与模型关联的数据表。
     * 如果你的表名不是 'categories'，请在这里修改。
     * 例如：protected $table = 'my_categories';
     */
    protected $connection = 'pgsql';
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

    /**
     * 指示是否自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 模型的"启动"方法
     *
     * @return void
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

    /**
     * 软删除方法，根据配置决定是软删除还是硬删除
     *
     * @param bool $forceDelete 是否强制删除（绕过软删除配置）
     * @return bool|null
     * @throws Throwable
     */
    public function softDelete(bool $forceDelete = false): ?bool
    {
        // 判断是否启用软删除，除非强制硬删除
        $useSoftDelete = blog_config('soft_delete', true);
        \support\Log::debug("Soft delete config value: " . var_export($useSoftDelete, true));
        \support\Log::debug("Force delete flag: " . var_export($forceDelete, true));
        
        if (!$forceDelete && $useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                \support\Log::debug("Executing soft delete for category ID: " . $this->id);
                // 使用save方法而不是update方法，确保模型状态同步
                $this->deleted_at = date('Y-m-d H:i:s');
                $result = $this->save();
                \support\Log::debug("Soft delete result: " . var_export($result, true));
                \support\Log::debug("Category deleted_at value after save: " . var_export($this->deleted_at, true));
                return $result !== false; // 确保返回布尔值
            } catch (\Exception $e) {
                \support\Log::error('Soft delete failed for category ID ' . $this->id . ': ' . $e->getMessage());
                return false;
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            \support\Log::debug("Executing hard delete for category ID: " . $this->id);
            try {
                return $this->delete();
            } catch (\Exception $e) {
                \support\Log::error('Hard delete failed for category ID ' . $this->id . ': ' . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * 恢复软删除的记录
     *
     * @return bool
     */
    public function restore(): bool
    {
        try {
            // 使用save方法而不是update方法，确保模型状态同步
            $this->deleted_at = null;
            $result = $this->save();
            return $result !== false;
        } catch (\Exception $e) {
            \support\Log::error('Restore failed for category ID ' . $this->id . ': ' . $e->getMessage());
            return false;
        }
    }

    // --- 模型关联 ---

    /**
     * 获取该分类下的所有文章。
     * 分类与文章为多对多关系，通过 post_category 中间表。
     *
     * @return BelongsToMany
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_category', 'category_id', 'post_id');
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
