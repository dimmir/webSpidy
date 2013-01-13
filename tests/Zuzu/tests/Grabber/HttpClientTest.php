<?php

namespace ZuZu\Test\Grabber;

use ZuZu\Grabber\HttpClient;

class HttpClientTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var HttpClient
     */
    protected $client;

    public function setUp()
    {
        $this->client = new HttpClient();
    }

    /**
     * @covers ZuZu\Grabber\HttpClient::getDefaultUserAgent
     * @covers ZuZu\Grabber\HttpClient::setUserAgentListConfigFile
     * @covers ZuZu\Grabber\HttpClient::getUserAgentList
     * @covers ZuZu\Grabber\HttpClient::getUserAgentListConfigFile
     */
    public function testUserAgentList()
    {
        $client = $this->client;
        $client->setDefaultUserAgent(false);
        //if file not found
        $client->setUserAgentListConfigFile('file');
        $client->getUserAgentList();
        $this->assertTrue($client->getDefaultUserAgent());
        //if file exists
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resurse' . DIRECTORY_SEPARATOR . 'useragentlist.config';
        $client->setUserAgentListConfigFile($file);
        $useragentlist = $client->getUserAgentList();
        $this->assertEquals(3,count($useragentlist));
    }

    /**
     * @covers ZuZu\Grabber\HttpClient::setUseProxy
     * @covers ZuZu\Grabber\HttpClient::getProxyServer
     * @covers ZuZu\Grabber\HttpClient::setProxyServersFile
     * @covers ZuZu\Grabber\HttpClient::getProxyAuth
     * @covers ZuZu\Grabber\HttpClient::getProxyServersFile
     * @covers ZuZu\Grabber\HttpClient::splitServerWithAuth
     * @covers ZuZu\Grabber\HttpClient::addProxy
     * @covers ZuZu\Grabber\HttpClient::removeProxy
     * @expectedException \InvalidArgumentException
     */
    public function testProxy()
    {
        $client = $this->client;
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resurse' . DIRECTORY_SEPARATOR . 'proxylist.config';
        $client->setProxyServersFile($file);
        $client->setUseProxy(true);
        $client->setProxyAutoChange(false);

        $this->assertEquals('69.163.96.25:8080',$client->getProxyServer());
        $this->assertEquals('username:password',$client->getProxyAuth());
        $this->assertNotNull($client->getProxyServerList());
        $this->assertEquals('69.163.96.25:8080',$client->getConfig('curl.CURLOPT_PROXY'));

        $client->setProxyServerList(null);
        $client->setUseProxy(false);
        $this->assertNull($client->getConfig('curl.CURLOPT_PROXY'));
        $client->setProxyServersFile('file');
        $client->getProxyServerList();

    }

    /**
     * @covers ZuZu\Grabber\HttpClient::setProxyAutoChange
     * @covers ZuZu\Grabber\HttpClient::setUseProxy
     * @covers ZuZu\Grabber\HttpClient::getRandProxyServerAndAuth
     * @covers ZuZu\Grabber\HttpClient::getProxyAuth
     * @covers ZuZu\Grabber\HttpClient::onRequestCreateForProxy
     * @covers ZuZu\Grabber\HttpClient::addProxy
     * @covers ZuZu\Grabber\HttpClient::removeProxy
     * @covers ZuZu\Grabber\HttpClient::addAutoProxy
     * @covers ZuZu\Grabber\HttpClient::removeAutoProxy
     */
    public function testProxyAuto()
    {
        $client = $this->client;
        $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resurse' . DIRECTORY_SEPARATOR . 'proxylist.config';
        $client->setProxyServersFile($file);
        //proxy = true autoChange = true
        $client->setUseProxy(true);
        $client->setProxyAutoChange(true);
        $listener = $client->getEventDispatcher()->getListeners('client.create_request');
        $this->assertInstanceOf('ZuZu\Grabber\HttpClient',$listener[0][0]);
        $request = $client->get('example.com');
        $this->assertNotNull($request->getCurlOptions()->get(CURLOPT_PROXY));
        //proxy = true autoChange = false
        $client->setProxyAutoChange(false);
        $listener2 = $client->getEventDispatcher()->getListeners('client.create_request');
        $this->assertCount(0,$listener2);
        //proxy = false autoChange = true
        $client->setUseProxy(false);
        $client->setProxyAutoChange(true);
        $listener2 = $client->getEventDispatcher()->getListeners('client.create_request');
        $this->assertCount(0,$listener2);
        // autoChange = true proxy = true
        $client->setUseProxy(true);
        $listener = $client->getEventDispatcher()->getListeners('client.create_request');
        $this->assertInstanceOf('ZuZu\Grabber\HttpClient',$listener[0][0]);
    }

    /**
     * @covers ZuZu\Grabber\HttpClient::setup
     */
    public function testSetup()
    {
        $config = array(
            'proxyServer' => 'server:port',
            'userAgentList' => array('agent1','agent2')
        );
        $this->client->setup($config);
        $this->assertEquals('server:port',$this->client->getProxyServer());
        $this->assertEquals(array('agent1','agent2'),$this->client->getUserAgentList());
    }

    public function testFactory()
    {
        $config = array(
            'curl.CURLOPT_PROXY' => 'server:port',
            'plugins' => array(
                'logFile' => 'file.log',
                'lifetimeCache' => 3000
            ),
            'client' => array(
                'proxyServersFile' => 'fileproxy'
            )
        );
        $client = HttpClient::factory($config);
        $this->assertEquals('server:port',$client->getConfig('curl.CURLOPT_PROXY'));
        $this->assertEquals('file.log',$client->createPluginManager()->getLogFile());
        $this->assertEquals('fileproxy',$client->getProxyServersFile());
    }
}
