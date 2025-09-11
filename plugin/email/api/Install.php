<?php

namespace plugin\email\api;

use plugin\admin\api\Menu;
use plugin\email\app\admin\controller\SettingController;

class Install
{
    /**
     * 安装
     * @return void
     */
    public static function install()
    {
        if (Menu::get(SettingController::class)) {
            return;
        }
        // 找到通用菜单
        $commonMenu = Menu::get('common');
        if (!$commonMenu) {
            echo "未找到通用设置菜单" . PHP_EOL;
            return;
        }
        // 以通用菜单为上级菜单插入菜单
        $pid = $commonMenu['id'];
        Menu::add([
            'title' => '邮件设置',
            'href' => '/app/email/admin/setting',
            'pid' => $pid,
            'key' => SettingController::class,
            'weight' => 0,
            'type' => 1,
        ]);
    }

    /**
     * 卸载
     * @return void
     */
    public static function uninstall()
    {
        // 删除菜单
        Menu::delete(SettingController::class);
    }
}