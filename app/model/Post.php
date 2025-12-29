<?php

namespace app\model;

use app\service\BlogService;
use app\service\CacheService;
use app\service\ElasticSyncService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use support\Log;
use support\Model;
use Throwable;

/**
 * 文章模型
 *
 * @property int                 $id              文章ID
 * @property string              $title           文章标题
 * @property string              $slug            文章URL别名
 * @property string              $content_type    内容类型 (e.g., 'markdown', 'html')
 * @property string              $content         文章内容
 * @property string              $excerpt         文章摘要
 * @property string|null $ai_summary      AI 摘要
 * @property string              $status          文章状态 (e.g., 'published', 'draft', 'archived')
 * @property string              $visibility      文章可见性 (e.g., 'public', 'private')
 * @property string              $password        文章密码
 * @property bool                $featured        是否为特色文章
 * @property bool                $allow_comments  是否允许评论
 * @property Carbon|null         $created_at      创建时间
 * @property Carbon|null         $updated_at      更新时间
 * @property Carbon|null         $deleted_at      软删除时间
 * @property-read PostAuthor[]   $postAuthors     文章-作者关联记录
 * @property-read Author[]       $authors         通过中间表关联的所有作者
 * @property-read Author|null $primaryAuthor   文章的主要作者
 * @property-read PostCategory[] $postCategories  文章-分类关联记录
 * @property-read Category[]     $categories      通过中间表关联的所有分类
 * @property-read PostTag[]      $postTags        文章-标签关联记录
 * @property-read Tag[]          $tags            通过中间表关联的所有标签
 * @property-read Comment[]      $comments        文章的评论
 *
 * @method static Builder|Post published() 只查询已发布的文章
 * @method static Builder|Post draft() 只查询草稿箱的文章
 * @method static Builder|Post byAuthor(int $authorId) 查询指定作者的文章
 * @method static Builder|Post withTrashed() 包含软删除的记录
 * @method static Builder|Post onlyTrashed() 只查询软删除的记录
 */
