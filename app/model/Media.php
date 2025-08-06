<?php
namespace app\model;

use support\Model;

class Media extends Model
{
    /**
     * 与模型关联的表名
     *
     * @var string
     */
    protected $table = 'media';

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
        'filename',
        'original_name',
        'file_path',
        'file_size',
        'mime_type',
        'alt_text',
        'caption',
        'description',
        'author_id',
        'thumb_path'
    ];

    /**
     * 获取附件的完整URL
     *
     * @return string
     */
    public function getUrlAttribute(): string
    {
        return '/uploads/' . $this->file_path;
    }
    
    /**
     * 获取附件缩略图的完整URL
     *
     * @return string
     */
    public function getThumbUrlAttribute(): string
    {
        if ($this->thumb_path) {
            return '/uploads/' . $this->thumb_path;
        }
        
        // 如果没有生成缩略图，则返回原图
        return $this->url;
    }
    
    /**
     * 判断是否为图片类型
     *
     * @return bool
     */
    public function getIsImageAttribute(): bool
    {
        $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        return in_array($this->mime_type, $imageTypes);
    }
}