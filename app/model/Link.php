<?php

namespace app\model;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use support\Log;
use support\Model;
use Throwable;

/**
 * 链接模型
 * 用于存储友情链接或其他网址信息。
 *
 * @property int         $id              链接ID
 * @property string      $name            链接名称
 * @property string      $url             链接地址
 * @property string|null $description     链接描述
 * @property string|null $image           链接配图URL
 * @property string|null $icon            网站图标URL
 * @property int         $sort_order      排序权重，数字越小越靠前
 * @property bool        $status          状态 (true: 显示, false: 隐藏)
 * @property string      $target          打开方式 (_blank, _self等)
 * @property string      $redirect_type   跳转方式: direct=直接跳转, goto=中转页跳转, iframe=内嵌页面,info=详情页
 * @property bool        $show_url        是否在中转页显示原始URL
 * @property string|null $content         链接详细介绍(Markdown格式)
 * @property string|null $email           所有者电子邮件
 * @property string|null $callback_url    回调地址
 * @property string|null $note            管理员备注
 * @property string|null $seo_title       SEO标题
 * @property string|null $seo_keywords    SEO关键词
 * @property string|null $seo_description SEO描述
 * @property array|null  $custom_fields   自定义字段(JSON格式)
 * @property Carbon|null $created_at      创建时间
 * @property Carbon|null $updated_at      更新时间
 * @property Carbon|null $deleted_at      软删除时间
 *
 * @method static Builder|Link where(string|array|Closure $column, mixed $operator = null, mixed $value = null, string
 *         $boolean = 'and') 添加where条件查询
 * @method static Builder|Link active() 只查询显示状态的链接
 * @method static Builder|Link ordered() 按排序权重升序查询
 * @method static Builder|Link withTrashed() 包含软删除的记录
 * @method static Builder|Link onlyTrashed() 只查询软删除的记录
 */
class Link extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'links';

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'url',
        'description',
        'image',
        'icon',
        'sort_order',
        'status',
        'target',
        'redirect_type',
        'show_url',
        'content',
        'email',
        'callback_url',
        'note',
        'seo_title',
        'seo_keywords',
        'seo_description',
        'custom_fields',
    ];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'name' => 'string',
        'url' => 'string',
        'image' => 'string',
        'icon' => 'string',
        'description' => 'string',
        'sort_order' => 'integer',
        'status' => 'boolean',
        'show_url' => 'boolean',
        'content' => 'string',
        'email' => 'string',
        'callback_url' => 'string',
        'note' => 'string',
        'seo_title' => 'string',
        'seo_keywords' => 'string',
        'seo_description' => 'string',
        'custom_fields' => 'array',
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
     * 查询作用域：只查询显示状态的链接。
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    /**
     * 查询作用域：按排序权重升序查询。
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc');
    }

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
     * 查询作用域：只查询待审核的记录。
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', false);
    }

    /**
     * 查询作用域：只查询已审核的记录。
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', true);
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

        if (!$forceDelete && $useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                Log::debug('Executing soft delete for link ID: ' . $this->id);
                // 使用save方法而不是update方法，确保模型状态同步
                $this->deleted_at = date('Y-m-d H:i:s');
                $result = $this->save();
                Log::debug('Soft delete result: ' . var_export($result, true));
                Log::debug('Link deleted_at value after save: ' . var_export($this->deleted_at, true));

                return $result !== false; // 确保返回布尔值
            } catch (Exception $e) {
                Log::error('Soft delete failed for link ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            Log::debug('Executing hard delete for link ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for link ID ' . $this->id . ': ' . $e->getMessage());

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
            Log::error('Restore failed for link ID ' . $this->id . ': ' . $e->getMessage());

            return false;
        }
    }

    // -----------------------------------------------------
    // 自定义字段访问器
    // -----------------------------------------------------

    /**
     * 获取管理员笔记
     *
     * @return string|null
     */
    public function getAdminNoteAttribute(): ?string
    {
        return $this->custom_fields['admin_note'] ?? null;
    }

    /**
     * 设置管理员笔记
     *
     * @param string|null $value
     *
     * @return void
     */
    public function setAdminNoteAttribute(?string $value): void
    {
        $customFields = $this->custom_fields ?: [];
        if ($value === null || $value === '') {
            unset($customFields['admin_note']);
        } else {
            $customFields['admin_note'] = $value;
        }
        $this->custom_fields = $customFields;
    }

    /**
     * 获取自定义字段值
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    public function getCustomField(string $key, $default = null)
    {
        return $this->custom_fields[$key] ?? $default;
    }

    /**
     * 设置自定义字段值
     *
     * @param string $key
     * @param mixed $value
     *
     * @return void
     */
    public function setCustomField(string $key, $value): void
    {
        $customFields = $this->custom_fields ?: [];
        if ($value === null) {
            unset($customFields[$key]);
        } else {
            $customFields[$key] = $value;
        }
        $this->custom_fields = $customFields;
    }

    /**
     * 批量设置自定义字段
     *
     * @param array $fields
     *
     * @return void
     */
    public function setCustomFields(array $fields): void
    {
        $customFields = $this->custom_fields ?: [];
        foreach ($fields as $key => $value) {
            if ($value === null) {
                unset($customFields[$key]);
            } else {
                $customFields[$key] = $value;
            }
        }
        $this->custom_fields = $customFields;
    }
}
