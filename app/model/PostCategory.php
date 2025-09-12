<?php

namespace app\model;

use support\Model;

/**
 * post_category 文章-分类关联表
 * @property integer $post_id 文章ID(主键)
 * @property integer $category_id 分类ID(主键)
 * @property string $created_at 创建时间
 * @property string $updated_at 更新时间
 */
class PostCategory extends Model
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
    protected $table = 'post_category';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'category_id';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;
    
    
}
