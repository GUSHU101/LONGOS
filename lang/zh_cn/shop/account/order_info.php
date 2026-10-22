<?php

/**
 * account.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-04 10:59:15
 * @modified   2022-08-04 10:59:15
 */

return [
    'index'             => '订单详情',
    'order_details'     => '订单详情',
    'to_pay'            => '去支付',
    'cancel'            => '取消订单',
    'confirm_receipt'   => '确认收货',
    'order_number'      => '订单号',
    'order_date'        => '下单日期',
    'state'             => '状态',
    'order_amount'      => '订单金额',
    'order_items'       => '订购商品',
    'apply_after_sales' => '申请售后',
    'order_total'       => '合计订单',
    'logistics_status'  => '物流状态',
    'order_status'      => '订单状态',
    'remark'            => '备注',
    'update_time'       => '更新时间',
    'order_summary'          => '订单概览',
    'back_to_orders'         => '返回订单列表',
    'request_refund'         => '申请取消并退款',
    'cancel_prompt'          => '确定取消这个待支付订单吗？已预留的库存将被释放。',
    'refund_prompt'          => '确定为这个已支付订单提交取消和退款申请吗？退款完成可能需要商家审核。',
    'confirm_receipt_prompt' => '确认已经收到这个订单吗？此操作无法撤销。',
    'processing'             => '处理中…',
    'action_failed'          => '订单更新失败，请检查最新状态后重试。',
    'action_unavailable'     => '订单当前状态已不允许执行此操作。',
    'session_required'       => '为保障订单安全，支付和状态变更只能在下单时的浏览器会话中操作；会员订单请先登录。',
    'not_available'          => '暂无',
    'not_required'           => '无需配送',
    'track_number'           => '在新窗口追踪物流单号 :number',
    'tracking_pending'       => '物流追踪链接待更新',
    'current_status'         => '当前状态',
    'no_remark'              => '暂无补充说明',
    'status_hint_created'    => '订单正在生成支付信息。',
    'status_hint_unpaid'     => '订单仍需支付，完成付款后将优先进入处理流程。',
    'status_hint_paid'       => '付款已确认，商家正在备货并准备发出。',
    'status_hint_shipped'    => '订单已在配送途中，请通过下方物流信息查询，并在实际收货后确认。',
    'status_hint_completed'  => '订单已完成，符合条件的商品仍可申请售后。',
    'status_hint_cancelled'  => '订单已取消，无需继续处理。',
    'status_hint_refunding'  => '取消或退款申请正在处理中，完成时间取决于商家及支付渠道审核。',
];
