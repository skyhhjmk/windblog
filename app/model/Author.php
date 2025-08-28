<?php

namespace app\model;

use Illuminate\Support\Carbon;
use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;

/**
 * @property int $id 作者ID
 * @property string $name 作者名称
 * @property string $email 作者邮箱
 * @property string $bio 作者简介
 * @property Carbon|null $created_at 创建时间
 * @property Carbon|null $updated_at 更新时间
 * @property-read Collection|Post[] $posts 作者发布的所有文章
 */
class Author extends Model
{
    /**
     * 与模型关联的数据表。
     * 如果表名不是模型名的复数形式（例如 'authors'），则需要在这里指定。
     * 根据你的 post_author 表结构，这里应该是 'wa_users'。
     *
     * @var string
     */
    protected $table = 'wa_users';

    /**
     * 指示模型是否应该被戳记时间。
     * 如果你的 'wa_users' 表中有 'created_at' 和 'updated_at' 字段，请保持为 true。
     * 如果没有，请设置为 false。
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 可以被批量赋值的属性。
     * 这是为了防止批量赋值漏洞（Mass Assignment Vulnerability）。
     * 当你使用 Author::create($data) 或 $author->fill($data) 时，只有在这里列出的属性才会被赋值。
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'bio',
        // 如果你的表还有其他允许用户填写的字段，请在这里添加
    ];

    /**
     * 需要被隐藏的属性。
     * 当模型被转换为数组或 JSON 时（例如使用 $author->toArray() 或 return response()->json($author)），
     * 这些属性将不会出现在输出结果中。通常用于隐藏密码、哈希值等敏感信息。
     *
     * @var array
     */
    protected $hidden = [
        // 'password_hash', // 假设你有一个密码哈希字段
        // 'remember_token',
    ];

    /**
     * 需要进行类型转换的属性。
     * 这可以确保从数据库中取出的数据具有你期望的类型。
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'email_verified_at' => 'datetime', // 如果有邮箱验证时间字段
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // ========================================================================
    // 模型事件
    // ========================================================================

    /**
     * 模型的 "booted" 方法。
     * 在这里可以注册模型事件监听器，例如在创建、更新、删除模型时执行一些操作。
     * 这与你之前在 post_author 模型中使用的方法是相同的。
     */
    protected static function booted(): void
    {
        // 示例：在作者被创建时，自动做一些处理
        static::created(function (self $author) {
            // 例如：向管理员发送新作者注册的通知
            // info("新作者已注册: {$author->name} (ID: {$author->id})");
        });

        // 示例：在作者被删除前，检查他是否还有文章
        static::deleting(function (self $author) {
            // 如果设置了数据库级联删除（ON DELETE CASCADE），这里的逻辑可能不是必须的。
            // 但如果需要做一些额外的清理工作（比如删除用户头像文件），可以在这里进行。
            if ($author->posts()->count() > 0) {
                // 注意：如果数据库没有设置级联删除，这里会报错，因为 post_author 表中仍有引用该作者的记录。
                // 手动删除关联：
                // $author->posts()->detach(); // 对于多对多关系
                // 或者 $author->posts()->delete(); // 如果你想删除文章本身
            }
        });
    }

    // ========================================================================
    // 关联关系
    // ========================================================================

    /**
     * 获取该作者撰写的所有文章。
     * 这是一个一对多关系，通过中间表 'post_author' 来定义。
     *
     * @return HasMany
     */
    public function posts(): HasMany
    {
        // 参数说明：
        // 1. Post::class: 关联的模型类
        // 2. 'post_author.postid': 中间表中指向 Post 模型的外键
        // 3. 'wa_users.id': 当前模型（Author）的主键
        return $this->hasMany(Post::class, 'post_author.postid', 'wa_users.id');
    }
}
