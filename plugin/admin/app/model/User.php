<?php

namespace plugin\admin\app\model;

use app\model\UserOAuthBinding;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id 主键(主键)
 * @property string $username 用户名
 * @property string $nickname 昵称
 * @property string $password 密码
 * @property string $sex 性别
 * @property string $avatar 头像
 * @property string $email 邮箱
 * @property string $mobile 手机
 * @property int $level 等级
 * @property string $birthday 生日
 * @property int $money 余额
 * @property int $score 积分
 * @property string $last_time 登录时间
 * @property string $last_ip 登录ip
 * @property string $join_time 注册时间
 * @property string $join_ip 注册ip
 * @property string $token token
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 * @property int $role 角色
 * @property int $status 禁用
 */
class User extends Base
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
     * 获取用户的OAuth绑定
     *
     * @return HasMany
     */
    public function oauthBindings(): HasMany
    {
        return $this->hasMany(UserOAuthBinding::class, 'user_id', 'id');
    }
}
