<?php

namespace plugin\webmanlogs\app\controller;

use plugin\admin\app\common\Util;
use plugin\admin\app\controller\Crud;
use support\exception\BusinessException;
use support\Model;
use support\Request;
use support\Response;

class IndexController extends Crud
{

    /**
     * @var Model
     */
    protected $model = null;

    /**
     * 无需登录及鉴权的方法
     * @var array
     */
    protected $noNeedLogin = [];

    /**
     * 需要登录无需鉴权的方法
     * @var array
     */
    protected $noNeedAuth = [];

    /**
     * 数据限制
     * 例如当$dataLimit='personal'时将只返回当前管理员的数据
     * @var string
     */
    protected $dataLimit = null;

    /**
     * 数据限制字段
     */
    protected $dataLimitField = 'admin_id';

    /**
     * 日志列表
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function index(Request $request)
    {
        if ($request->isAjax()) {
            $directory = runtime_path() . '/logs';
            return $this->json(0, '', getFilesAndDirectoriesRecursively($directory, $request->input('filename')));
        }
        return view('index/index', ['name' => 'webmanlogs']);
    }


    /**
     * 日志明细
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function filedetail(Request $request)
    {
        if ($request->isAjax()) {
            // 创建 SplFileObject 对象
            $file = new \SplFileObject($request->input('path'), 'r');

            // 获取文件总行数
            $file->seek(PHP_INT_MAX);

            $totalLines = $file->key();

            // 指定开始行号和读取的行数
            $startLine = $request->input('start_line');
            $startLine = $startLine > 0 ? $startLine : ($totalLines > 1000 ? $totalLines - 1000 : 1);
            $numOfLines = $request->input('lines') > 0 ? $request->input('lines') : 1000;
            // 存储行数据的数组
            $lines = [];
            //跳转到开始行
            $file->seek($startLine - 1);
            // 读取并存储指定的行数据
            while (!$file->eof() && $numOfLines--) {
                $lines[] = $file->fgets();
                $file->next();
            }
            // 将数组转换为字符串原样返回
            $rawContent = implode("", $lines);
            return $this->json(0, '', compact('rawContent'));
        }
        return view('index/filedetail');
    }

}
