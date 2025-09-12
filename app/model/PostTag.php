<?php

namespace app\model;

use support\Model;

/**
 * post_tag 文章-标签关联表
 * @property integer $post_id 文章ID(主键)
 * @property integer $tag_id 标签ID(主键)
 * @property string $created_at 创建时间
 */
class PostTag extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string|null
     */
    protected $connection = 'mysql';
    
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'post_tag';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'tag_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
    
    
}
