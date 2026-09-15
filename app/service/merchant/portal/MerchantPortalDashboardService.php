<?php

namespace app\service\merchant\portal;

use app\common\base\BaseService;
use app\common\constant\CommonConstant;
use app\common\constant\RouteConstant;
use app\repository\ops\dashboard\MerchantDashboardRepository;
use app\service\payment\order\PayOrderService;
use app\service\payment\order\RefundService;

/**
 * 商户首页业务工作台服务。
 *
 * 通过单一接口返回交易指标、接入检查、待办和近期业务记录，避免前端并发多个请求后
 * 因任一接口失败而清空整个首页。
 */
class MerchantPortalDashboardService extends BaseService
{
    public function __construct(
        protected MerchantDashboardRepository $dashboardRepository,
        protected MerchantPortalProfileService $profileService,
        protected MerchantPortalChannelService $channelService,
        protected MerchantPortalCredentialService $credentialService,
        protected MerchantPortalFinanceService $financeService,
        protected PayOrderService $payOrderService,
        protected RefundService $refundService
    ) {
    }

    /** 获取当前商户业务工作台总览。 */
    public function overview(int $merchantId): array
    {
        $today = date('Y-m-d');
        $start = $today . ' 00:00:00';
        $end = date('Y-m-d 00:00:00', strtotime($start . ' +1 day'));
        $merchantPayload = $this->profileService->profile($merchantId);
        $merchant = (array) ($merchantPayload['merchant'] ?? []);
        $credential = $this->credentialService->apiCredential($merchantId);
        $balancePayload = $this->financeService->withdrawableBalance($merchantId);
        $snapshot = (array) ($balancePayload['snapshot'] ?? []);

        $channels = $this->channelService->myChannels([], $merchantId, 1, 5);
        $enabledChannels = $this->channelService->myChannels(['status' => CommonConstant::STATUS_ENABLED], $merchantId, 1, 1);
        $platformChannels = $this->channelService->myChannels(['channel_mode' => RouteConstant::CHANNEL_MODE_COLLECT], $merchantId, 1, 1);
        $selfChannels = $this->channelService->myChannels(['channel_mode' => RouteConstant::CHANNEL_MODE_SELF], $merchantId, 1, 1);
        $pluginConfigs = $this->channelService->pluginConfigs([], $merchantId, 1, 1);

        $todayPay = $this->dashboardRepository->todayPaySummary($merchantId, $start, $end);
        $todayRefundAmount = $this->dashboardRepository->todayRefundAmount($merchantId, $start, $end);
        $pendingSettlement = $this->dashboardRepository->pendingSettlementSummary($merchantId);
        $successRateBp = $todayPay['order_count'] > 0
            ? (int) floor($todayPay['success_count'] * 10000 / $todayPay['order_count'])
            : 0;

        $readiness = $this->readiness(
            $merchant,
            $credential,
            (int) ($platformChannels['total'] ?? 0),
            (int) ($selfChannels['total'] ?? 0),
            (int) ($pluginConfigs['total'] ?? 0),
            (int) ($enabledChannels['total'] ?? 0),
            $this->dashboardRepository->hasChannelTestOrder($merchantId)
        );

        return [
            'generated_at' => $this->formatDateTime($this->now()),
            'merchant' => $merchant,
            'credential' => $credential,
            'snapshot' => $snapshot,
            'metrics' => [
                'today_pay_amount' => $todayPay['pay_amount'],
                'today_pay_amount_text' => $this->formatAmount($todayPay['pay_amount']),
                'today_order_count' => $todayPay['order_count'],
                'today_success_rate_bp' => $successRateBp,
                'today_success_rate_text' => $this->formatRate($successRateBp),
                'today_refund_amount' => $todayRefundAmount,
                'today_refund_amount_text' => $this->formatAmount($todayRefundAmount),
                'available_balance' => (int) ($snapshot['available_balance'] ?? 0),
                'available_balance_text' => (string) ($snapshot['available_balance_text'] ?? '0.00'),
                'frozen_balance' => (int) ($snapshot['frozen_balance'] ?? 0),
                'frozen_balance_text' => (string) ($snapshot['frozen_balance_text'] ?? '0.00'),
                'pending_settlement_count' => $pendingSettlement['count'],
                'pending_settlement_amount' => $pendingSettlement['amount'],
                'pending_settlement_amount_text' => $this->formatAmount($pendingSettlement['amount']),
                'channel_total' => (int) ($channels['total'] ?? 0),
                'enabled_channel_count' => (int) ($enabledChannels['total'] ?? 0),
            ],
            'readiness' => $readiness,
            'tasks' => $this->tasks($readiness, $pendingSettlement['count']),
            'recent' => [
                'channels' => array_values((array) ($channels['list'] ?? [])),
                'pay_orders' => $this->payOrderService->paginate([], 1, 5, $merchantId)['list'] ?? [],
                'refund_orders' => $this->refundService->paginate([], 1, 5, $merchantId)['list'] ?? [],
                'settlements' => $this->financeService->settlementRecords([], $merchantId, 1, 5)['list'] ?? [],
                'ledgers' => $this->financeService->balanceFlows([], $merchantId, 1, 5)['list'] ?? [],
            ],
        ];
    }

