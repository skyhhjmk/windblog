<?php

namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use support\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * @property integer $id 主键(主键)
 * @property string $username 用户名
 * @property string $nickname 昵称
 * @property string $password 密码
 * @property string $sex 性别
 * @property string $avatar 头像
 * @property string $email 邮箱
 * @property string $mobile 手机
 * @property integer $level 等级
 * @property string $birthday 生日
 * @property integer $money 余额
 * @property integer $score 积分
 * @property string $last_time 登录时间
 * @property string $last_ip 登录ip
 * @property string $join_time 注册时间
 * @property string $join_ip 注册ip
 * @property string $token token
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property string|null $deleted_at 软删除时间
 * @property integer $role 角色
 * @property integer $status 禁用
 * @property-read \Illuminate\Database\Eloquent\Collection|\app\model\Comment[] $comments 关联的评论
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
//    protected $fillable = [
//        'name',
//        'email',
//        'bio',
//        // 如果你的表还有其他允许用户填写的字段，请在这里添加
//    ];

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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

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
    
    // ========================================================================    
    // 查询作用域    
    // ========================================================================

    /**
     * 查询作用域：包含软删除的记录。
     *
     * @param Builder $query
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
                \support\Log::debug("Executing soft delete for author ID: " . $this->id);
                // 使用save方法而不是update方法，确保模型状态同步
                $this->deleted_at = date('Y-m-d H:i:s');
                $result = $this->save();
                \support\Log::debug("Soft delete result: " . var_export($result, true));
                \support\Log::debug("Author deleted_at value after save: " . var_export($this->deleted_at, true));
                return $result !== false; // 确保返回布尔值
            } catch (\Exception $e) {
                \support\Log::error('Soft delete failed for author ID ' . $this->id . ': ' . $e->getMessage());
                return false;
            }
        } else {
            // 硬删除：直接从数据库中删除记录
            \support\Log::debug("Executing hard delete for author ID: " . $this->id);
            try {
                return $this->delete();
            } catch (\Exception $e) {
                \support\Log::error('Hard delete failed for author ID ' . $this->id . ': ' . $e->getMessage());
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
            \support\Log::error('Restore failed for author ID ' . $this->id . ': ' . $e->getMessage());
            return false;
        }
    }

    // ========================================================================
    // 关联关系
    // ========================================================================

    /**
     * 获取作者的所有文章。
     * 通过 post_author 中间表建立多对多关系。
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function posts()
    {
        return $this->belongsToMany(Post::class, 'post_author', 'author_id', 'post_id');
    }

    /**
     * 获取作者的所有评论
     *
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id', 'id');
    }
}
