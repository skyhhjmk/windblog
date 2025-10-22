<?php

namespace app\model;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use support\Log;
use support\Model;

/**
 * FloLink 浮动链接模型
 * 用于存储文章中自动关键词链接配置
 *
 * @property int         $id                浮动链接ID
 * @property string      $keyword           关键词
 * @property string      $url               目标链接地址
 * @property string|null $title             链接标题(用于悬浮窗显示)
 * @property string|null $description       链接描述(用于悬浮窗显示)
 * @property string|null $image             图片URL(用于悬浮窗显示)
 * @property int         $priority          优先级(数字越小优先级越高)
 * @property string      $match_mode        匹配模式: first=仅替换首次出现, all=替换所有
 * @property bool        $case_sensitive    是否区分大小写
 * @property bool        $replace_existing  是否替换已有链接(智能替换aff等)
 * @property string      $target            打开方式
 * @property string      $rel               rel属性
 * @property string      $css_class         CSS类名
 * @property bool        $enable_hover      是否启用悬浮窗
 * @property int         $hover_delay       悬浮窗延迟显示时间(毫秒)
 * @property bool        $status            状态 (true: 启用, false: 禁用)
 * @property int         $sort_order        排序权重
 * @property array|null  $custom_fields     自定义字段(JSON格式)
 * @property Carbon|null $created_at        创建时间
 * @property Carbon|null $updated_at        更新时间
 * @property Carbon|null $deleted_at        软删除时间
 *
 * @method static Builder|FloLink active() 只查询启用状态的浮动链接
 * @method static Builder|FloLink ordered() 按优先级和排序权重排序
 * @method static Builder|FloLink withTrashed() 包含软删除的记录
 * @method static Builder|FloLink onlyTrashed() 只查询软删除的记录
 */
class FloLink extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'flo_links';

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'keyword',
        'url',
        'title',
        'description',
        'image',
        'priority',
        'match_mode',
        'case_sensitive',
        'replace_existing',
        'target',
        'rel',
        'css_class',
        'enable_hover',
        'hover_delay',
        'status',
        'sort_order',
        'custom_fields',
    ];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'keyword' => 'string',
        'url' => 'string',
        'title' => 'string',
        'description' => 'string',
        'image' => 'string',
        'priority' => 'integer',
        'match_mode' => 'string',
        'case_sensitive' => 'boolean',
        'replace_existing' => 'boolean',
        'target' => 'string',
        'rel' => 'string',
        'css_class' => 'string',
        'enable_hover' => 'boolean',
        'hover_delay' => 'integer',
        'status' => 'boolean',
        'sort_order' => 'integer',
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
     * 查询作用域：只查询启用状态的浮动链接
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
     * 查询作用域：按优先级和排序权重排序
     *
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('priority', 'asc')
            ->orderBy('sort_order', 'asc');
    }

    /**
     * 查询作用域：包含软删除的记录
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
     * 查询作用域：只查询软删除的记录
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
     */
    public function softDelete(bool $forceDelete = false): ?bool
    {
        // 判断是否启用软删除，除非强制硬删除
        $useSoftDelete = blog_config('soft_delete', true);

        if (!$forceDelete && $useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                $this->deleted_at = date('Y-m-d H:i:s');
                $result = $this->save();

                return $result !== false;
            } catch (Exception $e) {
                Log::error('Soft delete failed for FloLink ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for FloLink ID ' . $this->id . ': ' . $e->getMessage());

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
            $this->deleted_at = null;
            $result = $this->save();

            return $result !== false;
        } catch (Exception $e) {
            Log::error('Restore failed for FloLink ID ' . $this->id . ': ' . $e->getMessage());

            return false;
        }
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
