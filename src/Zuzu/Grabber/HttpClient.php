<?php

namespace ZuZu\Grabber;

use Guzzle\Http\Client as GuzzleClient;
use ZuZu\Grabber\PluginManager;
use Guzzle\Common\Event;

/**
 * Client with PluginManager and with User-Agent and Proxy settings
 */
class HttpClient extends GuzzleClient
{
    const PROXY_HTTP = 'http';
    const PROXY_SOCKS5 = 'socks5';
    /**
     * @var PluginManager
     */
    protected $pluginManager;

    /**
     * @var array list of real User-Agents
     */
    protected $userAgentList;

    protected $userAgentListConfigFile;
    /**
     * @var bool When true implemented Guzzle User-agent
     */
    protected $defaultUserAgent = false;

    protected $useProxy = false;
    protected $proxyServer;
    protected $proxyAuth;
    protected $proxyType = self::PROXY_HTTP;
    protected $proxyServerList;
    protected $proxyServersFile;
    protected $proxyAutoChange = false;

    /**
     * @param array $config Configuration GuzzleClient end
     * Plugins (through the array key 'plugins'=array(...)) end
     * HttpClient (through the array key 'client'=array(...))
     * @param string $strategy The scheme of the PluginManager creation
     * @return HttpClient
     */
    public static function factory($config = array(),$strategy = 'grabber')
    {
        $configPlugins =array();
        if (array_key_exists('plugins',$config)){
            $configPlugins = $config['plugins'];
            unset($config['plugins']);
        }
        $configHttpClient = array();
        if (array_key_exists('client',$config)){
            $configHttpClient = $config['client'];
            unset($config['client']);
        }
        $client = new static($config);
        $client->setup($configHttpClient);
        $client->createPluginManager($configPlugins,$strategy);
        return $client;
    }

    public function __construct($config = null)
    {
        parent::__construct(null,$config);
        $this->init();
    }
    
    private function init()
    {
        if (!$this->getDefaultUserAgent()){
            $this->setDefaultHeaders(array('User-Agent' => $this->getRandomUserAgent()));
        }
        if ($this->getUseProxy()){
            $this->addProxy();
        }
    }

    /**
     * @param array $config Configuration data for PluginManager
     * @param string $strategy
     * @return PluginManager
     */
    public function createPluginManager($config = array(), $strategy = null)
    {
        if (!$this->pluginManager){
            $this->pluginManager = new PluginManager($this,$config,$strategy);
        }
        return $this->pluginManager;
    }

    /**
     * @param array $config Configuration data for Client
     */
    public  function  setup($config)
    {
        if($config && is_array($config)){
            foreach($config as $key => $value){
                $setter ='set'.$key;
                if (method_exists($this,$setter)){
                    call_user_func(array($this,$setter),$value);
                }
            }

        }
    }

    protected  function getRandomUserAgent()
    {
        if ($agentList = $this->getUserAgentList()){
            return array_rand($this->userAgentList);
        }
        return false;
    }

    public function onRequestCreateForProxy(Event $event)
    {
        $serverAuth = $this->getRandProxyServerAndAuth();;
        $curlOptions= $event['request']->getCurlOptions()->set(CURLOPT_PROXY,$serverAuth['server']);
        if (!isset($serverAuth['auth'])){
            $curlOptions->set(CURLOPT_PROXYUSERPWD,$serverAuth['auth']);
        }
    }

    protected function addProxy()
    {
        $config =array();
        $config['curl.CURLOPT_PROXYTYPE'] = $this->getProxyType();
        if ($this->getProxyAutoChange()){
            $this->addAutoProxy();
        }else{
            $config['curl.CURLOPT_PROXY'] = $this->getProxyServer();
            if ($auth = $this->getProxyAuth()){
                $config['curl.CURLOPT_PROXYUSERPWD'] = $auth;
            }
        }
        $this->setConfig($config);
    }

    protected function removeProxy()
    {
        if ($this->getConfig('curl.CURLOPT_PROXY')){
            $this->getConfig()->remove('curl.CURLOPT_PROXY');
            $this->removeAutoProxy();
            if ($this->getConfig('curl.CURLOPT_PROXYUSERPWD')){
                $this->getConfig()->remove('curl.CURLOPT_PROXYUSERPWD');
            }
        }
    }

    protected function addAutoProxy()
    {
        $dispatcher = $this->getEventDispatcher();
        $dispatcher->addListener('client.create_request',array($this,'onRequestCreateForProxy'));
    }

    protected function removeAutoProxy()
    {
        $dispatcher = $this->getEventDispatcher();
        $dispatcher->removeListener('client.create_request',array($this,'onRequestCreateForProxy'));
    }
    //user-agent config
    public function setUserAgentList($userAgentList)
    {
        $this->userAgentList = $userAgentList;

        return $this;
    }

