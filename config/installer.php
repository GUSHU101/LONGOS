<?php

/*
 * Keep the installer configuration in Laravel's normal config directory so
 * it is included in config:cache. The installer provider also has a runtime
 * fallback for deployments carrying an old cached configuration.
 */

return require base_path('beike/Installer/config.php');
