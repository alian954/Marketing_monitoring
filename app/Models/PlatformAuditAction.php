<?php

namespace App\Models;

enum PlatformAuditAction: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationUpdated = 'organization.updated';
    case OrganizationOpenedByPlatformAdmin = 'organization.opened_by_platform_admin';
    case ProductLifecycleUpdated = 'product.lifecycle_updated';
    case ProductPlanCreated = 'product_plan.created';
    case ProductPlanStatusUpdated = 'product_plan.status_updated';
    case SubscriptionCreated = 'subscription.created';
    case SubscriptionUpdated = 'subscription.updated';
    case SubscriptionItemCreated = 'subscription_item.created';
    case SubscriptionItemUpdated = 'subscription_item.updated';
    case MemberInvitationCreated = 'member.invitation_created';
    case MemberInvitationRevoked = 'member.invitation_revoked';
    case MemberInvitationAccepted = 'member.invitation_accepted';
    case MemberRoleUpdated = 'member.role_updated';
    case MemberClientScopeUpdated = 'member.client_scope_updated';
    case MemberRevoked = 'member.revoked';
    case BillingOrderCreated = 'billing.order_created';
    case BillingOrderCanceled = 'billing.order_canceled';
    case BillingPaymentRecorded = 'billing.payment_recorded';
    case BillingRefundRecorded = 'billing.refund_recorded';
    case TrialRequested = 'trial.requested';
    case TrialApproved = 'trial.approved';
    case TrialRejected = 'trial.rejected';

    public function label(): string
    {
        return match ($this) {
            self::OrganizationCreated => '建立組織',
            self::OrganizationUpdated => '更新組織設定',
            self::OrganizationOpenedByPlatformAdmin => '平台管理員進入組織',
            self::ProductLifecycleUpdated => '變更產品狀態',
            self::ProductPlanCreated => '建立產品方案',
            self::ProductPlanStatusUpdated => '變更方案狀態',
            self::SubscriptionCreated => '建立訂閱合約',
            self::SubscriptionUpdated => '更新訂閱合約',
            self::SubscriptionItemCreated => '新增訂閱產品項目',
            self::SubscriptionItemUpdated => '更新訂閱產品項目',
            self::MemberInvitationCreated => '建立成員邀請',
            self::MemberInvitationRevoked => '撤銷成員邀請',
            self::MemberInvitationAccepted => '成員接受邀請',
            self::MemberRoleUpdated => '變更成員權限',
            self::MemberClientScopeUpdated => '變更成員客戶範圍',
            self::MemberRevoked => '撤銷成員存取',
            self::BillingOrderCreated => '開立訂單',
            self::BillingOrderCanceled => '取消訂單',
            self::BillingPaymentRecorded => '登錄收款',
            self::BillingRefundRecorded => '登錄退款',
            self::TrialRequested => '申請產品試用',
            self::TrialApproved => '核准產品試用',
            self::TrialRejected => '婉拒產品試用',
        };
    }
}
