<?php
namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use support\Model;

/**
 * 文章模型
 *
 * @property int $id 文章ID
 * @property string $title 文章标题
 * @property string $slug 文章URL别名
 * @property string $content_type 内容类型 (e.g., 'markdown', 'html')
 * @property string $content 文章内容
 * @property string $excerpt 文章摘要
 * @property string $status 文章状态 (e.g., 'published', 'draft', 'archived')
 * @property int $category_id 分类ID
 * @property int $author_id 作者ID
 * @property int $view_count 浏览次数
 * @property \Illuminate\Support\Carbon|null $created_at 创建时间
 * @property \Illuminate\Support\Carbon|null $updated_at 更新时间
 * @property-read \app\model\Author $author 文章作者
 * @property-read \app\model\Category|null $category 文章分类
 * @property-read \app\model\PostAuthor[] $postAuthors 文章-作者关联记录 (用于多作者场景)
 *
 * @method static Builder|Post published() 只查询已发布的文章
 * @method static Builder|Post draft() 只查询草稿箱的文章
 * @method static Builder|Post byAuthor(int $authorId) 查询指定作者的文章
 */
class Post extends Model
{
    /**
     * 与模型关联的表名
     * Eloquent 会自动推断为 'posts'，显式声明以增加代码可读性。
     *
     * @var string
     */
    protected $table = 'posts';

    /**
     * 可批量赋值的属性
     * 允许在 create() 或 fill() 方法中批量赋值的字段。
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'slug',
        'content_type',
        'content',
        'excerpt',
        'status',
        'category_id',
        'author_id',
        'view_count',
    ];

    /**
     * 属性类型转换
     * 在从数据库中获取属性时，自动将其转换为指定的类型。
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'category_id' => 'integer',
        'author_id' => 'integer',
        'view_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * 模型的“启动”方法。
     * 用于注册模型事件，如创建、更新时自动执行某些操作。
     */
    protected static function booted()
    {
        // 当文章正在创建时
        static::creating(function (Post $post) {
            // 如果没有提供 slug，则根据标题自动生成
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
            // 如果没有提供摘要，则从内容中截取
            if (empty($post->excerpt)) {
                $post->excerpt = Str::limit(strip_tags($post->content), 200);
            }
        });

        // 当文章正在更新时
        static::updating(function (Post $post) {
            // 如果标题被修改了，并且 slug 没有被手动修改，则重新生成 slug
            if ($post->isDirty('title') && !$post->isDirty('slug')) {
                $post->slug = Str::slug($post->title);
            }
        });
    }

    // -----------------------------------------------------
    // 模型关系定义
    // -----------------------------------------------------

    /**
     * 获取文章的作者（主要作者）。
     * 定义一个“属于”关系，Post 模型属于 Author 模型。
     * Eloquent 会自动使用 `author_id` 作为外键去 `authors` 表查找。
     *
     * @return BelongsTo
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    /**
     * 获取文章所属的分类。
     * 定义一个“属于”关系，Post 模型属于 Category 模型。
     * Eloquent 会自动使用 `category_id` 作为外键去 `categories` 表查找。
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 获取文章的所有作者（用于多作者场景）。
     * 定义一个“一对多”关系，一篇文章可以有多条 post_author 关联记录。
     * 通过这个关系，可以方便地管理一篇文章的多个作者。
     *
     * @return HasMany
     */
    public function postAuthors(): HasMany
    {
        return $this->hasMany(PostAuthor::class, 'postid');
    }

    // -----------------------------------------------------
    // 查询作用域
    // -----------------------------------------------------

    /**
     * 查询作用域：只查询已发布的文章。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    /**
     * 查询作用域：只查询草稿箱的文章。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * 查询作用域：查询指定作者的文章。
     *
     * @param Builder $query
     * @param int $authorId 作者ID
     * @return Builder
     */
    public function scopeByAuthor(Builder $query, int $authorId): Builder
    {
        return $query->where('author_id', $authorId);
    }
}