    /**
     * 生成分支式接入检查，不把平台分配通道和商户自建通道误写成串行步骤。
     *
     * @return array<string, mixed>
     */
    private function readiness(
        array $merchant,
        array $credential,
        int $platformChannelCount,
        int $selfChannelCount,
        int $pluginConfigCount,
        int $enabledChannelCount,
        bool $hasTestOrder
    ): array {
        $profileReady = trim((string) ($merchant['contact_name'] ?? '')) !== ''
            && trim((string) ($merchant['settlement_account_name'] ?? '')) !== ''
            && trim((string) ($merchant['settlement_account_no'] ?? '')) !== '';
        $credentialReady = !empty($credential['has_credential']);
        $selfChannelReady = $pluginConfigCount > 0 && $selfChannelCount > 0;
        $channelReady = $platformChannelCount > 0 || $selfChannelReady;

        return [
            'profile_ready' => $profileReady,
            'credential_ready' => $credentialReady,
            'platform_channel_count' => $platformChannelCount,
            'plugin_config_count' => $pluginConfigCount,
            'self_channel_count' => $selfChannelCount,
            'enabled_channel_count' => $enabledChannelCount,
            'channel_ready' => $channelReady,
            'route_ready' => $channelReady && $enabledChannelCount > 0,
            'test_order_ready' => $hasTestOrder,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function tasks(array $readiness, int $pendingSettlementCount): array
    {
        $tasks = [];
        if (!$readiness['profile_ready']) {
            $tasks[] = ['key' => 'profile', 'level' => 'warning', 'title' => '完善商户与结算资料', 'description' => '联系人或结算资料尚未填写完整。', 'path' => '/system/merchant-info'];
        }
        if (!$readiness['credential_ready']) {
            $tasks[] = ['key' => 'credential', 'level' => 'warning', 'title' => '开通接口凭证', 'description' => '开放接口签名尚未就绪。', 'path' => '/channel/api-credential'];
        }
        if (!$readiness['channel_ready']) {
            $tasks[] = ['key' => 'channel', 'level' => 'danger', 'title' => '准备收款通道', 'description' => '当前既没有平台分配通道，也没有完整的自建通道。', 'path' => '/channel/my-channel'];
        } elseif (!$readiness['route_ready']) {
            $tasks[] = ['key' => 'route', 'level' => 'danger', 'title' => '检查通道选择', 'description' => '已有通道但当前没有启用通道可用于收款。', 'path' => '/channel/route-preview'];
        }
        if ($readiness['route_ready'] && !$readiness['test_order_ready']) {
            $tasks[] = ['key' => 'test', 'level' => 'info', 'title' => '完成一次测试支付', 'description' => '建议在收款通道中发起小额测试，确认完整支付链路。', 'path' => '/channel/my-channel'];
        }
        if ($pendingSettlementCount > 0) {
            $tasks[] = ['key' => 'settlement', 'level' => 'info', 'title' => '查看待结算资金', 'description' => "当前有 {$pendingSettlementCount} 笔结算记录等待处理。", 'path' => '/transaction/settlement-record'];
        }

        return $tasks;
    }
}
