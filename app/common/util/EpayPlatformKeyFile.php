<?php

namespace app\common\util;

use RuntimeException;

/**
 * ePay V2 平台 RSA 密钥文件定位器。
 *
 * 仅在运行环境显式声明 EPAY_PLATFORM_KEY_DIR 时切换目录；未声明时回退到
 * 项目根目录，保证源码安装和本地开发不会受到 Docker 持久化目录影响。
 */
final class EpayPlatformKeyFile
{
    private const ENV_KEY_DIR = 'EPAY_PLATFORM_KEY_DIR';
    private const PRIVATE_FILE = 'epay-platform-private.pem';
    private const PUBLIC_FILE = 'epay-platform-public.pem';

    /**
     * 获取平台密钥目录。
     */
    public static function directory(): string
    {
        $configured = trim((string) getenv(self::ENV_KEY_DIR));
        if ($configured !== '') {
            return self::normalizeDirectory($configured);
        }

        return base_path(false);
    }

    /**
     * 确保平台密钥目录存在。
     *
     * @throws RuntimeException 目录无法创建时抛出
     */
    public static function ensureDirectory(): string
    {
        $directory = self::directory();
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('创建平台密钥目录失败: ' . $directory);
        }

        return $directory;
    }

    /**
     * 获取平台私钥文件路径。
     */
    public static function privatePath(): string
    {
        return self::join(self::directory(), self::PRIVATE_FILE);
    }

    /**
     * 获取平台公钥文件路径。
     */
    public static function publicPath(): string
    {
        return self::join(self::directory(), self::PUBLIC_FILE);
    }

    /**
     * 读取平台私钥内容。
     */
    public static function privateKey(): string
    {
        return self::read(self::privatePath());
    }

    /**
     * 读取平台公钥内容。
     */
    public static function publicKey(): string
    {
        return self::read(self::publicPath());
    }

    private static function read(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $content = file_get_contents($path);

        return is_string($content) ? trim($content) : '';
    }

    private static function join(string $directory, string $file): string
    {
        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file;
    }

    private static function normalizeDirectory(string $directory): string
    {
        $normalized = rtrim(trim($directory), '/\\');

        return $normalized !== '' ? $normalized : $directory;
    }
}
