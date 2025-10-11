<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use support\Model;
use plugin\admin\app\model\Admin;
use plugin\admin\app\model\User;
use Throwable;

/**
 * 媒体模型
 *
 * @property string      $filename      文件名
 * @property string      $original_name 原始文件名
 * @property string      $file_path     文件路径
 * @property int         $file_size     文件大小
 * @property string      $mime_type     文件类型
 * @property string      $alt_text      替代文本
 * @property string      $caption       说明文字
 * @property string      $description   描述
 * @property int         $author_id     上传用户ID
 * @property string      $author_type   上传用户类型
 * @property string      $thumb_path    缩略图路径
 * @property string|null $deleted_at    软删除时间
 */
class Media extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'media';

    /**
     * 重定义主键，默认是id
     *
     * @var string
     */
    protected $primaryKey = 'id';

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

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'filename',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'alt_text',
        'caption',
        'description',
        'author_id',
        'author_type',
        'thumb_path'
    ];

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
        \support\Log::debug("Soft delete config value: " . var_export($useSoftDelete, true));
        \support\Log::debug("Force delete flag: " . var_export($forceDelete, true));

        if (!$forceDelete && $useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                \support\Log::debug("Executing soft delete for media ID: " . $this->id);
                // 使用save方法而不是update方法，确保模型状态同步
                $this->deleted_at = date('Y-m-d H:i:s');
                $result = $this->save();
                \support\Log::debug("Soft delete result: " . var_export($result, true));
                \support\Log::debug("Media deleted_at value after save: " . var_export($this->deleted_at, true));
                return $result !== false; // 确保返回布尔值
            } catch (\Exception $e) {
                \support\Log::error('Soft delete failed for media ID ' . $this->id . ': ' . $e->getMessage());
                return false;
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            \support\Log::debug("Executing hard delete for media ID: " . $this->id);
            try {
                return $this->delete();
            } catch (\Exception $e) {
                \support\Log::error('Hard delete failed for media ID ' . $this->id . ': ' . $e->getMessage());
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
            \support\Log::error('Restore failed for media ID ' . $this->id . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取附件的完整URL
     *
     * @return string
     */
    public function getUrlAttribute(): string
    {
        return '/uploads/' . $this->file_path;
    }

    /**
     * 获取附件缩略图的完整URL
     *
     * @return string
     */
    public function getThumbUrlAttribute(): string
    {
        if ($this->thumb_path) {
            return '/uploads/' . $this->thumb_path;
        }

        // 如果没有生成缩略图，则返回原图
        return $this->url;
    }

    /**
     * 判断是否为图片类型
     *
     * @return bool
     */
    public function getIsImageAttribute(): bool
    {
        $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        return in_array($this->mime_type, $imageTypes);
    }

    /**
     * 获取媒体的作者
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function author()
    {
        if ($this->author_type === 'admin') {
            return $this->hasOne(Admin::class, 'id', 'author_id');
        }

        return $this->hasOne(User::class, 'id', 'author_id');
    }

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}