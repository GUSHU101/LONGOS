<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Requirements
    |--------------------------------------------------------------------------
    |
    | This is the default Laravel server requirements, you can add as many
    | as your application require, we check if the extension is enabled
    | by looping through the array and run "extension_loaded" on it.
    |
    */
    'core'                   => [
        'minPhpVersion' => '8.2',
    ],
    'runtime'                => [
        // Web installation boots Laravel's console kernel for migration,
        // seeding and SaaS provisioning in one PHP-FPM request.
        'memory_limit'                => '512M',
        'minimum_memory_bytes'        => 256 * 1024 * 1024,
        'max_execution_seconds'       => 0,
        // A second browser request must fail fast and show the resumable
        // progress page instead of appearing to spin for several minutes.
        'operation_lock_wait_seconds' => 3,
    ],
    'final'                  => [
        'key'     => true,
        'publish' => false,
    ],
    'requirements'           => [
        'php'    => [
            'BCMath',
            'Ctype',
            'cURL',
            'DOM',
            'Intl',
            'Fileinfo',
            'JSON',
            'Mbstring',
            'OpenSSL',
            'PCRE',
            'PDO',
            'Tokenizer',
            'XML',
            'ZIP',
            'GD',
            'Iconv',
            'SimpleXML',
        ],
        'apache' => [
            'mod_rewrite',
        ],
        'db' => [
            'mysql' => 'PDO_MYSQL',
            'pgsql' => 'PDO_PGSQL',

        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Folders Permissions
    |--------------------------------------------------------------------------
    |
    | This is the default Laravel folders permissions, if your application
    | requires more permissions just add them to the array list bellow.
    |
    */
    'permissions'            => [
        '.env'                => 'writable file (0600 recommended)',
        'bootstrap/cache/'    => 'writable directory',
        'public/cache/'       => 'writable directory',
        'public/plugin/'      => 'writable directory',
        'storage/app/'        => 'writable directory',
        'storage/framework/'  => 'writable directory',
        'storage/logs/'       => 'writable directory',
    ],

    /*
    |--------------------------------------------------------------------------
    | Environment Form Wizard Validation Rules & Messages
    |--------------------------------------------------------------------------
    |
    | This are the default form field validation rules. Available Rules:
    | https://laravel.com/docs/5.4/validation#available-validation-rules
    |
    */
    'environment'            => [
        'form' => [
            'rules' => [
                'database_connection'         => 'required|in:mysql,pgsql',
                'database_hostname'           => 'required|string|max:50',
                'database_port'               => 'required|numeric',
                'database_name'               => 'required|string|max:50',
                'database_username'           => 'required|string|max:50',
                'database_password'           => 'nullable|string|max:50',
                'saas_database_mode'          => 'required|in:prefix,database',
                'saas_base_domain'            => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
                'saas_database_prefix'        => ['required', 'string', 'max:32', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
                'saas_dns_target'             => 'nullable|string|max:253',
                'saas_tenant_admin_username'  => 'nullable|required_if:saas_database_mode,database|string|max:50',
                'saas_tenant_admin_password'  => 'nullable|string|max:200',
                'admin_email'                 => 'required|email:rfc',
                'admin_password'              => 'required|string|min:8|max:50|confirmed',
                'admin_password_confirmation' => 'required|string|min:8|max:50',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Installed Middleware Options
    |--------------------------------------------------------------------------
    | Different available status switch configuration for the
    | canInstall middleware located in `canInstall.php`.
    |
    */
    'installed'              => [
        'redirectOptions' => [
            'route' => [
                'name' => 'welcome',
                'data' => [],
            ],
            'abort' => [
                'type' => '404',
            ],
            'dump'  => [
                'data' => 'Dumping a not found message.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Selected Installed Middleware Option
    |--------------------------------------------------------------------------
    | The selected option fo what happens when an installer instance has been
    | Default output is to `/resources/views/error/404.blade.php` if none.
    | The available middleware options include:
    | route, abort, dump, 404, default, ''
    |
    */
    'installedAlreadyAction' => '',

    /*
    |--------------------------------------------------------------------------
    | Updater Enabled
    |--------------------------------------------------------------------------
    | Can the application run the '/update' route with the migrations.
    | The default option is set to False if none is present.
    | Boolean value
    |
    */
    'updaterEnabled'         => 'true',

];
