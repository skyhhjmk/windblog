<?php

use Phinx\Migration\AbstractMigration;

/**
 * 插入预置数据迁移文件
 * 包含系统配置、角色、友链等基础数据
 */
class InsertSeedData extends AbstractMigration
{
    /**
     * 迁移执行方法
     */
    public function up()
    {
        $this->insertWaOptions();
        $this->insertWaRoles();
        $this->insertLinks();
        $this->insertSettings();
    }

    /**
     * 插入wa_options表预置数据
     */
    private function insertWaOptions()
    {
        $adapterType = $this->getAdapter()->getAdapterType();

        // 检查表是否存在以及结构是否正确
        $tableExists = $this->hasTable('wa_options');
        if ($tableExists) {
            // 检查表是否有name列
            try {
                $columns = $this->fetchRow("SELECT column_name FROM information_schema.columns WHERE table_name = 'wa_options' AND column_name = 'name'");
                if (!$columns) {
                    // 如果没有name列，删除表并重新创建
                    $this->table('wa_options')->drop()->save();
                    $tableExists = false;
                }
            } catch (Exception $e) {
                // 如果查询失败，可能是因为information_schema不可用，直接重新创建表
                $this->table('wa_options')->drop()->save();
                $tableExists = false;
            }
        }

        if (!$tableExists) {
            // 重新创建wa_options表
            $table = $this->table('wa_options', [
                'engine' => $adapterType === 'mysql' ? 'InnoDB' : null,
                'collation' => $adapterType === 'mysql' ? 'utf8mb4_unicode_ci' : null,
                'comment' => '选项表',
            ]);

            $table->addColumn('name', 'string', [
                'limit' => 128,
                'null' => false,
                'comment' => '键',
            ]);

            $table->addColumn('value', 'text', [
                'null' => false,
                'comment' => '值',
            ]);

            $table->addColumn('created_at', 'timestamp', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => '创建时间',
            ]);

            $table->addColumn('updated_at', 'timestamp', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
                'comment' => '更新时间',
            ]);

