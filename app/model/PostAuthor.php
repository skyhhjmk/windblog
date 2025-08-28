<?php
namespace app\model;

use support\Model;

class PostAuthor extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'post_author';

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
     * 可以被批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'post_id',
        'author_id',
        'is_primary',
        'contribution',
    ];
    
    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'view_count' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}