class Post extends Model
{
    // 如果模型已有 boot 方法，请手动合并。这里新增一个简易事件挂载。
    protected static function bootEs()
    {
        // 保存后立即同步到 ES（仅当开启或用于预建立索引）
        static::saved(function (Post $post) {
            // 仅在 ES 开启时处理
            if (!BlogService::getConfig('es.enabled', false)) {
                return;
            }
            try {
                // 如果是软删除（deleted_at 已设置），确保从 ES 移除
                if (!empty($post->deleted_at)) {
                    ElasticSyncService::deletePost((int) $post->id);
                } else {
                    ElasticSyncService::indexPost($post);
                }
            } catch (Throwable $e) {
                Log::warning('[Post.saved] ES sync failed: ' . $e->getMessage());
            }
        });

        // 删除后移除 ES 文档
        static::deleted(function (Post $post) {
            // 仅在 ES 开启时处理
            if (!BlogService::getConfig('es.enabled', false)) {
                return;
            }
            try {
                // 硬删除或软删除后的 delete 事件都确保 ES 文档移除
                ElasticSyncService::deletePost((int) $post->id);
            } catch (Throwable $e) {
                Log::warning('[Post.deleted] ES delete failed: ' . $e->getMessage());
            }
        });

        // 保存后检测发布状态变化，清除相关缓存
        static::saved(function (Post $post) {
            try {
                // 检测是否为发布状态
                $isPublished = $post->status === 'published';

                // 检测状态是否从非发布变为发布（新发布）
                $wasUnpublished = $post->wasChanged('status') &&
                    $post->getOriginal('status') !== 'published' &&
                    $post->status === 'published';

                // 如果是发布状态的文章（新发布或已发布的更新），清除缓存
                if ($isPublished) {
                    Log::info("[Post.saved] Post {$post->id} published, clearing caches...");
                    CacheService::clearPublishCache($post->id);

                    if ($wasUnpublished) {
                        Log::info("[Post.saved] Post {$post->id} newly published");
                    }
                }
            } catch (Throwable $e) {
                Log::warning('[Post.saved] Cache clear failed: ' . $e->getMessage());
            }
        });

    }

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
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'slug',
        'content_type',
        'content',
        'excerpt',
        'ai_summary',
        'status',
        'visibility',
        'password',
        'featured',
        'allow_comments',
        'published_at',
    ];

    /**
     * 属性类型转换
     * 在从数据库中获取属性时，自动将其转换为指定的类型。
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'featured' => 'boolean',
        'allow_comments' => 'boolean',
        'comment_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    /**
     * 模型的"启动"方法。
     * 用于注册模型事件，如创建、更新时自动执行某些操作。
     */
    protected static function booted()
    {
        self::bootEs();
        // 添加全局作用域，只查询未软删除的记录
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->whereNull('deleted_at');
        });

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

        // 保存文章后，检查并更新媒体引用
        static::saved(function (Post $post) {
            try {
                // 检查文章内容中的媒体引用
                $content = $post->content;
                if (empty($content)) {
                    return;
                }

                // 提取内容中的媒体URL
                $mediaUrls = [];

                // 匹配HTML图片标签
                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $imgMatches);
                if (!empty($imgMatches[1])) {
                    $mediaUrls = array_merge($mediaUrls, $imgMatches[1]);
                }

                // 匹配Markdown图片链接
                preg_match_all('/!\[[^\]]*\]\(([^\)]+)\)/i', $content, $mdImgMatches);
                if (!empty($mdImgMatches[1])) {
                    $mediaUrls = array_merge($mediaUrls, $mdImgMatches[1]);
                }

                // 匹配普通链接
                preg_match_all('/\[[^\]]*\]\(([^\)]+)\)/i', $content, $linkMatches);
                if (!empty($linkMatches[1])) {
                    $mediaUrls = array_merge($mediaUrls, $linkMatches[1]);
                }

                // 去重
                $mediaUrls = array_unique($mediaUrls);
                $externalMediaUrls = [];
                $localMediaUrls = [];

                foreach ($mediaUrls as $url) {
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        // 处理外部URL
                        $siteUrl = blog_config('site_url', '', true);
                        if (!empty($siteUrl) && strpos($url, $siteUrl) === false) {
                            $externalMediaUrls[] = $url;
                        } elseif (empty($siteUrl)) {
                            // 如果站点URL未配置，将所有URL视为外部URL
                            $externalMediaUrls[] = $url;
                        } else {
                            // 本地URL，提取文件路径部分
                            $localPath = parse_url($url, PHP_URL_PATH);
                            if ($localPath) {
                                $localMediaUrls[] = $localPath;
                            }
                        }
                    } else {
                        // 可能是相对路径，直接作为本地URL处理
                        $localMediaUrls[] = $url;
                    }
                }

                // 更新外部媒体引用
                if (!empty($externalMediaUrls)) {
                    foreach ($externalMediaUrls as $externalUrl) {
                        // 查找对应的媒体记录
                        $media = Media::where(function ($query) use ($externalUrl) {
                            // 尝试多种查询方式，确保在不同数据库中都能工作
                            $query->where('custom_fields', 'LIKE', '%' . $externalUrl . '%')
                                ->orWhereJsonContains('custom_fields->reference_info->external_urls', $externalUrl);
                        })->first();
                        if ($media) {
                            // 记录文章引用
                            $media->addExternalUrlReference($externalUrl, $post->id);
                            $media->save();
                        }
                    }
                }

                // 更新本地媒体引用
                if (!empty($localMediaUrls)) {
                    foreach ($localMediaUrls as $localUrl) {
                        // 提取文件名（用于匹配）
                        $filename = basename($localUrl);
                        if (empty($filename)) {
                            continue;
                        }

                        // 查找对应的媒体记录
                        $media = Media::where('filename', $filename)->first();
                        if ($media) {
                            // 记录文章引用
                            $media->addPostReference($post->id);
                            $media->save();
                        }
                    }
                }
            } catch (Throwable $e) {
                Log::warning('[Post.saved] Media reference check failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * 指示是否自动维护时间戳
     *
     * @var bool
     */
    public $timestamps = true;

    // -----------------------------------------------------
    // 模型关系定义
    // -----------------------------------------------------

    /**
     * 获取文章的所有作者关联记录。
     * 定义一个"一对多"关系，一篇文章可以有多条 post_author 关联记录。
     *
     * @return HasMany
     */
    public function postAuthors(): HasMany
    {
        return $this->hasMany(PostAuthor::class, 'post_id');
    }

    /**
     * 获取文章的所有分类关联记录。
     * 定义一个"一对多"关系，一篇文章可以属于多个分类。
     *
     * @return HasMany
     */
    public function postCategories(): HasMany
    {
        return $this->hasMany(PostCategory::class, 'post_id');
    }

    /**
     * 获取文章的所有标签关联记录。
     * 定义一个"一对多"关系，一篇文章可以有多个标签。
     *
     * @return HasMany
     */
    public function postTags(): HasMany
    {
        return $this->hasMany(PostTag::class, 'post_id');
    }

    /**
     * 获取文章关联的所有作者。
     * 通过 post_author 中间表建立多对多关系。
     *
     * @return BelongsToMany<Author>
     */
    public function authors(): BelongsToMany
    {
        /** @var BelongsToMany<Author> */
        return $this->belongsToMany(Author::class, 'post_author', 'post_id', 'author_id')
            ->orderBy('post_author.is_primary', 'desc');
    }

    /**
     * 获取文章的主要作者关系。
     * 通过 post_author 中间表，筛选出 is_primary = true 的作者关系。
     *
     * @return BelongsToMany
     */
    public function primaryAuthor(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'post_author', 'post_id', 'author_id')
            ->wherePivot('is_primary', true);
    }

    /**
     * 获取文章的主要作者实例。
     * 通过 post_author 中间表，筛选出 is_primary = true 的作者。
     *
     * @return Author|null
     */
    public function getPrimaryAuthor(): ?Author
    {
        return $this->primaryAuthor()->first();
    }

    /**
     * 获取文章关联的所有分类。
     * 通过 post_category 中间表建立多对多关系。
     *
     * @return BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'post_category', 'post_id', 'category_id');
    }

    /**
     * 获取文章关联的所有标签。
     * 通过 post_tag 中间表建立多对多关系。
     *
     * @return BelongsToMany
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }

    /**
     * 获取文章的所有评论
     *
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }

    /**
     * 获取文章的所有扩展属性
     * 定义一个"一对多"关系，一篇文章可以有多个扩展属性
     *
     * @return HasMany
     */
    public function postExts(): HasMany
    {
        return $this->hasMany(PostExt::class, 'post_id', 'id');
    }

    /**
     * 获取文章的指定扩展属性
     *
     * @param string $key 扩展属性键名
     *
     * @return PostExt|null
     */
    public function getExt(string $key): ?PostExt
    {
        /** @var PostExt|null $ext */
        $ext = $this->postExts()->where('key', $key)->first();

        return $ext;
    }

    /**
     * 获取文章的头图URL
     * 优先使用手动设置的头图，否则从文章内容中提取第一张图片
     *
     * @return string|null 头图URL或null
     */
    public function getFeaturedImage(): ?string
    {
        // 1. 检查是否有手动设置的头图
        $featuredImageExt = $this->getExt('featured_image');
        if ($featuredImageExt && isset($featuredImageExt->value['url']) && !empty($featuredImageExt->value['url'])) {
            return $featuredImageExt->value['url'];
        }

        // 2. 从文章内容中提取第一张图片
        $content = $this->content;
        if (empty($content)) {
            return null;
        }

        // 匹配HTML图片标签
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $imgMatches)) {
            return $imgMatches[1];
        }

        // 匹配Markdown图片链接
        if (preg_match('/!\[[^\]]*\]\(([^\)]+)\)/i', $content, $mdImgMatches)) {
            return $mdImgMatches[1];
        }

        return null;
    }

    /**
     * 设置文章的扩展属性
     *
     * @param string $key   扩展属性键名
     * @param array  $value 扩展属性值
     *
     * @return PostExt
     */
    public function setExt(string $key, array $value): PostExt
    {
        // 使用 updateOrCreate 替代先查询后更新，减少数据库查询次数
        /** @var PostExt $ext */
        $ext = PostExt::updateOrCreate(
            [
                'post_id' => $this->id,
                'key' => $key,
            ],
            [
                'value' => $value,
            ]
        );

        return $ext;
    }

    /**
     * 删除文章的指定扩展属性
     *
     * @param string $key 扩展属性键名
     *
     * @return bool
     */
    public function deleteExt(string $key): bool
    {
        $ext = $this->getExt($key);
        if ($ext) {
            return $ext->delete();
        }

        return true; // 不存在时也视为成功
    }

    // -----------------------------------------------------
    // 查询作用域
    // -----------------------------------------------------

    /**
     * 查询作用域：只查询已发布的文章。
     *
     * @param Builder $query
     *
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
     *
     * @return Builder
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    /**
     * 查询作用域：查询指定作者的文章。
     * 通过中间表 post_author 进行查询。
     *
     * @param Builder $query
     * @param int     $authorId 作者ID
     *
     * @return Builder
     */
    public function scopeByAuthor(Builder $query, int $authorId): Builder
    {
        return $query->whereHas('authors', function ($q) use ($authorId) {
            $q->where('id', $authorId);
        });
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
     * 软删除方法，根据配置决定是软删除还是硬删除
     *
     * @param bool $forceDelete 是否强制删除（绕过软删除配置）
     *
     * @return bool|null
     * @throws Throwable
     */
    public function softDelete(bool $forceDelete = false): ?bool
    {
        // 判断是否启用软删除,除非强制硬删除
        $useSoftDelete = blog_config('soft_delete', true);
        Log::debug('Soft delete config value: ' . var_export($useSoftDelete, true));
        Log::debug('Force delete flag: ' . var_export($forceDelete, true));

        // 修正逻辑：先判断强制删除,再判断软删除配置
        if ($forceDelete) {
            // 硬删除：直接从数据库中删除记录
            Log::debug('Executing hard delete for post ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for post ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } elseif ($useSoftDelete) {
            // 软删除：设置 deleted_at 字段
            try {
                Log::debug('Executing soft delete for post ID: ' . $this->id);
                $this->deleted_at = Carbon::now();
                $result = $this->save();
                Log::debug('Soft delete result: ' . var_export($result, true));
                Log::debug('Post deleted_at value after save: ' . var_export($this->deleted_at, true));

                return $result !== false; // 确保返回布尔值
            } catch (Exception $e) {
                Log::error('Soft delete failed for post ID ' . $this->id . ': ' . $e->getMessage());

                return false;
            }
        } else {
            // 配置为不使用软删除,执行硬删除
            Log::debug('Executing hard delete for post ID: ' . $this->id);
            try {
                return $this->delete();
            } catch (Exception $e) {
                Log::error('Hard delete failed for post ID ' . $this->id . ': ' . $e->getMessage());

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
            Log::error('Restore failed for post ID ' . $this->id . ': ' . $e->getMessage());

            return false;
        }
    }
}
