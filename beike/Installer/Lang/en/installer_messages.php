<?php

return [

    /*
     *
     * Shared translations.
     *
     */
    'title'        => 'LONGOS Installer',
    'next'         => 'Next Step',
    'back'         => 'Previous',
    'finish'       => 'Install',
    'status'       => 'Status',
    'forms'        => [
        'errorTitle' => 'The Following errors occurred:',
    ],

    /*
     *
     * Home page translations.
     *
     */
    'welcome'      => [
        'template_title'   => 'Welcome',
        'title'            => 'Welcome',
        'describe'         => 'Welcome to LONGOS Commerce SaaS. This wizard will verify the environment and configure your platform.',
        'message'          => 'Easy Installation and Setup Wizard.',
        'next'             => 'Check Requirements',
        'copyright_btn'    => 'I have read the agreement and agree',
        'copyright_title'  => 'Copyright Description',
        'copyright_list_1' => '1. LONGOS is the product identity and copyright holder for LONGOS-specific original work and modifications.',
        'copyright_list_2' => '2. The platform is derived from BeikeShop. Upstream copyright, attribution notices and third-party rights remain with their respective owners.',
        'copyright_list_3' => '3. Distribution and external deployment are governed by OSL-3.0. Retain all required source notices and attribution.',
        'statement_1'      => 'Disclaimer:',
        'statement_2'      => 'Risk statement: You install and operate LONGOS at your own discretion. The software is provided without warranty to the extent permitted by the OSL-3.0 license and applicable law.',
        'statement_3'      => 'Legal compliance: When using this website system, you agree not to engage in any illegal, infringing or violating local laws and regulations in business activities. We do not assume any responsibility for your business behavior',
        'statement_4'      => 'Data loss and hacker attacks: Although we have taken reasonable security measures to protect the data and security of the system, the risk of information transmission loss and hacker attacks still exists. We are not responsible for data loss, hacker attacks, or other security incidents in the system. You are responsible for taking measures to protect data and system security on your own',
    ],

    /*
     *
     * Requirements page translations.
     *
     */
    'requirements' => [
        'template_title' => 'Step 1 | Server Requirements',
        'environment'    => 'Environmen',
        'title'          => 'Server Requirements',
        'next'           => 'Check Permissions',
    ],

    /*
     *
     * Permissions page translations.
     *
     */
    'permissions'  => [
        'template_title' => 'Step 2 | Directory Permission',
        'title'          => 'Directory permission detection',
        'next'           => 'Configure Environment',
        'table'          => 'Table of contents',
    ],

    /*
     *
     * Environment page translations.
     *
     */
    'environment'  => [
        'template_title'                       => 'Step 3 | System Parameters',
        'title'                                => 'SaaS platform configuration',
        'name_required'                        => 'An environment name is required.',
        'database_link'                        => 'Database link',
        'admin_info'                           => 'Set admin account password',
        'app_name_label'                       => 'App Name',
        'app_name_placeholder'                 => 'App Name',
        'app_environment_label'                => 'App Environment',
        'db_connection_failed_host_port'       => 'Database host or port error!',
        'db_connection_failed_user_password'   => 'Database username or password error!',
        'db_connection_failed_database_name'   => 'Database name not exist or error!',
        'db_connection_failed_invalid_version' => 'MySQL version must grater than 5.7!',
        'app_environment_label_local'          => 'Local',
        'app_environment_label_developement'   => 'Development',
        'app_environment_label_qa'             => 'Qa',
        'app_environment_label_production'     => 'Production',
        'app_environment_label_other'          => 'Other',
        'app_environment_placeholder_other'    => 'Enter your environment...',
        'app_url_label'                        => 'App Url',
        'app_url_placeholder'                  => 'App Url',
        'db_connection_failed'                 => 'Could not connect to the database.',
        'env_write_failed'                     => 'Unable to write the .env configuration file. Check the project root write permission.',
        'installation_session_expired'         => 'The installation session has expired. Return to the system parameters page and submit it again.',
        'installation_failed'                  => 'Installation was interrupted unexpectedly. Return to the configuration page and retry; the installer will resume from the completed stage.',
        'insufficient_memory_limit'            => 'The current PHP memory_limit is :current; web installation requires at least :required. Increase the container memory limit and redeploy.',
        'operation_lock_failed'                => 'The installer operation lock could not be created. Check write permissions for storage/app.',
        'operation_in_progress'                => 'Another installation request is still running. Refresh later and do not click Install repeatedly.',
        'finalization_failed'                  => 'The installation data is complete, but the final page could not be generated. Retry and the installer will resume safely.',
        'db_connection_label'                  => 'Database Connection',
        'db_connection_label_mysql'            => 'mysql',
        'db_connection_label_sqlite'           => 'sqlite',
        'db_connection_label_pgsql'            => 'pgsql',
        'db_connection_label_sqlsrv'           => 'sqlsrv',
        'db_host_label'                        => 'Database Host',
        'db_host_placeholder'                  => 'Database Host',
        'db_port_label'                        => 'Database Port',
        'db_port_placeholder'                  => 'Database Port',
        'db_name_label'                        => 'Database Name',
        'db_name_placeholder'                  => 'Database Name',
        'db_username_label'                    => 'Database User Name',
        'db_username_placeholder'              => 'Database User Name',
        'db_password_label'                    => 'Database Password',
        'db_password_placeholder'              => 'Database Password',
        'saas_info'                            => 'SaaS tenant configuration',
        'saas_info_help'                       => 'SaaS is enabled after installation. Create tenants in the admin panel and LONGOS assigns their subdomains automatically.',
        'saas_database_mode'                   => 'Tenant data isolation',
        'saas_database_mode_prefix'            => 'Simple mode (recommended) — reuse this database, no root required',
        'saas_database_mode_prefix_help'       => 'Each tenant receives an isolated table prefix and reuses the database account above.',
        'saas_database_mode_database'          => 'Advanced mode — separate database per tenant',
        'saas_database_mode_database_help'     => 'Stronger isolation, but requires an account allowed to create databases and grant access.',
        'saas_prefix_mode_help'                => 'Recommended: one database account is enough; LONGOS creates an isolated table namespace for each future tenant.',
        'https_recommended'                    => 'This page is using HTTP. Production should be deployed through Coolify and Caddy, then initialized over HTTPS.',
        'web_installer_tls_notice'             => 'The browser wizard cannot configure the server-wide proxy. Use the Coolify Compose deployment in production; containers initialize the database automatically and Coolify Caddy manages control and tenant HTTPS.',
        'saas_base_domain'                     => 'Tenant base domain',
        'saas_base_domain_help'                => 'For example, example.com gives tenant brand1 the domain brand1.example.com.',
        'saas_database_prefix'                 => 'Dedicated tenant database name prefix',
        'saas_dns_target'                      => 'Wildcard DNS target',
        'saas_dns_target_help'                 => 'Server public IP or hostname. Point the *.:domain A/CNAME record to this value after installation.',
        'saas_tenant_admin_username'           => 'Provisioning database administrator',
        'saas_tenant_admin_password'           => 'Provisioning database password',
        'saas_tenant_admin_help'               => 'Used to create and grant tenant databases. The account needs CREATE DATABASE and GRANT privileges. If it matches the runtime database user, a blank password reuses the database password above.',
        'saas_admin_connection_failed'         => 'Could not connect with the provisioning database administrator account.',
        'saas_admin_permission_failed'         => 'The provisioning database administrator lacks database creation or grant privileges.',
        'install_state_write_failed'           => 'Could not save the resumable installation state. Check write permissions for storage/app.',
        'admin_email'                          => 'Admin User Account',
        'admin_password'                       => 'Admin Password',
        'admin_password_confirmation'          => 'Confirm Admin Password',
        'admin_password_confirmation_mismatch' => 'Admin password confirmation does not match.',
        'install'                              => 'Install',
        'ajax_database_parameters'             => 'Check database parameters...',
        'ajax_database_success'                => 'Database connection succeeded',
        'error_email'                          => 'Please fill in the correct email address',
        'table_already_exists'                 => 'Found data tables in the database, please clear or backup them before installing!',
        'php_extension'                        => 'Please install the PHP extension',
        'down_phpversion'                      => 'Install or switch to PHP 8.2 or later',
    ],

    'installing'   => [
        'template_title'  => 'Installing LONGOS',
        'title'           => 'Installing LONGOS Commerce SaaS',
        'progress'        => 'Installation progress',
        'schema'          => 'Creating and upgrading the database schema…',
        'schema_complete' => 'Database schema migration completed.',
        'seed'            => 'Loading the store, country and regional base data…',
        'administrator'   => 'Creating the platform administrator…',
        'saas'            => 'Registering the SaaS control site and domain…',
        'finalize'        => 'Enabling tenant routing and finalizing configuration…',
        'keep_open'       => 'Keep this page open. Each phase is saved separately, so an interrupted installation can resume safely without repeating completed work.',
        'continue'        => 'Continue installation',
    ],

    /*
     *
     * Installed Log translations.
     *
     */
    'installed'    => [
        'success_log_message' => 'LONGOS successfully installed on ',
    ],

    /*
     *
     * Final page translations.
     *
     */
    'final'        => [
        'title'            => 'Installation Finished',
        'template_title'   => 'Installation Finished',
        'migration'        => 'Migration &amp; Seed Console Output:',
        'console'          => 'Application Console Output:',
        'log'              => 'Installation Log Entry:',
        'env'              => 'Final .env File:',
        'exit'             => 'Click here to exit',
        'finished'         => 'Congratulations, the system is successfully installed, let\'s experience it now',
        'database_binding' => 'Connected database: :connection · :host / :database',
        'saas_ready'       => 'The SaaS control site is enabled. You can now create tenant sites from the admin panel.',
        'wildcard_dns'     => 'Add this DNS record: *.:domain → :target',
        'wildcard_server'  => 'Point the *.:domain wildcard DNS record to the Coolify server; Caddy issues exact-domain certificates on demand.',
        'server_public_ip' => 'server public IP',
        'to_front'         => 'Shop',
        'to_admin'         => 'Admin Panel',
        'nginx_alert'      => 'Nginx rewrite rules are required. See the <a href="https://docs.beikeshop.com/install/source.html#nginx" target="_blank">upstream compatibility guide</a>.',
    ],

    /*
     *
     * Update specific translations
     *
     */
    'updater'      => [
        /*
         *
         * Shared translations.
         *
         */
        'title'    => 'LONGOS Updater',

        /*
         *
         * Welcome page translations for update feature.
         *
         */
        'welcome'  => [
            'title'   => 'Welcome To The Updater',
            'message' => 'Welcome to the update wizard.',
        ],

        /*
         *
         * Welcome page translations for update feature.
         *
         */
        'overview' => [
            'title'           => 'Overview',
            'message'         => 'There is 1 update.|There are :number updates.',
            'install_updates' => 'Install Updates',
        ],

        /*
         *
         * Final page translations.
         *
         */
        'final'    => [
            'title'    => 'Finished',
            'finished' => 'Application\'s database has been successfully updated.',
            'exit'     => 'Click here to exit',
        ],

        'log'      => [
            'success_message' => 'LONGOS successfully updated on ',
        ],
    ],
];