    public function getUserAgentList()
    {
        if (!$this->userAgentList){
            $fileConfig = $this->getUserAgentListConfigFile();
            if ($fileConfig && file_exists($fileConfig)){
                return $this->userAgentList = file($fileConfig, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            }
            //if file not exists
            $this->setDefaultUserAgent(true);
            return false;
        }
        return $this->userAgentList;
    }

    public function setUserAgentListConfigFile($userAgentListConfigFile)
    {
        $this->userAgentListConfigFile = $userAgentListConfigFile;

        return $this;
    }

    public function getUserAgentListConfigFile()
    {
        return $this->userAgentListConfigFile;
    }

    public function setDefaultUserAgent($defaultUserAgent)
    {
        $this->defaultUserAgent = $defaultUserAgent;

        return $this;
    }

    public function getDefaultUserAgent()
    {
        return $this->defaultUserAgent;
    }

    //proxy config
    /**
     * @param string $proxyAuth String in format: username:password
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setProxyAuth($proxyAuth)
    {
        $this->proxyAuth = $proxyAuth;

        return $this;
    }

    /**
     * @return string String in format: username:password
     */
    public function getProxyAuth()
    {
        if (!$this->proxyAuth){
            $serverAuth = $this->getRandProxyServerAndAuth();
            if (isset($serverAuth['auth'])){
                $this->proxyAuth = $serverAuth['auth'];
            }

        }
        return $this->proxyAuth;
    }

    /**
     * @return array Array with keys server and auth
     */
    protected  function getRandProxyServerAndAuth()
    {
        $proxyServerList = $this->getProxyServerList();
        $key = array_rand($proxyServerList);
        return $this->splitServerWithAuth($proxyServerList[$key]);


    }

    /**
     * Whether to change the proxy server for each request
     *
     * @param bool $proxyAutoChange
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setProxyAutoChange($proxyAutoChange)
    {
        $this->proxyAutoChange = $proxyAutoChange;
        if ($this->getUseProxy() === true){
            if ($this->proxyAutoChange === true){
                $this->addAutoProxy();
            }else{
                $this->removeAutoProxy();
            }
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function getProxyAutoChange()
    {
        return $this->proxyAutoChange;
    }

    /**
     * @param string $proxyServer  String in format: server:port
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setProxyServer($proxyServer)
    {
        $this->proxyServer = $proxyServer;

        return $this;
    }

    /**
     * @return string String in the format: server:port
     */
    public function getProxyServer()
    {
        if (!$this->proxyServer){
            $server = $this->getRandProxyServerAndAuth();
            $this->proxyServer = $server['server'];
        }
        return $this->proxyServer;
    }

    /**
     * @param array $proxyServerList An array of strings in format
     * server:port or server:port:username:password
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setProxyServerList($proxyServerList)
    {
        $this->proxyServerList = $proxyServerList;

        return $this;
    }

    /**
     * @return array
     */
    public function getProxyServerList()
    {
        if (!$this->proxyServerList){
            $config = @file($this->getProxyServersFile(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($config && is_array($config)){
                $this->proxyServerList = $config;
            }else{
               throw new  \InvalidArgumentException(sprintf("File '%s' not found",$this->getProxyServersFile()));
            }
        }
        return $this->proxyServerList;
    }

    /**
     * Line in the file must be in format
     * server:port or server:port:username:password
     *
     * @param $proxyServersFile
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setProxyServersFile($proxyServersFile)
    {
        $this->proxyServersFile = $proxyServersFile;

        return $this;
    }

    public function getProxyServersFile()
    {
        return $this->proxyServersFile;
    }

    /**
     * @param string $proxyType or 'http'(default) or 'socks5'
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setProxyType($proxyType)
    {
        $this->proxyType = $proxyType;

        return $this;
    }

    public function getProxyType()
    {
        switch ($this->proxyType){
            case self::PROXY_HTTP:
                return CURLPROXY_HTTP;
            case self::PROXY_SOCKS5:
                return CURLPROXY_SOCKS5;
            default:
                return CURLPROXY_HTTP;
        }
    }

    /**
     * Whether to use a proxy server
     *
     * @param bool $useProxy
     * @return \ZuZu\Grabber\HttpClient
     */
    public function setUseProxy($useProxy)
    {
        $this->useProxy = $useProxy;
        if ($this->useProxy === true){
            $this->addProxy();
        }else{
            $this->removeProxy();
        }
        return $this;
    }

    /**
     * @return bool
     */
    public function getUseProxy()
    {
        return $this->useProxy;
    }

    /**
     * @param string $serverWithAuth strings in format server:port or server:port:username:password
     * @return array An Array with key 'server' and 'auth'(optional)
     */
    protected function splitServerWithAuth($serverWithAuth)
    {
        $all=explode(':',$serverWithAuth);
        $result = array('server' => $all[0].':'.$all[1]);
        if (isset($all[2])){
            $result['auth'] = $all[2].':'.$all[3];
        }

        return $result;
    }

   
}
