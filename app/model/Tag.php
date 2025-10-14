<?php

namespace app\model;

use support\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Throwable;

/**
 * tags 标签表
 * @property integer $id 标签ID，主键(主键)
 * @property string $name 标签名称
 * @property string $slug 标签别名
 * @property string $description 标签描述
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $deleted_at 删除时间
 */
class Tag extends Model
{
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tags';

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
     * 属性类型转换
     * 在从数据库中获取属性时，自动将其转换为指定的类型。
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    
    /**
     * 模型的"启动"方法。
     * 用于注册模型事件，如创建、更新时自动执行某些操作。
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
        
        // 修复逻辑：当$forceDelete为true时，无论配置如何都应该执行硬删除
        if ($forceDelete || ($useSoftDelete && !$forceDelete)) {
            if ($forceDelete) {
                // 硬删除：直接从数据库中删除记录
                \support\Log::debug("Executing hard delete for tag ID: " . $this->id);
                try {
                    return $this->delete();
                } catch (\Exception $e) {
                    \support\Log::error('Hard delete failed for tag ID ' . $this->id . ': ' . $e->getMessage());
                    return false;
                }
            } else {
                // 软删除：设置 deleted_at 字段
                try {
                    \support\Log::debug("Executing soft delete for tag ID: " . $this->id);
                    // 使用save方法而不是update方法，确保模型状态同步
                    $this->deleted_at = date('Y-m-d H:i:s');
                    $result = $this->save();
                    \support\Log::debug("Soft delete result: " . var_export($result, true));
                    \support\Log::debug("Tag deleted_at value after save: " . var_export($this->deleted_at, true));
                    return $result !== false; // 确保返回布尔值
                } catch (\Exception $e) {
                    \support\Log::error('Soft delete failed for tag ID ' . $this->id . ': ' . $e->getMessage());
                    return false;
                }
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            \support\Log::debug("Executing hard delete for tag ID: " . $this->id);
            try {
                return $this->delete();
            } catch (\Exception $e) {
                \support\Log::error('Hard delete failed for tag ID ' . $this->id . ': ' . $e->getMessage());
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
            \support\Log::error('Restore failed for tag ID ' . $this->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取与该标签关联的所有文章。
     * 标签与文章为多对多关系，通过 post_tag 中间表。
     *
     * @return BelongsToMany
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag', 'tag_id', 'post_id');
    }
}