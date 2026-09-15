<?php

namespace app\repository\payment\runtime;

use app\common\base\BaseRepository;
use app\model\payment\PaymentExceptionRecord;

/**
 * 查询支付业务异常当前状态记录。
 *
 * 本仓库封装异常主体、主体单号和异常类型组成的唯一业务键查询；异常检测、状态推进
 * 和处置结果由服务层负责。
 */
class PaymentExceptionRecordRepository extends BaseRepository
{
    public function __construct()
    {
        parent::__construct(new PaymentExceptionRecord());
    }

    /**
     * 按异常唯一业务键查询记录。
     *
     * @param string $subjectType 异常主体类型
     * @param string $subjectNo 异常主体单号
     * @param string $exceptionType 异常类型
     * @param list<string> $columns 查询字段
     * @return PaymentExceptionRecord|null 记录不存在时返回 null
     */
    public function findBySubjectType(
        string $subjectType,
        string $subjectNo,
        string $exceptionType,
        array $columns = ['*']
    ): ?PaymentExceptionRecord {
        return $this->model->newQuery()
            ->where('subject_type', $subjectType)
            ->where('subject_no', $subjectNo)
            ->where('exception_type', $exceptionType)
            ->first($columns);
    }

    /**
     * 在当前事务中按异常唯一业务键锁定记录。
     *
     * 调用方必须已经开启数据库事务；行锁持续到事务结束，用于串行化同一异常的创建、
     * 重新打开和关闭操作。记录不存在时返回 null。
     *
     * @param string $subjectType 异常主体类型
     * @param string $subjectNo 异常主体单号
     * @param string $exceptionType 异常类型
     * @param list<string> $columns 查询字段
     * @return PaymentExceptionRecord|null 已锁定的异常记录
     */
    public function findForUpdateBySubjectType(
        string $subjectType,
        string $subjectNo,
        string $exceptionType,
        array $columns = ['*']
    ): ?PaymentExceptionRecord {
        return $this->model->newQuery()
            ->where('subject_type', $subjectType)
            ->where('subject_no', $subjectNo)
            ->where('exception_type', $exceptionType)
            ->lockForUpdate()
            ->first($columns);
    }
}
