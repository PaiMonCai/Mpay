<?php

declare(strict_types=1);

namespace app\exception;

/**
 * 渠道不支持当前支付操作的异常。
 */
class UnsupportedPaymentOperationException extends PaymentException
{
}
