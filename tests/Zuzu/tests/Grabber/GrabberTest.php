<?php
namespace ZuZu\Test\Grabber;

use ZuZu\Grabber\Grabber;
use Guzzle\Http\Client as GuzzleClient;
use Guzzle\Http\Plugin\MockPlugin;
use Guzzle\Http\Plugin\HistoryPlugin;
use Guzzle\Http\Message\Response;
use Guzzle\Http\Message\PostFile;
use Guzzle\Common\Batch\BatchBuilder;


class GrabberTest extends \PHPUnit_Framework_TestCase
{
    public function getGrabberWithResponse($response)
    {
        $grabber = new Grabber();
        $mock = new MockPlugin();

        if (is_array($response)){
            foreach($response as $r){
                $mock->addResponse($r);
            }
        }else{
            $mock->addResponse($response);
        }
        $grabber->getClient()->getEventDispatcher()->addSubscriber($mock);
        return $grabber;
    }

    /**
     * @covers ZuZu\Grabber\Grabber::request
     * @covers ZuZu\Grabber\Grabber::buildResult
     * @covers ZuZu\Grabber\Grabber::getCleanHtml
     * @covers ZuZu\Grabber\Grabber::setCleanHtml
     */
    public function testSingleRequestWithHtmlResponse()
    {
        $html = <<<HTML
<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>
<body><p>Body</p></body></html>
HTML;
        $response = new Response(200, array('Content-Type' => 'text/html'),$html);
        $grabber = $this->getGrabberWithResponse($response);
        $grabber->setCleanHtml(true);
        $crawler  = $grabber->request('http://www.example.com');
        $this->assertEquals('Body',$crawler->filter('p')->text());
    }
    /**
     * @covers ZuZu\Grabber\Grabber::request
     * @covers ZuZu\Grabber\Grabber::buildResult
     */
    public function testSingleRequestWithXmlResponse()
    {
        $response = new Response(200,
            array('Content-Type' => 'text/xml; charset=utf-8'),
            "<test><label>Label1</label><label>Label2</label></test>");
        $grabber = $this->getGrabberWithResponse($response);
        $crawler  = $grabber->request('http://www.example.com');
        $this->assertEquals('Label1',$crawler->filter('label')->eq(0)->text());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::request
     * @covers ZuZu\Grabber\Grabber::buildResult
     */
    public function testSingleRequestWithJsonResponse()
    {
        $response = new Response(200,
            array('Content-Type' => 'text/json'),
            '{"a":1,"b":2,"c":3}');
        $grabber = $this->getGrabberWithResponse($response);
        $this->assertEquals(array("a"=>1, "b"=>2, "c"=>3),$grabber->request('http://www.example.com'));
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testSingleRequestWithBadJson()
    {
        $response = new Response(200, array('Content-Type' => 'text/json'), "{'a':1,'b':2,'c':3}");
        $grabber = $this->getGrabberWithResponse($response);
        $grabber->request('http://www.example.com');
    }

    /**
     * @covers ZuZu\Grabber\Grabber::request
     * @covers ZuZu\Grabber\Grabber::buildResult
     * @covers ZuZu\Grabber\Grabber::addPostFiles
     */
    public function testUsePostFile()
    {
        $historyPlugin = new HistoryPlugin();
        $response = new Response(200);
        $grabber = $this->getGrabberWithResponse($response);
        $grabber->getClient()->getEventDispatcher()->addSubscriber($historyPlugin);

        $files = array(
            'fieldName' => array(
                'name' => 'fileName.txt',
                'tmp_name' => __FILE__
            )
        );
        $grabber->request('http://www.example.com','POST',null, null, $files);
        $request = $historyPlugin->getLastRequest();
        $this->assertEquals(array(
                'fieldName' => array(
                    new PostFile('fieldName', __FILE__)
                )
            ), $request->getPostFiles());

    }

    /**
     * @covers ZuZu\Grabber\Grabber::buildResult
     * @covers ZuZu\Grabber\Grabber::submit
     */
    public function testSubmit()
    {
        $historyPlugin = new HistoryPlugin();
        $response1 = new Response(200,
            array('Content-Type' => 'text/html; charset=utf-8'),
            '<html><form action="/test2"><input type="submit" /></form></html>');
        $response2 = new Response(200);
        $grabber = $this->getGrabberWithResponse(array($response1,$response2));
        $grabber->getClient()->getEventDispatcher()->addSubscriber($historyPlugin);

        $crawler  = $grabber->request('http://www.example.com/test1');
        $grabber->submit($crawler->filter('input')->form());
        $request = $historyPlugin->getLastRequest();
        $this->assertEquals('http://www.example.com/test2', $request->getUrl());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::submit
     * @covers ZuZu\Grabber\Grabber::addPostFiles
     */
    public function testSubmitForFileForm()
    {
        $historyPlugin = new HistoryPlugin();
        $response1 = new Response(200,
            array('Content-Type' => 'text/html; charset=utf-8'),
            '<html>
            <form action="/test2">
            <input type="file" name="foo[bar]"/>
            <input type="submit" name="test" /></form>
            </html>');
        $response2 = new Response(200);
        $grabber = $this->getGrabberWithResponse(array($response1,$response2));
        $grabber->getClient()->getEventDispatcher()->addSubscriber($historyPlugin);

        $crawler  = $grabber->request('http://www.example.com/test1');
        $form = $crawler->selectButton('test')->form(array("foo[bar]"=>__FILE__),'POST');
        $grabber->submit($form);
        $request = $historyPlugin->getLastRequest();
        $this->assertEquals('foo[bar]',$request->getPostFiles()['foo[bar]'][0]->getFieldName());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::click
     */
    public function testClick()
    {
        $historyPlugin = new HistoryPlugin();
        $response1 = new Response(200,
            array('Content-Type' => 'text/html; charset=utf-8'),
            '<html><a href="/foo">foo</a></html>');
        $response2 = new Response(200);
        $grabber = $this->getGrabberWithResponse(array($response1,$response2));
        $grabber->getClient()->getEventDispatcher()->addSubscriber($historyPlugin);

        $crawler  = $grabber->request('http://www.example.com/test1');
        $grabber->click($crawler->filter('a')->link());
        $request = $historyPlugin->getLastRequest();
        $this->assertEquals('http://www.example.com/foo', $request->getUrl());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::getBatch
     * @covers ZuZu\Grabber\Grabber::getClient
     */
    public function testGetClientAndBatch()
    {
        $grabber = new Grabber();
        $this->assertInstanceOf('ZuZu\\Grabber\\HttpClient',$grabber->getClient());
        $this->assertInstanceOf('Guzzle\\Common\\Batch\\ExceptionBufferingBatch',$grabber->getBatch());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::request
     * @covers ZuZu\Grabber\Grabber::send
     */
    public function testMultiRequest()
    {
        $response1 = new Response(200, array('Content-Type' => 'text/json; charset=utf-8'),
            '{"a":1,"b":2,"c":3}');
        $response2 = new Response(200, array('Content-Type' => 'text/html; charset=utf-8'),
            "<html><body><p>Body</body></html>");
        $response3 = new Response(200, array('Content-Type' => 'text/xml; charset=utf-8'),
            "<test><label>Label1</label><label>Label2</label></test>");
        $grabber = $this->getGrabberWithResponse(array($response1,$response2,$response3));
        $grabber->setBatchSize(2);
        $grabber->request('http://www.example1.com')
            ->request('http://www.example2.com')
            ->request('http://www.example3.com');
        $results = $grabber->send();
        $this->assertTrue(is_array($results[0]));
        $this->assertEquals('Body',$results[1]->filter('p')->text());
        $this->assertEquals('Label1',$results[2]->filter('label')->eq(0)->text());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::send
     * @covers ZuZu\Grabber\Grabber::getExceptions
     * @covers ZuZu\Grabber\Grabber::clearExceptions
     */
    public function testBadMultiRequest()
    {
        $response1 = new Response(404);
        $response2 = new Response(200, array('Content-Type' => 'text/html; charset=utf-8'),
            "<html><body><p>Body</body></html>");
        $response3 = new Response(500);
        $grabber = $this->getGrabberWithResponse(array($response1,$response2,$response3));
        $grabber->setBatchSize(2);
        $grabber->request('http://www.example1.com')
            ->request('http://www.example2.com')
            ->request('http://www.example3.com');
        $results = $grabber->send();
        $exceptions = $grabber->getExceptions();
        $this->assertCount(2,$exceptions);
        $this->assertEquals('Body',$results[0]->filter('p')->text());
        $this->assertInstanceOf('Guzzle\\Http\\Exception\\ClientErrorResponseException',
            $exceptions[0]->getIterator()->getArrayCopy()[0]);
        $this->assertInstanceOf('Guzzle\\Http\\Exception\\ServerErrorResponseException',
            $exceptions[1]->getIterator()->getArrayCopy()[0]);
        $grabber->clearExceptions();
        $this->assertCount(0,$grabber->getExceptions());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::setBaseUrl
     * @covers ZuZu\Grabber\Grabber::getBaseUrl
     */
    public  function testSetGetBaseUrl()
    {
        $grabber = new Grabber();
        $grabber->setBaseUrl('http://www.example.com');
        $this->assertEquals('http://www.example.com', $grabber->getBaseUrl());
    }
    /**
     * @covers ZuZu\Grabber\Grabber::setCrawlerClass
     * @covers ZuZu\Grabber\Grabber::getCrawlerClass
     */
    public function testSetGetCrawlerClass()
    {
        $grabber = new Grabber();
        $grabber->setCrawlerClass('ZuZu\\Grabber\\Crawler');
        $this->assertEquals('ZuZu\\Grabber\\Crawler', $grabber->getCrawlerClass());
    }

    /**
     * @covers ZuZu\Grabber\Grabber::setBatch
     * @covers ZuZu\Grabber\Grabber::getBatch
     * @covers ZuZu\Grabber\Grabber::setBatchSize
     */
    public function testSetBatch()
    {
        $grabber = new Grabber();
        $grabber->setBatchSize(10);

        $batch = BatchBuilder::factory()
                        ->transferRequests($grabber->getBatchSize())
                        ->build();
        $grabber->setBatch($batch);
        $this->assertSame($batch,$grabber->getBatch());
        $this->assertEquals(10,$grabber->getBatchSize());
    }

    public function testCustomClient()
    {
        $client = new GuzzleClient();
        $grabber = new Grabber($client);
        $this->assertSame($client,$grabber->getClient());
    }
}
