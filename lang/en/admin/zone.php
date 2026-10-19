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
    'zone_name'         => 'Province Name',
    'country_of_origin' => 'Country/Region Of Origin',

    'zones_index'            => 'Province List',
    'zones_create'           => 'Create Province',
    'zones_update'           => 'Update Province',
    'zones_delete'           => 'Delete Province',
    'zones_default_disabled' => 'Default province cannot be disabled',

    'error_country'          => 'Please select a Country/Region',
    'batch_enable'           => 'Enable selected',
    'batch_disable'          => 'Disable selected',
    'batch_delete'           => 'Delete selected',
    'batch_confirm'          => 'Apply this action to all selected provinces?',
    'batch_result'           => ':processed processed, :skipped skipped.',
    'selected_count'         => 'Selected',
    'select_page'            => 'Select all provinces on this page',
    'page_selected'          => 'Every province on this page is selected.',
    'select_all_filtered'    => 'Select every matching province',
    'all_filtered_selected'  => 'All matching provinces selected',
    'select_page_only'       => 'Use this page only',
    'skipped_items'          => 'Items not changed',
    'sync_now'               => 'Sync catalog now',
    'sync_help'              => 'LONGOS automatically synchronizes its bundled province catalog every Monday. Existing enabled/disabled choices and sort order are preserved.',
    'sync_result'            => 'Province catalog synchronized: :created added, :updated updated, :unchanged unchanged, :skipped skipped.',
    'sync_failed'            => 'Catalog synchronization could not be completed. Existing province data was not deleted; please try again later.',
    'default_cannot_disable' => 'The default province cannot be disabled.',
    'default_cannot_delete'  => 'The default province cannot be deleted.',
    'in_use_cannot_delete'   => 'This province is used by an address, order, or region rule. Disable it instead.',
    'select_required'        => 'Select at least one province or choose all filtered results.',
    'no_matching_records'    => 'No provinces match the current filters.',
];
