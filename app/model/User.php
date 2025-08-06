<?php
namespace app\model;

use support\Model;

class User extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'posts';

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
}