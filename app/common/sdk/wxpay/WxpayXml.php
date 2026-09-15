<?php

declare(strict_types=1);

namespace app\common\sdk\wxpay;

/**
 * 微信支付 V2 XML 编解码工具。
 *
 * 微信支付 V2 接口使用 XML 作为请求和响应格式，字段值通常放在 CDATA 中。
 * 该工具只负责安全地在数组和 XML 字符串之间转换，不参与签名和业务校验。
 */
class WxpayXml
{
    /**
     * 将数组编码为微信支付 V2 XML。
     *
     * @param array<string, mixed> $data 待编码数据
     * @return string XML 字符串
     */
    public static function encode(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value) && !$value instanceof \Stringable) {
                throw new WxpaySdkException('微信支付 V2 XML 字段必须是标量：' . (string) $key);
            }

            $value = (string) $value;
            if ($value === '') {
                continue;
            }

            $xml .= sprintf(
                '<%1$s><![CDATA[%2$s]]></%1$s>',
                htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                self::escapeCdata($value)
            );
        }

        return $xml . '</xml>';
    }

    /**
     * 将微信支付 V2 XML 解码为数组。
     *
     * @param string $xml XML 字符串
     * @return array<string, string> 解码结果
     */
    public static function decode(string $xml): array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return [];
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new WxpaySdkException('微信支付 XML 不允许包含 DOCTYPE 或 ENTITY');
        }

        $previous = libxml_use_internal_errors(true);
        $element = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($element === false) {
            $message = $errors[0]->message ?? '未知 XML 错误';
            throw new WxpaySdkException('微信支付 XML 解析失败：' . trim($message));
        }

        if ($element->getName() !== 'xml') {
            throw new WxpaySdkException('微信支付 XML 根节点必须是 xml');
        }

        $result = [];
        foreach ($element->children() as $child) {
            $key = $child->getName();
            if ($key === '' || array_key_exists($key, $result)) {
                throw new WxpaySdkException('微信支付 XML 包含空字段名或重复字段：' . $key);
            }
            if ($child->count() > 0) {
                throw new WxpaySdkException('微信支付 V2 XML 字段不允许嵌套：' . $key);
            }

            // 直接读取一层子节点，空节点必须保持为空字符串。不能经 JSON 中转，
            // 否则 SimpleXML 会把 <field/> 转成 []，进而错误加入 V2 签名原文。
            $result[$key] = (string) $child;
        }

        return $result;
    }

    /**
     * 转义 CDATA 结束标记，避免字段值破坏 XML 结构。
     *
     * @param string $value 原始字段值
     * @return string CDATA 安全字段值
     */
    private static function escapeCdata(string $value): string
    {
        return str_replace(']]>', ']]]]><![CDATA[>', $value);
    }
}
