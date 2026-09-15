<?php

namespace app\http\admin\controller\ops;

use app\common\base\BaseController;
use app\http\admin\validation\PaymentExceptionValidator;
use app\service\ops\exception\PaymentExceptionQueryService;
use support\Request;
use support\Response;

/** 支付异常中心只读控制器。 */
class PaymentExceptionController extends BaseController
{
    public function __construct(
        protected PaymentExceptionQueryService $queryService
    ) {
    }

    /** 查询业务异常列表。 */
    public function exceptionIndex(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentExceptionValidator::class, 'exceptionIndex');
        return $this->success($this->queryService->paginateExceptions(
            $data,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 10)
        ));
    }

    /** 查询业务异常详情。 */
    public function exceptionShow(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentExceptionValidator::class, 'exceptionShow');
        $row = $this->queryService->exceptionDetail((int) $data['id']);
        return $row ? $this->success($row) : $this->fail('业务异常不存在', 404);
    }

    /** 查询恢复任务列表。 */
    public function recoveryIndex(Request $request): Response
    {
        $data = $this->validated($request->all(), PaymentExceptionValidator::class, 'recoveryIndex');
        return $this->success($this->queryService->paginateRecoveryTasks(
            $data,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 10)
        ));
    }

    /** 查询恢复任务详情。 */
    public function recoveryShow(Request $request, string $id): Response
    {
        $data = $this->validated(['id' => (int) $id], PaymentExceptionValidator::class, 'recoveryShow');
        $row = $this->queryService->recoveryTaskDetail((int) $data['id']);
        return $row ? $this->success($row) : $this->fail('恢复任务不存在', 404);
    }
}
