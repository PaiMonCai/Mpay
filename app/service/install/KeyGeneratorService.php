<?php

namespace app\service\install;

use app\common\base\BaseService;
use app\common\util\EpayPlatformKeyFile;
use app\common\util\RsaKeyPairGenerator;
use RuntimeException;

/**
 * 安装密钥生成服务。
 */
class KeyGeneratorService extends BaseService
{
    /**
     * 生成随机密钥。
     *
     * @param int $bytes 随机字节数
     * @return string 十六进制密钥
     */
    public function randomSecret(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * 生成 RSA 密钥对。
     *
     * @return array{private_key: string, public_key: string}
     */
    public function rsaPair(): array
    {
        return RsaKeyPairGenerator::generate(2048);
    }

    /**
     * 写入 ePay 平台 RSA 密钥文件。
     *
     * Docker 安装通过 EPAY_PLATFORM_KEY_DIR 指向持久化配置目录；源码安装未配置
     * 该变量时仍写入项目根目录。安装完成后需重启后端，让 config() 加载新密钥。
     *
     * @param bool $overwrite 是否覆盖已有密钥
     * @return array{private: string, public: string, created: bool} 写入结果
     * @throws RuntimeException 密钥目录或 PEM 文件无法写入时抛出
     */
    public function writePlatformKeys(bool $overwrite = false): array
    {
        EpayPlatformKeyFile::ensureDirectory();
        $privatePath = EpayPlatformKeyFile::privatePath();
        $publicPath = EpayPlatformKeyFile::publicPath();

        if (!$overwrite && is_file($privatePath) && is_file($publicPath)) {
            return ['private' => $privatePath, 'public' => $publicPath, 'created' => false];
        }

        $pair = $this->rsaPair();
        if (file_put_contents($privatePath, $pair['private_key'] . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('写入平台私钥失败');
        }
        if (file_put_contents($publicPath, $pair['public_key'] . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('写入平台公钥失败');
        }
        @chmod($privatePath, 0600);
        @chmod($publicPath, 0644);

        return ['private' => $privatePath, 'public' => $publicPath, 'created' => true];
    }

}
