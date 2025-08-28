<?php
namespace app\model;

use support\Model;

class ImportJob extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'import_jobs';

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
     * 可批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'type',
        'file_path',
        'status',
        'options',
        'progress',
        'message',
        'author_id'
    ];

    /**
     * 类型转换
     *
     * @var array
     */
    protected $casts = [
        'options' => 'string', // 作为字符串存储，手动处理JSON
        'completed_at' => 'datetime',
        'author_id' => 'integer',
        'progress' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}