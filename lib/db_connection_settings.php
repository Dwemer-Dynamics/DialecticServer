<?php

if (!function_exists('dialecticDbSetting')) {
    function dialecticDbSetting(string $key, string $default): string
    {
        $envValue = getenv($key);
        if ($envValue !== false && trim(strval($envValue)) !== '') {
            return trim(strval($envValue));
        }

        if (isset($GLOBALS[$key]) && trim(strval($GLOBALS[$key])) !== '') {
            return trim(strval($GLOBALS[$key]));
        }

        return $default;
    }
}

if (!function_exists('dialecticPgConnectionValue')) {
    function dialecticPgConnectionValue(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $value) === 1) {
            return $value;
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}

if (!function_exists('dialecticDbConnectionSettings')) {
    function dialecticDbConnectionSettings(string $defaultDatabase = 'dialectic'): array
    {
        return [
            'host' => dialecticDbSetting('DIALECTIC_DB_HOST', '127.0.0.1'),
            'port' => dialecticDbSetting('DIALECTIC_DB_PORT', '5432'),
            'dbname' => dialecticDbSetting('DIALECTIC_DB_NAME', $defaultDatabase),
            'schema' => 'public',
            'username' => dialecticDbSetting('DIALECTIC_DB_USER', 'dwemer'),
            'password' => dialecticDbSetting('DIALECTIC_DB_PASSWORD', 'dwemer'),
        ];
    }
}

if (!function_exists('dialecticPgConnectionString')) {
    function dialecticPgConnectionString(array $settings): string
    {
        return implode(' ', [
            'host=' . dialecticPgConnectionValue(strval($settings['host'] ?? '127.0.0.1')),
            'port=' . dialecticPgConnectionValue(strval($settings['port'] ?? '5432')),
            'dbname=' . dialecticPgConnectionValue(strval($settings['dbname'] ?? 'dialectic')),
            'user=' . dialecticPgConnectionValue(strval($settings['username'] ?? 'dwemer')),
            'password=' . dialecticPgConnectionValue(strval($settings['password'] ?? 'dwemer')),
        ]);
    }
}

?>
