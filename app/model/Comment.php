<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * comments 评论表
 * @property int $id 评论ID，主键(主键)
 * @property int $post_id 文章ID
 * @property int $user_id 用户ID
 * @property int $parent_id 父评论ID
 * @property string $guest_name 访客姓名
 * @property string $guest_email 访客邮箱
 * @property string $content 评论内容
 * @property string $status 评论状态
 * @property string $ip_address IP地址
 * @property string $user_agent 用户代理
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string $deleted_at 删除时间
 * @property-read \app\model\Post $post 关联的文章
 * @property-read \app\model\Author $author 关联的作者
 */
class Comment extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'comments';

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
        'post_id' => 'integer',
        'user_id' => 'integer',
        'parent_id' => 'integer',
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
        \support\Log::debug('Soft delete config value: ' . var_export($useSoftDelete, true));
        \support\Log::debug('Force delete flag: ' . var_export($forceDelete, true));

        // 修复逻辑：当$forceDelete为true时，无论配置如何都应该执行硬删除
        if ($forceDelete || ($useSoftDelete && !$forceDelete)) {
            if ($forceDelete) {
                // 硬删除：直接从数据库中删除记录
                \support\Log::debug('Executing hard delete for comment ID: ' . $this->id);
                try {
                    return $this->delete();
                } catch (\Exception $e) {
                    \support\Log::error('Hard delete failed for comment ID ' . $this->id . ': ' . $e->getMessage());

                    return false;
                }
            } else {
                // 软删除：设置 deleted_at 字段
                try {
                    \support\Log::debug('Executing soft delete for comment ID: ' . $this->id);
                    // 使用save方法而不是update方法，确保模型状态同步
                    $this->deleted_at = date('Y-m-d H:i:s');
                    $result = $this->save();
                    \support\Log::debug('Soft delete result: ' . var_export($result, true));
                    \support\Log::debug('Comment deleted_at value after save: ' . var_export($this->deleted_at, true));

                    return $result !== false; // 确保返回布尔值
                } catch (\Exception $e) {
                    \support\Log::error('Soft delete failed for comment ID ' . $this->id . ': ' . $e->getMessage());

                    return false;
                }
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            \support\Log::debug('Executing hard delete for comment ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (\Exception $e) {
                \support\Log::error('Hard delete failed for comment ID ' . $this->id . ': ' . $e->getMessage());

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
            \support\Log::error('Restore failed for comment ID ' . $this->id . ': ' . $e->getMessage());

            return false;
        }
    }

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
     * 获取关联的作者
     *
     * @return BelongsTo
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'user_id', 'id');
    }

    /**
     * 获取评论的回复
     *
     * @return HasMany
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id', 'id');
    }
}
