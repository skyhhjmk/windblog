<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PostAuthor Model
 *
 * 代表文章与作者之间的多对多关联关系表中的一条记录。
 * 此模型用于管理文章的作者信息，包括谁是主要作者以及他们的具体贡献。
 * 它通过 BelongsTo 关系链接到 Post 和 Author 模型。
 *
 * @property int         $id                     记录ID (主键)
 * @property int         $post_id                文章ID (外键)
 * @property int         $author_id              作者ID (外键)
 * @property int         $admin_id               管理员作者ID (外键)
 * @property bool        $is_primary             是否为主要作者
 * @property string|null $contribution           贡献描述，例如 "主笔"、"校对" 等
 * @property Carbon|null $created_at             创建时间
 * @property Carbon|null $updated_at             更新时间
 * @property-read string $formatted_contribution 格式化后的贡献信息 (访问器)
 * @property-read Post   $post                   所属的文章 (关联关系)
 * @property-read Author $author                 所属的作者 (关联关系)
 */
class PostAuthor extends Model
{
    /**
     * 与模型关联的数据表名。
     *
     * @var string
     */
    protected $table = 'post_author';

    /**
     * 指示模型是否应该被戳记时间。
     * 如果你的数据表没有 `created_at` 和 `updated_at` 字段，请设置为 `false`。
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 可以被批量赋值的属性（白名单）。
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'post_id',
        'author_id',
        'admin_id',
        'is_primary',
        'contribution',
    ];

    /**
     * 属性类型转换。
     * 将数据库中的值在获取时自动转换为指定的 PHP 数据类型。
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'post_id' => 'integer',
        'admin_id' => 'integer',
        'author_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ==================================================================
    // 关联关系 (Relationships)
    // ==================================================================

    /**
     * 定义与文章模型的 "属于" 关系。
     * 一条作者关联记录属于一篇文章。
     *
     * @return BelongsTo
     * @example $post = PostAuthor::find(1)->post;
     */
    public function post(): BelongsTo
    {
        // 参数1: 关联模型的完全限定类名 (FQCN)
        // 参数2: 当前模型 (post_author) 的外键字段名
        // 参数3: 关联模型 (posts) 的主键字段名 (默认为 'id'，通常可省略)
        return $this->belongsTo(Post::class, 'post_id', 'id');
    }

    /**
     * 定义与作者模型的 "属于" 关系。
     * 一条作者关联记录属于一个作者。
     *
     * @return BelongsTo
     * @example $author = PostAuthor::find(1)->author;
     */
    public function author(): BelongsTo
    {
        // 假设你的作者模型是 \app\model\Author
        return $this->belongsTo(Author::class, 'author_id', 'id');
    }

    // ==================================================================
    // 访问器 (Accessors)
    // ==================================================================

    /**
     * 获取格式化后的贡献信息。
     * 当访问 `$postAuthor->formatted_contribution` 属性时，此方法会自动被调用。
     *
     * @return string
     */
    public function getFormattedContributionAttribute(): string
    {
        // 使用 ?? null 合并操作符，更简洁
        return $this->contribution ?? '贡献者';
    }

    // ==================================================================
    // 本地作用域 (Local Scopes)
    // ==================================================================

    /**
     * 限制查询只包含主要作者。
     * 允许使用链式调用，如 `PostAuthor::primary()->get()`。
     *
     * @param Builder $query 当前的查询构建器实例
     *
     * @return Builder 修改后的查询构建器实例
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * 限制查询只包含非主要作者。
     * 允许使用链式调用，如 `PostAuthor::secondary()->get()`。
     *
     * @param Builder $query 当前的查询构建器实例
     *
     * @return Builder 修改后的查询构建器实例
     */
    public function scopeSecondary(Builder $query): Builder
    {
        return $query->where('is_primary', false);
    }

    // ==================================================================
    // 模型事件与观察者
    // ==================================================================

    /**
     * 模型的 "booted" 事件钩子。
     * 用于注册模型事件监听器，例如在创建或更新时执行特定逻辑。
     *
     * 此处实现了一个重要的业务逻辑：确保一篇文章在任何时候都只有一个主要作者。
     * 当一条记录被保存并标记为主要作者时，该文章的其他所有作者记录都会被自动更新为非主要作者。
     *
     * @return void
     */
    protected static function booted(): void
    {
        // 监听 'saving' 事件，它在创建和更新时都会触发
        static::saving(function (self $postAuthor) {
            // 如果当前记录被设置为主要作者
            if ($postAuthor->is_primary) {
                // 将同一篇文章的所有其他作者记录设置为非主要作者
                // 使用 whereNotIn 来排除当前记录，更安全
                static::query()
                    ->where('post_id', $postAuthor->post_id)
                    ->whereKeyNot($postAuthor->id) // 推荐用法，等同于 where('id', '!=', $postAuthor->id)
                    ->update(['is_primary' => false]);
            }
        });
    }
}
