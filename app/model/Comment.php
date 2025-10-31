<?php

namespace app\model;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use support\Log;
use Throwable;

/**
 * comments 评论表
 *
 * @property int $id          评论ID，主键(主键)
 * @property int $post_id     文章ID
 * @property int $user_id     用户ID
 * @property int $parent_id   父评论ID
 * @property string      $guest_name  访客姓名
 * @property string      $guest_email 访客邮箱
 * @property string      $content     评论内容
 * @property string      $quoted_data 引用数据（JSON格式）
 * @property string      $status      评论状态
 * @property string $ai_moderation_result     AI审核结果
 * @property string $ai_moderation_reason     AI审核原因
 * @property float  $ai_moderation_confidence AI审核置信度
 * @property string $ai_moderation_categories AI检测类别
 * @property string      $ip_address  IP地址
 * @property string      $user_agent  用户代理
 * @property string      $created_at  创建时间
 * @property string      $updated_at  更新时间
 * @property string      $deleted_at  删除时间
 * @property-read Post   $post        关联的文章
 * @property-read Author $author      关联的作者
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
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'post_id',
        'user_id',
        'parent_id',
        'guest_name',
        'guest_email',
        'content',
        'quoted_data',
        'status',
        'ai_moderation_result',
        'ai_moderation_reason',
        'ai_moderation_confidence',
        'ai_moderation_categories',
        'ip_address',
        'user_agent',
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
     *
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
     *
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
     *
     * @return bool|null
     * @throws Throwable
     */
    public function softDelete(bool $forceDelete = false): ?bool
    {
        // 判断是否启用软删除，除非强制硬删除
        $useSoftDelete = blog_config('soft_delete', true);
        Log::debug('Soft delete config value: ' . var_export($useSoftDelete, true));
        Log::debug('Force delete flag: ' . var_export($forceDelete, true));

        // 修复逻辑：先判断强制删除，再判断软删除配置
        if ($forceDelete) {
            // 硬删除：直接从数据库中删除记录
            Log::debug('Executing hard delete for comment ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for comment ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } elseif ($useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                Log::debug('Executing soft delete for comment ID: ' . $this->id);

                $this->deleted_at = Carbon::now();
                $result = $this->save();
                Log::debug('Soft delete result: ' . var_export($result, true));

                return $result !== false;
            } catch (Exception $e) {
                Log::error('Soft delete failed for comment ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } else {
            // 配置为不使用软删除，执行硬删除
            Log::debug('Executing hard delete for comment ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for comment ID ' . $this->id . ': ' . $e->getMessage());

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
        } catch (Exception $e) {
            Log::error('Restore failed for comment ID ' . $this->id . ': ' . $e->getMessage());

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
