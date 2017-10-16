<?php
class PHPServerConfig
{
    public $fileName;
    public $config;

    private function __construct($domain = 'main')
    {
        $folder = __DIR__ . '/../config';
        $fileName = $folder . '/' . $domain . '.php';

        if (!file_exists($fileName)) {
            throw new Exception('Configuration file "' . $fileName . '" not found');
        }

        $config = include $fileName;
        if (!is_array($config) || empty($config)) {
            throw new Exception('Invalid configuration file format');
        }

        $this->fileName = $fileName;
        $this->config = $config;
    }

    public static function instance($domain = 'main')
    {
        static $instances = array();

        if (empty($instances[$domain])) {
            $instances[$domain] = new self;
        }

        return $instances[$domain];
    }

    public static function get($uri, $domain = 'main')
    {
        $node = self::instance($domain)->config;
        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return null;
            }
            $node = $node[$path];
        }

        return $node;
    }
}