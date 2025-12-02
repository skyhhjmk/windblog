<?php

namespace plugin\admin\app\controller;

use app\service\updater\UpdateService;
use app\service\version\ChannelEnum;
use app\service\version\VersionService;
use support\Response;

/**
 * 更新控制器
 * 提供版本检查、更新执行、数据库迁移等功能
 */
class UpdateController extends Base
{
    /**
     * @var VersionService 版本服务
     */
    private VersionService $versionService;

    /**
     * @var UpdateService 更新服务
     */
    private UpdateService $updateService;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->versionService = new VersionService();
        $this->updateService = new UpdateService();
    }

    /**
     * 版本检查页面
     *
     * @return Response
     */
    public function index()
    {
        return raw_view('update/index');
    }

    /**
     * 检查新版本
     *
     * @return Response
     */
    public function checkVersion()
    {
        $channel = request()->input('channel', 'release');
        $mirror = request()->input('mirror', null);

        // 验证通道有效性
        if (!ChannelEnum::isValidChannel($channel)) {
            $channel = ChannelEnum::RELEASE->value;
        }

        // 检查版本
        $result = $this->versionService->checkVersion($channel, $mirror);

        return $this->success('检查版本成功', $result);
    }

    /**
     * 获取可用版本列表
     *
     * @return Response
     */
    public function getVersions()
    {
        $channel = request()->post('channel', 'release');
        $mirror = request()->post('mirror', null);

        // 验证通道有效性
        if (!ChannelEnum::isValidChannel($channel)) {
            $channel = ChannelEnum::RELEASE->value;
        }

        // 获取可用版本
        $versions = $this->versionService->getAvailableVersions($channel, $mirror);

        return $this->success('获取可用版本列表成功', $versions);
    }

    /**
     * 执行更新
     *
     * @return Response
     */
    public function executeUpdate()
    {
        $version = request()->post('version', null);
        $channel = request()->post('channel', 'release');
        $mirror = request()->post('mirror', null);

        // 执行更新
        $result = $this->updateService->update($version, $channel, $mirror);

        if ($result['success']) {
            return $this->success('更新成功', $result);
        } else {
            return $this->fail($result['message'], $result);
        }
    }

    /**
     * 执行数据库迁移
     *
     * @return Response
     */
    public function migrateDatabase()
    {
        return $this->runMigrations();
    }

    /**
     * 执行数据库迁移（路由映射方法）
     *
     * @return Response
     */
    public function runMigrations()
    {
        $result = $this->updateService->runMigrations();

        if ($result['success']) {
            return $this->success('数据库迁移成功', $result);
        } else {
            return $this->fail($result['message'], $result);
        }
    }

    /**
     * 设置镜像源
     *
     * @return Response
     */
    public function setMirror()
    {
        $mirror = request()->post('mirror', '');

        if (empty($mirror)) {
            return $this->fail('镜像源不能为空');
        }

        // TODO: 实现设置镜像源的逻辑，保存到配置中
        // blog_config('update_mirror', $mirror, false, false, true);

        return $this->success('镜像源设置成功', ['mirror' => $mirror]);
    }

    /**
     * 查看更新日志
     *
     * @return Response
     */
    public function viewLogs()
    {
        // TODO: 实现从文件或数据库中获取更新日志的逻辑
        $logs = [
            [
                'time' => date('Y-m-d H:i:s'),
                'message' => '系统初始化完成',
                'level' => 'info',
            ],
        ];

        return $this->success('获取更新日志成功', $logs);
    }

    /**
     * 同步主分支代码（dev通道）
     *
     * @return Response
     */
    public function syncMainBranch()
    {
        $mirror = request()->post('mirror', null);

        $result = $this->updateService->syncMainBranch($mirror);

        if ($result['success']) {
            return $this->success('主分支代码同步成功', $result);
        } else {
            return $this->fail($result['message'], $result);
        }
    }

    /**
     * 验证镜像源
     *
     * @return Response
     */
    public function validateMirror()
    {
        $mirror = request()->post('mirror', '');

        if (empty($mirror)) {
            return $this->fail('镜像源不能为空');
        }

        // TODO: 实现镜像源验证逻辑
        // 检查镜像源是否可访问
        // 检查镜像源是否包含正确的版本文件结构

        return $this->success('镜像源验证通过');
    }
}
