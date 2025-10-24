<?php

namespace app\model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use support\Db;
use support\Log;
use Throwable;

/**
 * 用户OAuth绑定模型
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $provider
 * @property string      $provider_user_id
 * @property string|null $provider_username
 * @property string|null $provider_email
 * @property string|null $provider_avatar
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property string|null $expires_at
 * @property array|null  $extra_data
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class UserOAuthBinding extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_oauth_bindings';

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
        'user_id' => 'integer',
        'extra_data' => 'array',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_username',
        'provider_email',
        'provider_avatar',
        'access_token',
        'refresh_token',
        'expires_at',
        'extra_data',
    ];

    /**
     * 隐藏的属性（不在JSON中显示）
     *
     * @var array
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    /**
     * 关联用户
     *
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 检查令牌是否过期
     *
     * @return bool
     */
    public function isTokenExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }

        return now() > $this->expires_at;
    }

    /**
     * 通过提供商和提供商用户ID查找绑定
     *
     * @param string $provider
     * @param string $providerUserId
     *
     * @return UserOAuthBinding|null
     */
    public static function findByProvider(string $provider, string $providerUserId): ?UserOAuthBinding
    {
        return self::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    /**
     * 获取支持的OAuth提供商列表
     * 从数据库动态读取所有oauth_*配置，仅返回已启用的平台
     *
     * @return array
     */
    public static function getSupportedProviders(): array
    {
        $providers = [];

        try {
            // 从数据库动态读取所有oauth_*配置
            $allSettings = Db::table('settings')
                ->where('key', 'like', 'oauth_%')
                ->get();

            foreach ($allSettings as $setting) {
                // 提取provider名称 (oauth_xxx => xxx)
                $providerKey = str_replace('oauth_', '', $setting->key);

                try {
                    $config = json_decode($setting->value, true);

                    if ($config && is_array($config)) {
                        $providers[$providerKey] = [
                            'name' => $config['name'] ?? ucfirst($providerKey),
                            'icon' => $config['icon'] ?? 'fab fa-' . $providerKey,
                            'color' => $config['color'] ?? '#666',
                            'enabled' => $config['enabled'] ?? false,
                        ];
                    }
                } catch (Throwable $e) {
                    // 忽略错误，继续处理下一个
                    continue;
                }
            }
        } catch (Throwable $e) {
            Log::error('Get supported providers failed: ' . $e->getMessage());
        }

        return $providers;
    }
}
