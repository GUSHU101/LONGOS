<?php

return [
    'title'                => 'LONGOS Global Translation',
    'preview'              => 'Content must be scanned, reviewed and published before it can be shown. No upstream provider request is sent without explicit operator verification and dispatch authorization.',
    'mode'                 => 'Mode',
    'dispatch'             => 'Provider requests',
    'disabled'             => 'Disabled',
    'embed_description'    => 'A presentation-language selector for published storefront content. It does not alter the original store locale, commerce, prices, or orders.',
    'choose_language'      => 'Choose display language',
    'presentation_only'    => 'Only published text changes. Orders, prices, and checkout stay unchanged.',
    'close'                => 'Close language selector',
    'recovery_badge'       => 'Safe repair pending',
    'recovery_title'       => 'The translation workspace is completing its upgrade',
    'recovery_description' => 'The translation schema is not fully ready. Translation jobs and the storefront selector are safely paused to protect source content, orders, and payments.',
    'recovery_connection'  => 'The translation schema could not be inspected. Confirm that the Coolify deployment and database connection are healthy, then reload this page.',
    'recovery_runtime'     => 'The translation workspace safely paused because one of its stored configuration values needs recovery. The detailed cause is recorded in the protected platform log.',
    'recovery_missing'     => 'Items that the automatic upgrade still needs to restore:',
    'recovery_step_deploy' => 'Redeploy LONGOS from the main branch in Coolify.',
    'recovery_step_wait'   => 'Wait for saas:upgrade and the plugin migrations to complete in the deployment log.',
    'recovery_step_reload' => 'Open this page again. Do not run Composer or migrations manually in the production container.',
    'recovery_safety'      => 'This state does not change the core locale, products, cart, checkout, payments, or order data.',
];
