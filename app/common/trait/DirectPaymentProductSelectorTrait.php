<?php

declare(strict_types=1);

namespace app\common\trait;

use app\common\constant\EpayProtocolConstant;
use app\exception\PaymentDefinitiveException;
use app\exception\PaymentException;
use app\exception\PaymentUncertainException;

/**
 * 直连聚合插件支付产品候选选择。
 *
 * 插件通过 `PRODUCT_*`、`enabled_products` 和处理器声明可用产品。
 */
trait DirectPaymentProductSelectorTrait
{
    /**
     * 构建直连插件已开通产品配置字段。
     *
     * @param array<string, string> $options 产品值 => 展示名称
     * @return array<string, mixed>
     */
    private function directPaymentEnabledProductsField(array $options): array
    {
        $values = array_map(static fn (int|string $value): string => (string) $value, array_keys($options));
        $labels = array_values($options);

        return [
            'type' => 'checkbox',
            'field' => 'enabled_products',
            'title' => '已开通产品',
            'value' => $values,
            'options' => array_map(
                static fn (string $value, string $label): array => ['label' => $label, 'value' => $value],
                $values,
                $labels
            ),
            'validate' => [
                ['required' => true, 'message' => '已开通产品不能为空'],
            ],
        ];
    }

    /**
     * 按当前支付环境执行最合适的支付产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, callable|array<string, mixed>> $handlers 产品处理器，key 使用 auth_code/jsapi/h5/urlscheme/web/jump/qrcode/html
     * @param string $gatewayName 插件展示名
     * @return array<string, mixed>
     */
    private function executeDirectPaymentProduct(array $order, array $handlers, string $gatewayName): array
    {
        $declaredHandlers = $handlers;
        $handlers = $this->directPaymentUsableHandlers($order, $handlers);
        $candidates = $this->directPaymentProductCandidates($order, array_keys($handlers));
        $attempts = [];

        foreach ($candidates as $index => $product) {
            try {
                return $handlers[$product]();
            } catch (PaymentException $e) {
                $attempts[] = [
                    'product' => $product,
                    'message' => $e->getMessage(),
                    'code' => (string) $e->getCode(),
                ];

                // 只有产品未开通、权限不足、缺少 JSAPI 身份等可换产品的错误才继续兜底。
                if ($index === count($candidates) - 1 || !$this->directPaymentCanFallback($e)) {
                    throw $this->withDirectPaymentAttempts(
                        $e,
                        $order,
                        $candidates,
                        $attempts,
                        $this->directPaymentSelectedProduct($order, $declaredHandlers[$product] ?? null, $product)
                    );
                }
            }
        }

        throw new PaymentDefinitiveException($gatewayName . '没有适合当前环境的支付产品', 40200, [
            'env' => $this->directPaymentEnv($order),
            'pay_type' => (string) ($order['pay_type_code'] ?? ''),
            'candidate_products' => $candidates,
            'available_products' => array_keys($handlers),
            'enabled_products' => $this->directPaymentEnabledProducts(),
        ]);
    }

    /**
     * 过滤当前订单真正可用的产品处理器。
     *
     * handler 可以直接传闭包，也可以传：
     *
     * [
     *     'products' => ['wxpay' => 'wxpay_scan', 'alipay' => 'alipay_scan'],
     *     'handler' => fn (): array => $this->scanPayByType(...),
     * ]
     *
     * `products` 固定使用 pay_type_code => product_code 映射，表示该 handler 在当前支付方式下依赖哪个插件产品。
     * product_code 应来自插件类内 `PRODUCT_*` 常量；第三方接口的交易类型编码不要写成 handler key。
     * 如果通道配置了 `enabled_products`，每个 handler 都必须声明 `products`，这里会提前过滤未勾选的产品；
     * 如果没有本地产品开关，处理器是否存在就是插件代码能力声明。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<string, callable|array<string, mixed>> $handlers 原始处理器
     * @return array<string, callable> 已按本地开通产品过滤后的处理器
     */
    private function directPaymentUsableHandlers(array $order, array $handlers): array
    {
        $payType = (string) ($order['pay_type_code'] ?? '');
        $usable = [];

        foreach ($handlers as $key => $definition) {
            $handler = null;
            $products = null;

            if (is_array($definition) && array_key_exists('handler', $definition)) {
                $handler = $definition['handler'];
                $products = $definition['products'] ?? null;
            } elseif (is_callable($definition)) {
                $handler = $definition;
            }

            if (!is_callable($handler)) {
                continue;
            }

            if ($products === null && $this->directPaymentEnabledProducts() !== []) {
                continue;
            }

            $matched = true;
            $requiredProducts = $this->directPaymentRequiredProducts($products, $payType, $matched);
            if (!$matched || !$this->directPaymentProductsEnabled($requiredProducts)) {
                continue;
            }

            $usable[(string) $key] = $handler;
        }

        return $usable;
    }

