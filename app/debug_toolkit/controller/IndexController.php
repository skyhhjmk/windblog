<?php

namespace app\debug_toolkit\controller;

use support\Request;

class IndexController
{
    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['index'];

    public function index(Request $request, int $page = 1)
    {
    }
}
