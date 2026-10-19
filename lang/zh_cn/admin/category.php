<?php

/**
 * order.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-08-02 14:22:41
 * @modified   2022-08-02 14:22:41
 */

return [
    'index'              => '分类',
    'edit_category'      => '编辑分类',
    'parent_category'    => '上级分类',
    'category_image'     => '分类图片',

    'categories_index'                => '分类列表',
    'categories_create'               => '创建分类',
    'categories_show'                 => '分类详情',
    'categories_update'               => '更新分类',
    'categories_delete'               => '删除分类',
    'smart_add_products'              => '智能自动归类',
    'smart_title'                     => '智能选品：:category',
    'smart_description'               => '仅根据“商品名称关键词”或“选定品牌”在当前租户内进行匹配，不读取商品描述，也不向外部 AI 发送数据。',
    'smart_name_keywords'             => '商品名称关键词',
    'smart_name_keywords_placeholder' => '多个关键词用逗号或换行分隔，例：跑鞋, sneaker',
    'smart_brands'                    => '指定品牌',
    'smart_brands_placeholder'        => '可选择多个品牌',
    'smart_auto_enabled'              => '对以后新建或修改的商品自动归类',
    'smart_auto_help'                 => '只要商品名称命中任一关键词，或商品品牌在选定列表中，即自动加入本分类。',
    'smart_save_rule'                 => '仅保存自动规则',
    'smart_rule_saved'                => '自动归类规则已保存。',
    'smart_rule_required'             => '启用自动归类时，请至少填写一个商品名称关键词或选择一个品牌。',
    'smart_invalid_brands'            => '选中品牌不存在或不属于当前租户。',
    'smart_match_reason'              => '匹配依据',
    'smart_match_name'                => '名称：:value',
    'smart_match_brand'               => '品牌：:value',
    'smart_confidence'                => '匹配强度',
    'smart_strict'                    => '严格（更准确）',
    'smart_balanced'                  => '平衡（推荐）',
    'smart_broad'                     => '宽松（更多结果）',
    'smart_rescan'                    => '重新分析',
    'smart_select_all'                => '全选结果',
    'smart_clear'                     => '清空选择',
    'smart_product'                   => '商品',
    'smart_score'                     => '匹配分',
    'smart_keywords'                  => '命中关键词',
    'smart_status'                    => '状态',
    'smart_summary'                   => '已扫描 :scanned / :eligible 个候选商品，找到 :matches 个匹配，当前显示 :returned 个。',
    'smart_truncated'                 => '匹配商品较多，当前仅显示排名靠前的结果，可减少关键词或品牌后重新分析。',
    'smart_empty'                     => '暂未找到名称命中关键词或属于选定品牌的商品。',
    'smart_apply'                     => '添加选中商品',
    'smart_select_required'           => '请至少选择一个候选商品。',
    'smart_preview_ready'             => '智能选品分析完成。',
    'smart_added'                     => '已将 :count 个商品加入该分类。',
    'smart_category_disabled'         => '请先启用该分类，再执行智能选品。',
    'smart_upgrade_pending'           => '智能自动归类正在完成自动升级，分类列表仍可正常使用，请稍后刷新。',
];