    /**
     * 解析某个 handler 在当前支付方式下依赖的插件产品。
     *
     * @param mixed $products handler 声明的产品依赖
     * @param string $payType 当前支付方式
     * @param bool $matched 是否匹配当前支付方式
     * @return array<int, string>
     */
    private function directPaymentRequiredProducts(mixed $products, string $payType, bool &$matched): array
    {
        $matched = true;
        if ($products === null) {
            return [];
        }

        if (!is_array($products) || array_is_list($products) || !array_key_exists($payType, $products)) {
            $matched = false;
            return [];
        }

        $product = trim((string) $products[$payType]);
        if ($product === '') {
            $matched = false;
            return [];
        }

        return [$product];
    }

    /**
     * 解析已选处理器对应的真实插件产品编码。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param mixed $definition 处理器声明
     * @param string $handlerKey 处理器键
     * @return string 产品编码
     */
    private function directPaymentSelectedProduct(array $order, mixed $definition, string $handlerKey): string
    {
        if (is_array($definition) && array_key_exists('products', $definition)) {
            $matched = true;
            $products = $this->directPaymentRequiredProducts(
                $definition['products'],
                (string) ($order['pay_type_code'] ?? ''),
                $matched
            );
            if ($matched && $products !== []) {
                return (string) $products[0];
            }
        }

        return $handlerKey;
    }

