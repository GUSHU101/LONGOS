<?php

/**
 * zone.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-29 17:21:38
 * @modified   2022-08-29 17:21:38
 */
return [
    'zone_name'         => '省份名称',
    'country_of_origin' => '所属国家/地区',

    'zones_index'       => '省份列表',
    'zones_create'      => '创建省份',
    'zones_update'      => '更新省份',
    'zones_delete'      => '删除省份',

    'error_country'          => '请选择国家/地区',
    'zones_default_disabled' => '默认省份不能禁用',
    'batch_enable'           => '批量启用',
    'batch_disable'          => '批量停用',
    'batch_delete'           => '批量删除',
    'batch_confirm'          => '确定要对所选省份执行此操作吗？',
    'batch_result'           => '已处理 :processed 项，跳过 :skipped 项。',
    'selected_count'         => '已选择',
    'select_page'            => '选择本页全部省份',
    'page_selected'          => '已选择本页全部省份。',
    'select_all_filtered'    => '选择全部筛选结果',
    'all_filtered_selected'  => '已选择全部筛选结果',
    'select_page_only'       => '仅选择本页',
    'skipped_items'          => '以下项目未被更改',
    'sync_now'               => '立即同步目录',
    'sync_help'              => 'LONGOS 每周一自动同步随版本提供的省份目录，并保留您设置的启用状态和排序。',
    'sync_result'            => '省份目录同步完成：新增 :created，更新 :updated，未变化 :unchanged，跳过 :skipped。',
    'sync_failed'            => '目录同步未能完成，现有省份数据未被删除，请稍后重试。',
    'default_cannot_disable' => '默认省份不能停用。',
    'default_cannot_delete'  => '默认省份不能删除。',
    'in_use_cannot_delete'   => '该省份已被地址、订单或区域规则使用，请改为停用。',
    'select_required'        => '请至少选择一个省份，或选择全部筛选结果。',
    'no_matching_records'    => '当前筛选条件没有匹配的省份。',
];
