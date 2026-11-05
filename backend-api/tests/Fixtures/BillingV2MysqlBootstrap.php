<?php

$backend = dirname(__DIR__, 2);
if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_PORT') !== '13368' || getenv('DB_DATABASE') !== 'beehouse_billing_v2_test' || getenv('DB_USERNAME') !== 'billing_test'
    || getenv('DB_URL') !== false && getenv('DB_URL') !== '') {
    throw new RuntimeException('Only the isolated loopback Billing V2 MySQL instance is allowed.');
}
if (getenv('BILLING_V2_ENABLED') === false) {
    putenv('BILLING_V2_ENABLED=true');
    $_ENV['BILLING_V2_ENABLED'] = $_SERVER['BILLING_V2_ENABLED'] = 'true';
}
if (is_file($backend.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Cached configuration is not allowed for isolated tests.');
}
$_ENV['APP_BASE_PATH'] = $backend;
$_SERVER['APP_BASE_PATH'] = $backend;
$loader = require $backend.'/vendor/autoload.php';
$loader->addPsr4('App\\', $backend.'/app', true);
$loader->addPsr4('Tests\\', $backend.'/tests', true);
$loader->addPsr4('Database\\Factories\\', $backend.'/database/factories', true);
$loader->addPsr4('Database\\Seeders\\', $backend.'/database/seeders', true);
spl_autoload_register(static function (string $class) use ($backend): void {
    foreach (['App\\' => 'app', 'Tests\\' => 'tests', 'Database\\Factories\\' => 'database/factories', 'Database\\Seeders\\' => 'database/seeders'] as $prefix => $directory) {
        if (str_starts_with($class, $prefix)) {
            $file = $backend.'/'.$directory.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);
