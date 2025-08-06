<?php
namespace app\model;

use support\Model;

class Settings extends Model
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
     * 允许批量赋值的字段
     *
     * @var array
     */
    protected $fillable = ['key', 'value'];
}