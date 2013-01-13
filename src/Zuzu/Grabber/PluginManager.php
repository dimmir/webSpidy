<?php

namespace ZuZu\Grabber;

use Guzzle\Http\ClientInterface;
use Guzzle\Common\Log\MonologLogAdapter;
use Guzzle\Http\Plugin\LogPlugin;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Doctrine\Common\Cache\FilesystemCache;
use Guzzle\Common\Cache\DoctrineCacheAdapter;
use Guzzle\Http\Plugin\CachePlugin;
use Guzzle\Http\Plugin\CookiePlugin;
use Guzzle\Http\CookieJar\ArrayCookieJar;
use Guzzle\Http\Plugin\CurlAuthPlugin;
use Guzzle\Http\Plugin\ExponentialBackoffPlugin;
use Guzzle\Http\Plugin\AsyncPlugin;

/**
 * Plugin Manager for \Guzzle\Http\Client
 */
class PluginManager
{
    /**
     * @var \Guzzle\Http\Client; 
     */
    protected $client;

    /**
     * @var LogPlugin
     */
    protected $logging;

    /**
     * @var CachePlugin
     */
    protected $caching;

    /**
     * @var CookiePlugin
     */
    protected $cookie;

    /**
     * @var CurlAuthPlugin
     */
    protected $auth;

    /**
     * @var ExponentialBackoffPlugin
     */
    protected $backoff;

    /**
     * @var AsyncPlugin
     */
    protected $async;


    protected $attachedPlugins = array();

    protected $cacheDir = 'cache/';
    protected $lifetimeCache;
    protected $cacheRevalidate;
    protected $logFile = 'log/grabber.log';
    protected $logSetting;
    protected $authUsername;
    protected $authPassword;
    protected $authScheme;

    /**
     * @param string $name property or plugin name
     * @return mixed
     * @throws \InvalidArgumentException
     */
    function __get($name)
    {
        $getter = 'get'.$name;
        if (method_exists($this,$getter)){
            return $this->$getter();
        }
        throw new \InvalidArgumentException(sprintf("property or plugin with '%s' is not defined",$name));
    }

    /**
     * @param string $name property or plugin name
     * @param mixed $value property value or true/false for attach/detach plugin
     * or replace instance of some plugin
     * @throws \InvalidArgumentException
     */
    function __set($name, $value)
    {
        if (!$this->parseAttach($name, $value)){
            $setter = 'set'.$name;
            if (method_exists($this,$setter)){
                $this->$setter($value);
            }else{
                throw new \InvalidArgumentException(sprintf("property or plugin with '%s' is not defined",$name));
            }
        }
    }

    private function parseAttach($name,$value)
    {
        if (method_exists($this,'attach'.$name)){
            if (is_bool($value)){
                if ($value){
                   $this->attach($name);
                }else{
                   $this->detach($name);
                }
            }else{
                call_user_func(array($this,'attach'.$name),$value);
            }
            return true;
        }
        return false;
    }

    /**
     * @param \Guzzle\Http\ClientInterface $client
     * @param $config
     * @param string $strategy The scheme of the object creation
     *      - grabber
     *      - spider
     *      - debug
     * @param array $config Configuration data:
     *      - configuring plugins (cacheDir,logFile, etc.)
     *      - attach/detach plugin as array('namePlugin' => true/false)
     *          (e.g. array('logging' => false))
     * @throws \InvalidArgumentException
     */
    function __construct(ClientInterface $client, $config=array(), $strategy = null)
    {
        if (!is_array($config)) {
            throw new \InvalidArgumentException('$config must be an array');
        }

        $this->client = $client;
        $this->build($strategy);
        $this->parseConfig($config);
    }

