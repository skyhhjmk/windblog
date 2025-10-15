<?php

namespace plugin\admin\app\model;

/**
 * @property int $id (主键)
 * @property string $key
 * @property string $value
 * @property mixed $created_at
 * @property mixed $updated_at
 */
class Setting extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'settings';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
}