    /**
     * 判断 handler 依赖的插件产品是否已经在当前通道开通。
     *
     * @param array<int, string> $requiredProducts handler 依赖的插件产品
     */
    private function directPaymentProductsEnabled(array $requiredProducts): bool
    {
        if ($requiredProducts === []) {
            return true;
        }

        $enabledProducts = $this->directPaymentEnabledProducts();
        if ($enabledProducts === []) {
            return false;
        }

        foreach ($requiredProducts as $product) {
            if (!in_array($product, $enabledProducts, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 读取插件本地已开通产品配置。
     *
     * @return array<int, string>
     */
    private function directPaymentEnabledProducts(): array
    {
        $products = method_exists($this, 'enabledProducts')
            ? $this->enabledProducts()
            : $this->getConfig('enabled_products', []);

        return is_array($products)
            ? array_values(array_map(static fn (mixed $product): string => (string) $product, $products))
            : [];
    }

    /**
     * 按支付方式、环境和显式 method 生成候选产品。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<int, string> $availableProducts 当前插件已实现的产品
     * @return array<int, string>
     */
    private function directPaymentProductCandidates(array $order, array $availableProducts): array
    {
        $available = array_values(array_unique($availableProducts));
        $payType = (string) ($order['pay_type_code'] ?? '');
        $env = $this->directPaymentEnv($order);
        $payment = $this->directPaymentPayload($order);

        // 付款码支付是独立业务形态，不自动降级到普通扫码，避免用户拿付款码时生成二维码。
        if ($this->directPaymentHasAuthCode($payment)) {
            return in_array('auth_code', $available, true) ? ['auth_code'] : [];
        }

        // 显式偏好仍受环境和插件能力约束。
        $preferred = $this->directPaymentPreferredProducts($payType, $env);
        $methodProduct = $this->directPaymentMethodProduct((string) ($payment['method'] ?? ''));
        if ($methodProduct !== ''
            && in_array($methodProduct, $available, true)
            && $this->directPaymentProductAllowed($methodProduct, $payType, $env)
        ) {
            array_unshift($preferred, $methodProduct);
        }

        return array_values(array_filter(
            array_unique($preferred),
            static fn (string $product): bool => in_array($product, $available, true)
        ));
    }

    /**
     * 根据环境给出通用候选顺序。
     *
     * @return array<int, string>
     */
    private function directPaymentPreferredProducts(string $payType, string $env): array
    {
        if ($env === EpayProtocolConstant::DEVICE_JUMP) {
            return ['jump', 'h5', 'web', 'urlscheme'];
        }

        if ($env === EpayProtocolConstant::DEVICE_WECHAT) {
            return $payType === 'wxpay'
                ? ['jsapi', 'urlscheme', 'qrcode', 'jump']
                : ['h5', 'jump', 'urlscheme', 'qrcode', 'web'];
        }

        if ($env === EpayProtocolConstant::DEVICE_ALIPAY) {
            return $payType === 'alipay'
                ? ['jsapi', 'h5', 'jump', 'web', 'qrcode']
                : ['h5', 'jump', 'urlscheme', 'qrcode', 'web'];
        }

        if (in_array($env, [EpayProtocolConstant::DEVICE_MOBILE, EpayProtocolConstant::DEVICE_QQ], true)) {
            return ['h5', 'jump', 'urlscheme', 'qrcode', 'web'];
        }

        return $payType === 'alipay'
            ? ['web', 'qrcode', 'jump', 'h5']
            : ['qrcode', 'web', 'jump', 'h5'];
    }

    /**
     * 解析支付环境。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     */
    private function directPaymentEnv(array $order): string
    {
        $env = strtolower(trim((string) ($order['_env'] ?? EpayProtocolConstant::DEVICE_PC)));

        return in_array($env, EpayProtocolConstant::v1Devices(), true) ? $env : EpayProtocolConstant::DEVICE_PC;
    }

    /**
     * 读取支付扩展载荷。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @return array<string, mixed>
     */
    private function directPaymentPayload(array $order): array
    {
        $extra = (array) ($order['extra'] ?? []);
        $payment = $extra['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    /**
     * 判断是否有付款码。
     *
     * @param array<string, mixed> $payment 支付扩展载荷
     */
    private function directPaymentHasAuthCode(array $payment): bool
    {
        return trim((string) ($payment['auth_code'] ?? '')) !== '';
    }

    /**
     * 读取标准支付产品偏好。
     */
    private function directPaymentMethodProduct(string $method): string
    {
        $method = strtolower(trim($method));
        if ($method === '') {
            return '';
        }

        return in_array($method, ['jsapi', 'h5', 'jump', 'web', 'urlscheme', 'qrcode', 'html', 'page'], true)
            ? $method
            : '';
    }

    /**
     * 判断显式 method 对当前环境是否合理。
     */
    private function directPaymentProductAllowed(string $product, string $payType, string $env): bool
    {
        if ($product === 'auth_code') {
            return true;
        }
        if ($env === EpayProtocolConstant::DEVICE_JUMP && in_array($product, ['qrcode', 'jsapi'], true)) {
            return false;
        }
        if ($product === 'jsapi') {
            return ($payType === 'wxpay' && $env === EpayProtocolConstant::DEVICE_WECHAT)
                || ($payType === 'alipay' && $env === EpayProtocolConstant::DEVICE_ALIPAY);
        }
        if (in_array($product, ['h5', 'jump', 'urlscheme'], true)) {
            return $env !== EpayProtocolConstant::DEVICE_PC || $product === 'jump';
        }
        if ($product === 'web') {
            return $env !== EpayProtocolConstant::DEVICE_WECHAT || $payType !== 'wxpay';
        }

        return true;
    }

    /**
     * 判断渠道错误是否允许继续尝试下一个产品。
     */
    private function directPaymentCanFallback(PaymentException $e): bool
    {
        if ($e instanceof PaymentUncertainException) {
            return false;
        }

        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $errorCode = strtoupper((string) ($data['channel_error_code'] ?? $data['sub_code'] ?? ''));
        $message = strtoupper($e->getMessage());

        foreach ([
            'ACCESS_FORBIDDEN',
            'INSUFFICIENT',
            'NO_PERMISSION',
            'NOT_OPEN',
            'PRODUCT_NOT_OPEN',
            'INVALID_PRODUCT',
            'TRADE_NOT_SUPPORT',
            '权限不足',
            '无权限',
            '未开通',
            '未签约',
            '不支持',
            '产品不可用',
            '接口未开通',
            '缺少用户标识',
        ] as $keyword) {
            $upperKeyword = strtoupper($keyword);
            if (str_contains($errorCode, $upperKeyword) || str_contains($message, $upperKeyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 附加产品尝试信息，方便后台排障。
     *
     * @param array<string, mixed> $order 标准插件下单参数
     * @param array<int, string> $candidates 候选产品
     * @param array<int, array<string, string>> $attempts 已尝试产品
     */
    private function withDirectPaymentAttempts(
        PaymentException $e,
        array $order,
        array $candidates,
        array $attempts,
        string $payProduct = ''
    ): PaymentException {
        $data = method_exists($e, 'getData') ? $e->getData() : [];
        $data['env'] = $this->directPaymentEnv($order);
        $data['pay_type'] = (string) ($order['pay_type_code'] ?? '');
        $data['candidate_products'] = $candidates;
        $data['product_attempts'] = $attempts;
        if ($payProduct !== '') {
            $data['pay_product'] = $payProduct;
        }

        $exception = $e::class;

        return new $exception($e->getMessage(), (int) ($e->getCode() ?: 40200), $data);
    }
}
