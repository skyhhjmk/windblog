<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Model;

/**
 * 文章扩展属性模型
 *
 * @property int       $id      主键ID
 * @property int       $post_id 文章ID
 * @property string    $key     扩展属性键名
 * @property array     $value   扩展属性值（JSON格式）
 * @property-read Post $post    关联的文章
 *
 * @method static Builder|PostExt byPost(int $postId) 查询指定文章的扩展属性
 * @method static Builder|PostExt byKey(string $key) 查询指定键的扩展属性
 */
class PostExt extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'post_ext';

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'post_id',
        'key',
        'value',
    ];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'post_id' => 'integer',
        'value' => 'array', // 将JSONB类型转换为PHP数组
    ];

    /**
     * 指示是否自动维护时间戳
     * 表结构中没有created_at和updated_at字段
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * 模型的"启动"方法
     * 用于注册模型事件和全局作用域
     */
    protected static function booted()
    {
        // 创建或更新前检查唯一性约束
        static::saving(function (PostExt $postExt) {
            // 检查是否已存在相同post_id和key的记录
            $existing = PostExt::where('post_id', $postExt->post_id)
                ->where('key', $postExt->key)
                ->first();

            // 如果存在且不是当前记录，则抛出异常或更新现有记录
            if ($existing && $existing->id !== $postExt->id) {
                // 更新现有记录的值
                $existing->value = $postExt->value;
                $existing->save();

                return false; // 阻止当前记录的保存
            }
        });
    }

    // -----------------------------------------------------
    // 模型关系定义
    // -----------------------------------------------------

    /**
     * 获取关联的文章
     * 定义一个"一对一"关系，一个扩展属性属于一篇文章
     *
     * @return BelongsTo
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id', 'id');
    }

    // -----------------------------------------------------
    // 查询作用域
    // -----------------------------------------------------

    /**
     * 查询作用域：查询指定文章的扩展属性
     *
     * @param Builder $query
     * @param int     $postId 文章ID
     *
     * @return Builder
     */
    public function scopeByPost(Builder $query, int $postId): Builder
    {
        return $query->where('post_id', $postId);
    }

    /**
     * 查询作用域：查询指定键的扩展属性
     *
     * @param Builder $query
     * @param string  $key 键名
     *
     * @return Builder
     */
    public function scopeByKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    // -----------------------------------------------------
    // 便捷方法
    // -----------------------------------------------------

    /**
     * 获取扩展属性值的指定字段
     *
     * @param string $field   字段名
     * @param mixed  $default 默认值
     *
     * @return mixed
     */
    public function getField(string $field, $default = null)
    {
        if (is_array($this->value) && array_key_exists($field, $this->value)) {
            return $this->value[$field];
        }

        return $default;
    }

    /**
     * 设置扩展属性值的指定字段
     *
     * @param string $field 字段名
     * @param mixed  $value 字段值
     *
     * @return $this
     */
    public function setField(string $field, $value)
    {
        if (!is_array($this->value)) {
            $this->value = [];
        }
        $this->value[$field] = $value;

        return $this;
    }

    /**
     * 检查是否存在指定字段
     *
     * @param string $field 字段名
     *
     * @return bool
     */
    public function hasField(string $field): bool
    {
        return is_array($this->value) && array_key_exists($field, $this->value);
    }
}
