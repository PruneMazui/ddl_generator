<?php
namespace PruneMazui\DdlGenerator;

abstract class AbstractDdlGenerator
{
    protected static $defaultConfig = [];

    protected $config = [];

    /**
     * @param array optional $config
     */
    public function __construct(array $config = null)
    {
        if(! is_null($config)) {
            $this->setConfig($config);
        }
    }

    /**
     * Set Config
     * @param array $config
     * @return \PruneMazui\DdlGenerator\Reader\AbstractReader
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Get Config
     * @param config $key
     * @return mixed
     */
    public function getConfig($key = null)
    {
        $config = $this->config + static::$defaultConfig;

        if(is_null($key)) {
            return $config;
        }

        $key_list = explode(".", $key);

        foreach($key_list as $key) {
            if(! isset($config[$key])) {
                return null;
            }
            $config = $config[$key];
        }

        return $config;
    }
}
