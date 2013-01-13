<?php
namespace ZuZu\Test\Grabber;

use ZuZu\Grabber\PluginManager;
use Guzzle\Http\Client;
use Guzzle\Http\Plugin\AsyncPlugin;
use Guzzle\Http\Plugin\LogPlugin;

class PluginManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var PluginManager
     */
    protected $manager;
    /**
     * @var Client
     */
    protected $client;

    protected function setUp()
    {
        $this->client = new Client();
        $this->manager = new PluginManager($this->client);
    }

    /**
     * @covers ZuZu\Grabber\PluginManager::getAttachedPlugins
     * @covers ZuZu\Grabber\PluginManager::getCaching
     * @covers ZuZu\Grabber\PluginManager::getLogging
     */
    public function testGetAttachedPlugins()
    {
        $manager = $this->manager;
        $manager->attach('caching');
        $manager->attachLogging();
        $this->assertEquals(array('caching','logging'),$manager->getAttachedPlugins());
        $manager->detach('caching');
        $manager->detach('logging');

    }

    /**
     * @covers ZuZu\Grabber\PluginManager::hasPlugin
     * @covers ZuZu\Grabber\PluginManager::getCookie
     */
    public function testHasPlugin()
    {
        $manager = $this->manager;
        $manager->attachCookie();
        $this->assertEquals(true,$manager->hasPlugin('cookie'));
        $manager->detach('cookie');
    }

    /**
     * @covers ZuZu\Grabber\PluginManager::attachAsync
     * @covers ZuZu\Grabber\PluginManager::getAsync
     * @covers ZuZu\Grabber\PluginManager::attach
     * @covers ZuZu\Grabber\PluginManager::detach
     */
    public function testAttachDetachPlugin()
    {
        $this->assertEquals(0,count($this->client->getEventDispatcher()->getListeners()));
        $this->manager->attachAsync();
        $countListeners1 = count($this->client->getEventDispatcher()->getListeners());
        $this->assertNotEquals(0,$countListeners1);

        $asyncPlugin = new AsyncPlugin();
        $this->manager->attachAsync($asyncPlugin);
        $countListeners2 = count($this->client->getEventDispatcher()->getListeners());
        $this->assertEquals($countListeners1,$countListeners2);
        $this->manager->detach('async');
    }

    /**
     * @covers ZuZu\Grabber\PluginManager::attachAuth
     * @covers ZuZu\Grabber\PluginManager::getAuth
     * @covers ZuZu\Grabber\PluginManager::__set
     * @covers ZuZu\Grabber\PluginManager::__get
     * @expectedException \InvalidArgumentException
     */
    public function testGetSet()
    {
        $manager = $this->manager;
        $manager->authUsername = 'username';
        $manager->authPassword = 'password';
        $manager->auth = true;
        $this->assertNotEquals(0,count($manager->getAttachedPlugins()));
        $this->assertEquals('username',$manager->authUsername);
        $manager->detach('auth');
        $this->assertEquals(0,count($manager->getAttachedPlugins()));

        $manager->log = 'log';
    }

    /**
     * @covers ZuZu\Grabber\PluginManager::__construct
     * @covers ZuZu\Grabber\PluginManager::parseConfig
     * @covers ZuZu\Grabber\PluginManager::__set
     * @covers ZuZu\Grabber\PluginManager::getLogging
     * @covers ZuZu\Grabber\PluginManager::getBackoff
     * @covers ZuZu\Grabber\PluginManager::parseAttach
     * @expectedException \InvalidArgumentException
     */
    public function testConfig()
    {
        $config = array(
            'backoff' => true,
            'logFile' => 'http.log',
            'logSetting' => LogPlugin::LOG_BODY,
            'logging' => true
        );

        $client = new Client();
        $manager = new PluginManager($client,$config);
        $this->assertEquals('http.log', $manager->getLogFile());
        $this->assertNotEquals(0,count($manager->getAttachedPlugins()));

        new PluginManager($client,'config');

    }

    /**
     * @covers ZuZu\Grabber\PluginManager::getCaching
     * @covers ZuZu\Grabber\PluginManager::attachCaching
     * @covers ZuZu\Grabber\PluginManager::getCacheDir
     * @covers ZuZu\Grabber\PluginManager::getCacheRevalidate
     * @covers ZuZu\Grabber\PluginManager::setCacheRevalidate
     * @covers ZuZu\Grabber\PluginManager::setCacheDir
     * @covers ZuZu\Grabber\PluginManager::setLifetimeCache
     */
    public  function testCaching()
    {
        $manager = $this->manager;
        $manager->setCacheDir('/cache')
            ->setCacheRevalidate('skip')
            ->setLifetimeCache(600)
            ->attachCaching();
        $this->assertEquals('/cache', $manager->getCacheDir());
        $this->assertEquals('skip', $manager->getCacheRevalidate());
        $this->assertEquals(2,count($this->client->getEventDispatcher()->getListeners()));
        $manager->detach('cache');
    }

}
