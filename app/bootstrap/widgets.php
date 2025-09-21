<?php

// 小工具启动文件
// 此文件用于注册默认小工具

use app\service\DefaultWidgetService;

// 注册所有默认小工具
DefaultWidgetService::registerDefaultWidgets();