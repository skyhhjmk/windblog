<?php

namespace app\model;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use plugin\admin\app\model\Admin;
use plugin\admin\app\model\User;
use support\Log;
use support\Model;
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
 * @property array $custom_fields 自定义字段
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
        'thumb_path',
        'custom_fields',
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
        Log::debug('Soft delete config value: ' . var_export($useSoftDelete, true));
        Log::debug('Force delete flag: ' . var_export($forceDelete, true));

        if ($forceDelete) {
            // 硬删除：直接从数据库中删除记录
            Log::debug('Executing hard delete for media ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for media ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } elseif ($useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                Log::debug('Executing soft delete for media ID: ' . $this->id);

                $this->deleted_at = Carbon::now();
                $result = $this->save();
                Log::debug('Soft delete result: ' . var_export($result, true));

                return $result !== false;
            } catch (Exception $e) {
                Log::error('Soft delete failed for media ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } else {
            // 配置为不使用软删除，执行硬删除
            Log::debug('Executing hard delete for media ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for media ID ' . $this->id . ': ' . $e->getMessage());

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
            Log::error('Restore failed for media ID ' . $this->id . ': ' . $e->getMessage());

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
     * @return HasOne
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
        'custom_fields' => 'array',
    ];

    /**
     * 添加外部URL引用
     *
     * @param string   $externalUrl 外部URL
     * @param int|null $postId      文章ID
     * @param int|null $pageId      页面ID（预留）
     *
     * @return $this
     */
    public function addExternalUrlReference(string $externalUrl, ?int $postId = null, ?int $pageId = null): self
    {
        // 获取当前custom_fields的副本
        $customFields = $this->custom_fields ?? [];

        // 确保reference_info存在
        if (!isset($customFields['reference_info'])) {
            $customFields['reference_info'] = [];
        }
        if (!isset($customFields['reference_info']['external_urls'])) {
            $customFields['reference_info']['external_urls'] = [];
        }

        // 初始化外部URL引用信息
        if (!isset($customFields['reference_info']['external_urls'][$externalUrl])) {
            $customFields['reference_info']['external_urls'][$externalUrl] = [
                'posts' => [],
                'pages' => [],
                'count' => 0,
            ];
        }

        // 添加文章引用
        if ($postId && !in_array($postId, $customFields['reference_info']['external_urls'][$externalUrl]['posts'])) {
            $customFields['reference_info']['external_urls'][$externalUrl]['posts'][] = $postId;
            $customFields['reference_info']['external_urls'][$externalUrl]['count']++;
        }

        // 添加页面引用（预留）
        if ($pageId && !in_array($pageId, $customFields['reference_info']['external_urls'][$externalUrl]['pages'])) {
            $customFields['reference_info']['external_urls'][$externalUrl]['pages'][] = $pageId;
            $customFields['reference_info']['external_urls'][$externalUrl]['count']++;
        }

        // 重新赋值以确保修改生效
        $this->custom_fields = $customFields;

        return $this;
    }

    /**
     * 移除外部URL引用
     *
     * @param string   $externalUrl 外部URL
     * @param int|null $postId      文章ID
     * @param int|null $pageId      页面ID（预留）
     *
     * @return $this
     */
    public function removeExternalUrlReference(string $externalUrl, ?int $postId = null, ?int $pageId = null): self
    {
        // 获取当前custom_fields的副本
        $customFields = $this->custom_fields ?? [];

        // 确保reference_info存在
        if (!isset($customFields['reference_info']['external_urls'][$externalUrl])) {
            return $this;
        }

        // 移除文章引用
        if ($postId) {
            $index = array_search($postId, $customFields['reference_info']['external_urls'][$externalUrl]['posts']);
            if ($index !== false) {
                unset($customFields['reference_info']['external_urls'][$externalUrl]['posts'][$index]);
                $customFields['reference_info']['external_urls'][$externalUrl]['count']--;
            }
        }

        // 移除页面引用（预留）
        if ($pageId) {
            $index = array_search($pageId, $customFields['reference_info']['external_urls'][$externalUrl]['pages']);
            if ($index !== false) {
                unset($customFields['reference_info']['external_urls'][$externalUrl]['pages'][$index]);
                $customFields['reference_info']['external_urls'][$externalUrl]['count']--;
            }
        }

        // 如果没有引用了，移除整个外部URL引用
        if ($customFields['reference_info']['external_urls'][$externalUrl]['count'] <= 0) {
            unset($customFields['reference_info']['external_urls'][$externalUrl]);
        }

        // 重新赋值以确保修改生效
        $this->custom_fields = $customFields;

        return $this;
    }

    /**
     * 获取所有外部URL引用
     *
     * @return array
     */
    public function getExternalUrlReferences(): array
    {
        return $this->custom_fields['reference_info']['external_urls'] ?? [];
    }

    /**
     * 添加失败的外部URL
     *
     * @param string   $url   外部URL
     * @param string   $error 错误信息
     * @param int|null $jobId 导入任务ID
     *
     * @return $this
     */
    public function addFailedExternalUrl(string $url, string $error, ?int $jobId = null): self
    {
        // 获取当前custom_fields的副本
        $customFields = $this->custom_fields ?? [];

        // 确保reference_info存在
        if (!isset($customFields['reference_info'])) {
            $customFields['reference_info'] = [];
        }
        if (!isset($customFields['reference_info']['failed_external_urls'])) {
            $customFields['reference_info']['failed_external_urls'] = [];
        }

        // 添加失败的外部URL
        $failedUrl = [
            'url' => $url,
            'error' => $error,
            'retry_count' => 0,
        ];

        // 如果提供了job_id，添加到失败URL信息中
        if ($jobId) {
            $failedUrl['job_id'] = $jobId;
        }

        $customFields['reference_info']['failed_external_urls'][] = $failedUrl;

        // 重新赋值以确保修改生效
        $this->custom_fields = $customFields;

        return $this;
    }

    /**
     * 获取所有失败的外部URL
     *
     * @return array
     */
    public function getFailedExternalUrls(): array
    {
        return $this->custom_fields['reference_info']['failed_external_urls'] ?? [];
    }

    /**
     * 清除失败的外部URL
     *
     * @return $this
     */
    public function clearFailedExternalUrls(): self
    {
        // 获取当前custom_fields的副本
        $customFields = $this->custom_fields ?? [];

        unset($customFields['reference_info']['failed_external_urls']);

        // 重新赋值以确保修改生效
        $this->custom_fields = $customFields;

        return $this;
    }

    /**
     * 增加重试次数
     *
     * @param string $url
     *
     * @return $this
     */
    public function incrementRetryCount(string $url): self
    {
        // 获取当前custom_fields的副本
        $customFields = $this->custom_fields ?? [];

        if (isset($customFields['reference_info']['failed_external_urls'])) {
            $index = array_search($url, array_column($customFields['reference_info']['failed_external_urls'], 'url'));
            if ($index !== false) {
                $customFields['reference_info']['failed_external_urls'][$index]['retry_count']++;
            }
        }

        // 重新赋值以确保修改生效
        $this->custom_fields = $customFields;

        return $this;
    }
}
