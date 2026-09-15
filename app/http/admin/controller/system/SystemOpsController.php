<?php

namespace app\http\admin\controller\system;

use app\common\base\BaseController;
use app\service\system\ops\SystemOpsStatusService;
use support\Request;
use support\Response;

/**
 * 接收管理后台运行监控请求并返回只读状态总览。
 *
 * 服务启停与重启由部署环境的进程守护工具负责，本控制器不提供进程控制入口。
 */
class SystemOpsController extends BaseController
{
    public function __construct(
        protected SystemOpsStatusService $systemOpsStatusService
    ) {
    }

    /**
     * 获取 Webman 运行监控总览。
     *
     * 返回运行环境、长驻进程、关键依赖、网页监听和近期日志摘要，不执行系统命令。
     */
    public function overview(Request $request): Response
    {
        return $this->success($this->systemOpsStatusService->overview());
    }
}