    /**
     * configures the Client according to strategy $strategy
     *
     * @param string $strategy
     */
    protected function build($strategy)
    {
        if ($strategy=='grabber' || $strategy=='spider' || $strategy=='debug'){
            $this->attachCookie();
        }
        if ($strategy=='spider'){
            $this->setLogSetting(LogPlugin::LOG_CONTEXT);
            $this->attachLogging();
        }
        if ($strategy=='debug'){
            $this->setLogSetting(LogPlugin::LOG_HEADERS);
            $this->attachLogging();
            $this->attachCaching();
        }
    }

    private function parseConfig($config)
    {
        foreach($config as $key => $value){
            if (!$this->parseAttach($key, $value)){
                $this->$key = $value;
            }

        }
    }

    /**
     * @return array An array of names of plugins
     */
    public function getAttachedPlugins()
    {
        return $this->attachedPlugins;   
    }

    /**
     * @param string $name plugin name
     * @return bool
     */
    public  function hasPlugin($name)
    {
        return in_array(strtolower($name),$this->attachedPlugins);
    }

    /**
     * Add plugin to the Client
     *
     * @param string $name plugin name
     */
    public  function attach($name)
    {
         if (!$this->hasPlugin($name)){
            $plugin = call_user_func(array($this,'get'.$name));
            $this->client->addSubscriber($plugin);
            $this->attachedPlugins[] = strtolower($name);
         }
    }

    /**
     * Remove plugin from Client
     *
     * @param string $name plugin name
     */
    public function detach($name)
    {
        if ($this->hasPlugin($name)){
            $this->client->getEventDispatcher()->removeSubscriber($this->$name);
            $key = array_search(strtolower($name), $this->attachedPlugins);
            unset($this->attachedPlugins[$key]);
         }
    }
    
    /**
     * @param \Guzzle\Http\Plugin\AsyncPlugin $async
     * @return \ZuZu\Grabber\PluginManager
     */
    public function attachAsync($async=null)
    {
        if ($async){
            $this->detach('async');
            $this->async = $async;
        }
        $this->attach('async');
        return $this;
    }

    /**
     * @return \Guzzle\Http\Plugin\AsyncPlugin
     */
    public function getAsync()
    {
        if (!$this->async){
            $this->async = new AsyncPlugin();
        }
        return $this->async;
    }

    /**
     * @param \Guzzle\Http\Plugin\CurlAuthPlugin $auth
     * @return \ZuZu\Grabber\PluginManager
     */
    public function attachAuth($auth=null)
    {
        if ($auth){
            $this->detach('auth');
            $this->auth = $auth;
        }
        $this->attach('auth');

        return $this;
    }

    /**
     * @return \Guzzle\Http\Plugin\CurlAuthPlugin
     */
    public function getAuth()
    {
        if (!$this->auth){
            $this->auth = new CurlAuthPlugin($this->getAuthUsername(), $this->getAuthPassword(), $this->getAuthScheme());
        }
        return $this->auth;
    }

    /**
     * @param \Guzzle\Http\Plugin\ExponentialBackoffPlugin $backoff
     * @return \ZuZu\Grabber\PluginManager
     */
    public function attachBackoff($backoff=null)
    {
        if ($backoff){
            $this->detach('backoff');
            $this->backoff = $backoff;
        }
        $this->attach('backoff');

        return $this;
    }

    /**
     * @return \Guzzle\Http\Plugin\ExponentialBackoffPlugin
     */
    public function getBackoff()
    {
        if (!$this->backoff){
            $this->backoff = new ExponentialBackoffPlugin();
        }
        return $this->backoff;
    }

    /**
     * @param \Guzzle\Http\Plugin\CachePlugin $caching
     * @return \ZuZu\Grabber\PluginManager
     */
    public function attachCaching($caching=null)
    {
        if ($caching){
            $this->detach('caching');
            $this->caching = $caching;
        }
        $this->attach('caching');

        return $this;
    }

    /**
     * @return \Guzzle\Http\Plugin\CachePlugin
     */
    public function getCaching()
    {
        if (!$this->caching){
            $backend = new FilesystemCache($this->getCacheDir());
            $adapter_cache = new DoctrineCacheAdapter($backend);
            $this->caching = new CachePlugin($adapter_cache,$this->getLifetimeCache());
        }
        return $this->caching;
    }

