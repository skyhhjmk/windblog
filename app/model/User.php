<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 前端用户模型
 *
 * @property int         $id
 * @property string      $username
 * @property string      $nickname
 * @property string      $password
 * @property string      $email
 * @property string|null $mobile
 * @property string|null $avatar
 * @property string|null $email_verified_at
 * @property string|null $activation_token
 * @property string|null $activation_token_expires_at
 * @property string|null $oauth_provider
 * @property string|null $oauth_id
 * @property int         $status 0=未激活, 1=正常, 2=禁用
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class User extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_users';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'level' => 'integer',
        'score' => 'integer',
        'money' => 'decimal:2',
        'email_verified_at' => 'datetime',
        'activation_token_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'last_time' => 'datetime',
        'join_time' => 'datetime',
    ];

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'username',
        'nickname',
        'password',
        'email',
        'mobile',
        'avatar',
        'email_verified_at',
        'activation_token',
        'activation_token_expires_at',
        'oauth_provider',
        'oauth_id',
        'status',
        'join_time',
        'join_ip',
        'last_time',
        'last_ip',
    ];

    /**
     * 隐藏的属性（不在JSON中显示）
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'activation_token',
        'token',
    ];

    /**
     * 检查邮箱是否已验证
     *
     * @return bool
     */
    public function isEmailVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * 检查激活令牌是否有效
     *
     * @return bool
     */
    public function isActivationTokenValid(): bool
    {
        if (!$this->activation_token) {
            return false;
        }

        if (!$this->activation_token_expires_at) {
            return false;
        }

        return now() < $this->activation_token_expires_at;
    }

    /**
     * 生成激活令牌
     *
     * @param int $expiresInHours 过期时间（小时）
     *
     * @return string
     */
    public function generateActivationToken(int $expiresInHours = 24): string
    {
        $token = bin2hex(random_bytes(32));
        $this->activation_token = $token;
        $this->activation_token_expires_at = date('Y-m-d H:i:s', time() + $expiresInHours * 3600);
        $this->save();

        return $token;
    }

    /**
     * 激活账户
     *
     * @return bool
     */
    public function activate(): bool
    {
        $this->email_verified_at = date('Y-m-d H:i:s');
        $this->activation_token = null;
        $this->activation_token_expires_at = null;
        $this->status = 1; // 设置为正常状态

        return $this->save();
    }

    /**
     * 检查用户是否可以评论（已激活）
     *
     * @return bool
     */
    public function canComment(): bool
    {
        return $this->status === 1 && $this->isEmailVerified();
    }

    /**
     * 获取用户头像URL（优先使用自定义头像，否则使用Gravatar）
     *
     * @param int    $size    头像尺寸
     * @param string $default 默认头像类型 (404, mp, identicon, monsterid, wavatar, retro, robohash, blank)
     *
     * @return string
     */
    public function getAvatarUrl(int $size = 200, string $default = 'identicon'): string
    {
        // 如果有自定义头像，直接返回
        if (!empty($this->avatar)) {
            return $this->avatar;
        }

        // 如果有邮箱，使用Gravatar
        if (!empty($this->email)) {
            return $this->getGravatarUrl($this->email, $size, $default);
        }

        // 返回默认头像
        return $this->getGravatarUrl('default@example.com', $size, $default);
    }

    /**
     * 生成Gravatar头像URL
     *
     * @param string $email   邮箱地址
     * @param int    $size    头像尺寸
     * @param string $default 默认头像类型
     *
     * @return string
     */
    private function getGravatarUrl(string $email, int $size = 200, string $default = 'identicon'): string
    {
        $hash = md5(strtolower(trim($email)));

        // 使用Cravatar国内镜像源提高访问速度
        return "https://cn.cravatar.com/avatar/{$hash}?s={$size}&d={$default}";
    }

    /**
     * 获取用户的评论
     *
     * @return HasMany
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id', 'id');
    }

    /**
     * 获取用户的OAuth绑定
     *
     * @return HasMany
     */
    public function oauthBindings(): HasMany
    {
        return $this->hasMany(UserOAuthBinding::class, 'user_id', 'id');
    }

    /**
     * 检查用户是否绑定了某个OAuth平台
     *
     * @param string $provider
     *
     * @return bool
     */
    public function hasOAuthBinding(string $provider): bool
    {
        return $this->oauthBindings()->where('provider', $provider)->exists();
    }

    /**
     * 获取用户的某个OAuth绑定
     *
     * @param string $provider
     *
     * @return UserOAuthBinding|null
     */
    public function getOAuthBinding(string $provider): ?UserOAuthBinding
    {
        return $this->oauthBindings()->where('provider', $provider)->first();
    }

    /**
     * 通过OAuth提供商和ID查找用户
     *
     * @param string $provider
     * @param string $oauthId
     *
     * @return User|null
     * @deprecated 使用UserOAuthBinding模型查询
     */
    public static function findByOAuth(string $provider, string $oauthId): ?User
    {
        return self::where('oauth_provider', $provider)
            ->where('oauth_id', $oauthId)
            ->first();
    }

    /**
     * 通过OAuth绑定查找用户
     *
     * @param string $provider
     * @param string $providerUserId
     *
     * @return User|null
     */
    public static function findByOAuthBinding(string $provider, string $providerUserId): ?User
    {
        $binding = UserOAuthBinding::findByProvider($provider, $providerUserId);

        return $binding ? $binding->user : null;
    }
}
