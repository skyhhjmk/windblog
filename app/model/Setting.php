<?php
namespace app\model;

use Illuminate\Database\Eloquent\Builder;
use support\Model;
use Throwable;

/**
 * 设置模型
 *
 * @property int $id 主键
 * @property string $key 设置键名
 * @property string $value 设置值
 * @property string|null $created_at 创建时间
 * @property string|null $updated_at 更新时间
 */
class Setting extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * 重定义主键，默认是id
     *
     * @var string
     */
    protected $primaryKey = 'id';

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
        // 设置模型启动时的逻辑
    }
    
    /**
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = ['key', 'value'];

    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    

}