    /**
     * @param \Guzzle\Http\Plugin\CookiePlugin $cookie
     * @return \ZuZu\Grabber\PluginManager
     */
    public function attachCookie($cookie=null)
    {
        if ($cookie){
            $this->detach('cookie');
            $this->cookie = $cookie;
        }
        $this->attach('cookie');

        return $this;
    }

    /**
     * @return \Guzzle\Http\Plugin\CookiePlugin
     */
    public function getCookie()
    {
        if (!$this->cookie){
            $adapter_cookie = new ArrayCookieJar();
            $this->cookie = new CookiePlugin($adapter_cookie);
        }

        return $this->cookie;
    }

    /**
     * @param \Guzzle\Http\Plugin\LogPlugin $logging
     * @return \ZuZu\Grabber\PluginManager
     */
    public function attachLogging($logging=null)
    {
        if ($logging){
            $this->detach('logging');
            $this->logging = $logging;
        }
        $this->attach('logging');

        return $this;
    }

    /**
     * @return \Guzzle\Http\Plugin\LogPlugin
     */
    public function getLogging()
    {
        if (!$this->logging){
            $logger = new Logger('guzzle_logger');
            $stream = new StreamHandler($this->getLogFile());
            $logger->pushHandler($stream);
            $adapter_log = new MonologLogAdapter($logger);
            $this->logging = new LogPlugin($adapter_log, $this->getLogSetting());
        }
        return $this->logging;
    }

    public function setCacheDir($cacheDir)
    {
        $this->cacheDir = $cacheDir;

        return $this;
    }

    public function getCacheDir()
    {
        return $this->cacheDir;
    }

    public function setLifetimeCache($lifetimeCache)
    {
        $this->lifetimeCache = $lifetimeCache;

        return $this;
    }

    public function getLifetimeCache()
    {
        return $this->lifetimeCache ? $this->lifetimeCache : 3600;
    }

    public function setLogFile($logFile)
    {
        $this->logFile = $logFile;

        return $this;
    }

    public function getLogFile()
    {
        return $this->logFile;
    }

    public function setLogSetting($logSetting)
    {
        $this->logSetting = $logSetting;

        return $this;
    }

    public function getLogSetting()
    {
        return $this->logSetting ? $this->logSetting : LogPlugin::LOG_CONTEXT;
    }

    /**
     * @param string $cacheRevalidate
     *          - skip  the response stored in the cache
     *          - never the response is always served from the origin server
     *          - null
     * @return \ZuZu\Grabber\PluginManager
     */
    public function setCacheRevalidate($cacheRevalidate)
    {
        $this->cacheRevalidate = $cacheRevalidate;

        if ($this->cacheRevalidate){
            $this->client->setConfig(array('params.cache.revalidate' => $this->cacheRevalidate));
        }else{
            $this->client->getConfig()->remove('params.cache.revalidate');
        }

        return $this;
    }

    public function getCacheRevalidate()
    {
        return $this->cacheRevalidate;
    }

    public function setAuthPassword($authPassword)
    {
        $this->authPassword = $authPassword;

        return $this;
    }

    public function getAuthPassword()
    {
        if (!$this->authUsername){
           throw new \Exception("Password for authentication is not defined");
        }
        return $this->authPassword;
    }

    public function setAuthScheme($authScheme)
    {
        $this->authScheme = $authScheme;

        return $this;
    }

    public function getAuthScheme()
    {
        if (!$this->authScheme){
            $this->authScheme = CURLAUTH_BASIC;
        }

        return $this->authScheme;
    }

    public function setAuthUsername($authUsername)
    {
        $this->authUsername = $authUsername;

        return $this;
    }

    public function getAuthUsername()
    {
        if (!$this->authUsername){
           throw new \Exception("Username for authentication is not defined");
        }
        return $this->authUsername;
    }



}