            $table->create();
        }

        $data = [
            [
                'id' => 1,
                'name' => 'system_config',
                'value' => '{"logo":{"title":"Webman Admin","image":"\/app\/admin\/admin\/images\/logo.png"},"menu":{"data":"\/app\/admin\/rule\/get","method":"GET","accordion":true,"collapse":false,"control":false,"controlWidth":500,"select":"0","async":true},"tab":{"enable":true,"keepState":true,"preload":false,"session":true,"max":"30","index":{"id":"0","href":"\/app\/admin\/index\/dashboard","title":"\u4eea\u8868\u76d8"}},"theme":{"defaultColor":"2","defaultMenu":"light-theme","defaultHeader":"light-theme","allowCustom":true,"banner":false},"colors":[{"id":"1","color":"#36b368","second":"#f0f9eb"},{"id":"2","color":"#2d8cf0","second":"#ecf5ff"},{"id":"3","color":"#f6ad55","second":"#fdf6ec"},{"id":"4","color":"#f56c6c","second":"#fef0f0"},{"id":"5","color":"#3963bc","second":"#ecf5ff"}],"other":{"keepLoad":"500","autoHead":false,"footer":false},"header":{"message":false}}',
                'created_at' => '2022-12-05 14:49:01',
                'updated_at' => '2022-12-08 20:20:28',
            ],
            [
                'id' => 2,
                'name' => 'table_form_schema_wa_users',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"sex":{"field":"sex","_field_id":"4","comment":"性别","control":"select","control_args":"url:\/app\/admin\/dict\/get\/sex","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"avatar":{"field":"avatar","_field_id":"5","comment":"头像","control":"uploadImage","control_args":"url:\/app\/admin\/upload\/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"6","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"7","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"level":{"field":"level","_field_id":"8","comment":"等级","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"birthday":{"field":"birthday","_field_id":"9","comment":"生日","control":"datePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"money":{"field":"money","_field_id":"10","comment":"余额(元)","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"score":{"field":"score","_field_id":"11","comment":"积分","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"last_time":{"field":"last_time","_field_id":"12","comment":"登录时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"last_ip":{"field":"last_ip","_field_id":"13","comment":"登录ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"join_time":{"field":"join_time","_field_id":"14","comment":"注册时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"join_ip":{"field":"join_ip","_field_id":"15","comment":"注册ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"token":{"field":"token","_field_id":"16","comment":"token","control":"input","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"17","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"search_type":"between","list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"18","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"role":{"field":"role","_field_id":"19","comment":"角色","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"20","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-23 15:28:13',
            ],
            [
                'id' => 3,
                'name' => 'table_form_schema_wa_roles',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"角色组","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"rules":{"field":"rules","_field_id":"2","comment":"权限","control":"treeSelectMulti","control_args":"url:\/app\/admin\/rule\/get?type=0,1,2","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"pid":{"field":"pid","_field_id":"5","comment":"父级","control":"select","control_args":"url:\/app\/admin\/role\/select?format=tree","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-19 14:24:25',
            ],
            [
                'id' => 4,
                'name' => 'table_form_schema_wa_rules',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"title":{"field":"title","_field_id":"1","comment":"标题","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"icon":{"field":"icon","_field_id":"2","comment":"图标","control":"iconPicker","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"key":{"field":"key","_field_id":"3","comment":"标识","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"pid":{"field":"pid","_field_id":"4","comment":"上级菜单","control":"treeSelect","control_args":"\/app\/admin\/rule\/select?format=tree&type=0,1","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"5","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"6","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"href":{"field":"href","_field_id":"7","comment":"url","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"type":{"field":"type","_field_id":"8","comment":"类型","control":"select","control_args":"url:\/app\/admin\/dict\/get\/rule_type","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"weight":{"field":"weight","_field_id":"9","comment":"排序","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-19 14:24:25',
            ],
            [
                'id' => 5,
                'name' => 'table_form_schema_wa_admins',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"ID","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"search_type":"between","form_show":false,"searchable":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"avatar":{"field":"avatar","_field_id":"4","comment":"头像","control":"uploadImage","control_args":"url:\/app\/admin\/upload\/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"5","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"6","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"created_at":{"field":"created_at","_field_id":"7","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"updated_at":{"field":"updated_at","_field_id":"8","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"login_at":{"field":"login_at","_field_id":"9","comment":"登录时间","control":"dateTimePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"10","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-19 14:24:25',
            ],
            [
                'id' => 6,
                'name' => 'table_form_schema_wa_options',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"键","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"value":{"field":"value","_field_id":"2","comment":"值","control":"textArea","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-08 11:36:57',
            ],
            [
                'id' => 7,
                'name' => 'table_form_schema_wa_uploads',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"search_type":"normal","form_show":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"名称","control":"input","control_args":"","list_show":true,"searchable":true,"search_type":"normal","form_show":false,"enable_sort":false},"url":{"field":"url","_field_id":"2","comment":"文件","control":"upload","control_args":"url:\/app\/admin\/upload\/file","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"admin_id":{"field":"admin_id","_field_id":"3","comment":"管理员","control":"select","control_args":"url:\/app\/admin\/admin\/select?format=select","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"file_size":{"field":"file_size","_field_id":"4","comment":"文件大小","control":"inputNumber","control_args":"","list_show":true,"search_type":"between","form_show":false,"enable_sort":false,"searchable":false},"mime_type":{"field":"mime_type","_field_id":"5","comment":"mime类型","control":"input","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"image_width":{"field":"image_width","_field_id":"6","comment":"图片宽度","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"image_height":{"field":"image_height","_field_id":"7","comment":"图片高度","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"ext":{"field":"ext","_field_id":"8","comment":"扩展名","control":"input","control_args":"","list_show":true,"searchable":true,"search_type":"normal","form_show":false,"enable_sort":false},"storage":{"field":"storage","_field_id":"9","comment":"存储位置","control":"select","control_args":"url:\/app\/admin\/dict\/get\/storage_type","search_type":"normal","form_show":false,"list_show":true,"enable_sort":false,"searchable":false},"category":{"field":"category","_field_id":"10","comment":"类别","control":"select","control_args":"url:\/app\/admin\/dict\/get\/upload","search_type":"normal","form_show":true,"list_show":true,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"11","comment":"上传时间","control":"datePicker","control_args":"","search_type":"between","form_show":false,"list_show":true,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"12","comment":"更新时间","control":"datePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-19 14:24:25',
            ],
            [
                'id' => 8,
                'name' => 'dict_upload',
                'value' => '[{"value":"1","name":"分类1"},{"value":"2","name":"分类2"},{"value":"3","name":"分类3"}]',
                'created_at' => '2022-12-04 16:24:13',
                'updated_at' => '2022-12-04 16:24:13',
            ],
            [
                'id' => 9,
                'name' => 'dict_sex',
                'value' => '[{"value":"0","name":"女"},{"value":"1","name":"男"}]',
                'created_at' => '2022-12-04 15:04:40',
                'updated_at' => '2022-12-04 15:04:40',
            ],
            [
                'id' => 10,
                'name' => 'dict_status',
                'value' => '[{"value":"0","name":"正常"},{"value":"1","name":"禁用"}]',
                'created_at' => '2022-12-04 15:05:09',
                'updated_at' => '2022-12-04 15:05:09',
            ],
            [
                'id' => 11,
                'name' => 'table_form_schema_wa_admin_roles',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"role_id":{"field":"role_id","_field_id":"1","comment":"角色id","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"admin_id":{"field":"admin_id","_field_id":"2","comment":"管理员id","control":"inputNumber","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-20 19:42:51',
            ],
            [
                'id' => 12,
                'name' => 'dict_dict_name',
                'value' => '[{"value":"dict_name","name":"字典名称"},{"value":"status","name":"启禁用状态"},{"value":"sex","name":"性别"},{"value":"upload","name":"附件分类"}]',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-20 19:42:51',
            ],
        ];

        $this->table('wa_options')->insert($data)->save();
    }

    /**
     * 插入wa_roles表预置数据
     */
    private function insertWaRoles()
    {
        $data = [
            [
                'id' => 1,
                'name' => '超级管理员',
                'rules' => '*',
                'created_at' => '2022-08-13 16:15:01',
                'updated_at' => '2022-12-23 12:05:07',
                'pid' => null,
            ],
        ];

        $this->table('wa_roles')->insert($data)->save();
    }

    /**
     * 插入links表预置数据
     */
    private function insertLinks()
    {
        $adapterType = $this->getAdapter()->getAdapterType();
        $boolType = $adapterType === 'sqlite' ? 1 : true;
        $boolFalseType = $adapterType === 'sqlite' ? 0 : false;

        $data = [
            [
                'name' => '雨云',
                'url' => 'https://www.rainyun.com/github_?s=blog-sys-ads',
                'description' => '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
                'icon' => 'https://www.rainyun.com/favicon.ico',
                'image' => null,
                'sort_order' => 1,
                'status' => $boolType,
                'target' => '_blank',
                'redirect_type' => 'direct',
                'show_url' => $boolFalseType,
                'content' => '# 超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
                'email' => 'admin@biliwind.com',
                'note' => null,
                'seo_title' => '雨云',
                'seo_keywords' => '雨云,云服务器,服务器,性价比',
                'seo_description' => '超高性价比云服务商，使用优惠码github注册并绑定微信即可获得5折优惠',
                'custom_fields' => null,
                'created_at' => '2025-09-26 11:00:00',
                'updated_at' => '2022-12-23 12:05:07',
                'deleted_at' => null,
            ],
        ];

        $this->table('links')->insert($data)->save();
    }

    /**
     * 插入settings表预置数据
     */
    private function insertSettings()
    {
        $data = [
            [
                'key' => 'table_form_schema_wa_users',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"enable_sort":true,"searchable":true,"search_type":"normal","form_show":false},"username":{"field":"username","_field_id":"1","comment":"用户名","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"nickname":{"field":"nickname","_field_id":"2","comment":"昵称","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"password":{"field":"password","_field_id":"3","comment":"密码","control":"input","control_args":"","form_show":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"sex":{"field":"sex","_field_id":"4","comment":"性别","control":"select","control_args":"url:\/app\/admin\/dict\/get\/sex","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"avatar":{"field":"avatar","_field_id":"5","comment":"头像","control":"uploadImage","control_args":"url:\/app\/admin\/upload\/avatar","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"email":{"field":"email","_field_id":"6","comment":"邮箱","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"mobile":{"field":"mobile","_field_id":"7","comment":"手机","control":"input","control_args":"","form_show":true,"list_show":true,"searchable":true,"search_type":"normal","enable_sort":false},"level":{"field":"level","_field_id":"8","comment":"等级","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false,"searchable":false},"birthday":{"field":"birthday","_field_id":"9","comment":"生日","control":"datePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"money":{"field":"money","_field_id":"10","comment":"余额(元)","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"score":{"field":"score","_field_id":"11","comment":"积分","control":"inputNumber","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"last_time":{"field":"last_time","_field_id":"12","comment":"登录时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"last_ip":{"field":"last_ip","_field_id":"13","comment":"登录ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"join_time":{"field":"join_time","_field_id":"14","comment":"注册时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false},"join_ip":{"field":"join_ip","_field_id":"15","comment":"注册ip","control":"input","control_args":"","form_show":true,"searchable":true,"search_type":"normal","list_show":false,"enable_sort":false},"token":{"field":"token","_field_id":"16","comment":"token","control":"input","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"17","comment":"创建时间","control":"dateTimePicker","control_args":"","form_show":true,"searchable":true,"search_type":"between","list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"18","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"between","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"role":{"field":"role","_field_id":"19","comment":"角色","control":"inputNumber","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"status":{"field":"status","_field_id":"20","comment":"禁用","control":"switch","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'group' => 'general',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-23 15:28:13',
            ],
            [
                'key' => 'table_form_schema_wa_roles',
                'value' => '{"id":{"field":"id","_field_id":"0","comment":"主键","control":"inputNumber","control_args":"","list_show":true,"search_type":"normal","form_show":false,"enable_sort":false,"searchable":false},"name":{"field":"name","_field_id":"1","comment":"角色组","control":"input","control_args":"","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"rules":{"field":"rules","_field_id":"2","comment":"权限","control":"treeSelectMulti","control_args":"url:\/app\/admin\/rule\/get?type=0,1,2","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false},"created_at":{"field":"created_at","_field_id":"3","comment":"创建时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"updated_at":{"field":"updated_at","_field_id":"4","comment":"更新时间","control":"dateTimePicker","control_args":"","search_type":"normal","form_show":false,"list_show":false,"enable_sort":false,"searchable":false},"pid":{"field":"pid","_field_id":"5","comment":"父级","control":"select","control_args":"url:\/app\/admin\/role\/select?format=tree","form_show":true,"list_show":true,"search_type":"normal","enable_sort":false,"searchable":false}}',
                'group' => 'general',
                'created_at' => '2022-08-15 00:00:00',
                'updated_at' => '2022-12-23 15:28:13',
            ],
        ];

        $this->table('settings')->insert($data)->save();
    }

    /**
     * 回滚执行方法
     */
    public function down()
    {
        $this->execute('DELETE FROM wa_options WHERE id IN (1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12)');
        $this->execute('DELETE FROM wa_roles WHERE id = 1');
        $this->execute('DELETE FROM links WHERE id = 1');
        $this->execute("DELETE FROM settings WHERE key LIKE 'table_form_schema_%'");
    }
}
