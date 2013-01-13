<?php

namespace ZuZu\Grabber;

use Guzzle\Http\ClientInterface as BaseClientInterface;
use ZuZu\Grabber\HttpClient as BaseClient;
use Guzzle\Http\Message\RequestInterface;
use Guzzle\Http\Message\Response;
use Guzzle\Common\Batch\Batch;
use Guzzle\Common\Batch\BatchBuilder;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\DomCrawler\Link;

/**
 * Simple Http Client.
 */
class Grabber
{
    /**
     * @var BaseClient The client base on the Guzzle http client
     */
    protected $client;

    /**
     * @var string Class name of the domCrawler
     */
    protected $domCrawlerClass = "Symfony\\Component\\DomCrawler\\Crawler";

    protected $cleanHtml = true;
    /**
     * @var \Guzzle\Common\Exception\ExceptionCollection[] array of ExceptionCollection
     */
    protected $exceptions;
    /**
     * @var Batch
     */
    protected $batch;

    /**
     * @var int Size of each batch
     */
    protected $batchSize = 0;

    /**
     * @param \Guzzle\Http\ClientInterface $baseClient
     * @param array $config Configuration data (@link ZuZu\Grabber\HttpClient::factory)
     * @param string $strategy The scheme of the PluginManager creation (default 'grabber')
     */
    public function __construct(BaseClientInterface $baseClient = null, $config = array(), $strategy = 'grabber')
    {
        $this->client = $baseClient ?: BaseClient::factory($config,$strategy) ;

    }

    public function setBaseUrl($url)
    {
        $this->getClient()->setBaseUrl($url);
    }

    public function getBaseUrl()
    {
        return $this->getClient()->getBaseUrl();
    }

    /**
     * @return HttpClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param string $Crawler Class name of the domCrawler
     */
    public function setCrawlerClass($crawler)
    {
        $this->domCrawlerClass = $crawler;
    }

    public function getCrawlerClass()
    {
        return $this->domCrawlerClass;
    }

    /**
     * If batchSize = 0 (default) then create a request and
     * returns the result as an array (for json) or
     * as an object Crawler (for html/xml)
     *
     * If batchSize > 0 then create a request and
     * added to the queue. To get an array results call the send()
     *
     * @param null $uri
     * @param string $method
     * @param null $headers
     * @param null $body
     * @see Guzzle\Http\Client::createRequest
     * @param array $files An array of file field values {@link Symfony\Component\DomCrawler\Field\FileFormField}
     * @return mixed|\Symfony\Component\DomCrawler\Crawler|Grabber
     */
    public  function request($uri=null,$method = 'GET', $headers = null, $body = null, $files = array())
    {

        $request = $this->getClient()->createRequest($method,$uri,$headers,$body);

        if ('POST' == $method && !empty($files)) {
            $this->addPostFiles($request, $files);
        }

        if (!$this->getBatchSize()){
            $response = $request->send();
            return $this->buildResult($response);
        }
        $batch = $this->getBatch();
        $batch->add($request);
        return $this;
    }

    /**
     * This method returns an array of results from requests from the queue.
     * The result is an array (for json) or an object Crawler (for html/xml)
     *
     * All good requests from the queue are executed.
     * Exceptions are buffered and are available through the method getExceptions()
     *
     * @return Crawler|array
     */
    public function send()
    {
        $requests = $this->getBatch()->flush();

        $results = array();
        foreach($requests as $request){
            $response = $request->getResponse();
            $results[] = $this->buildResult($response);
        }

        $exceptions = $this->getBatch()->getExceptions();
        if ($exceptions){
            foreach($exceptions as $exception){
                $this->exceptions[] = $exception->getPrevious();
                $exceptionRequests = $exception->getBatch();

                foreach($exceptionRequests as $requestE){
                    $responseE = $requestE->getResponse();
                    $statusCode = $responseE->getStatusCode();
                    if ($statusCode == 200){
                        $results[] = $this->buildResult($responseE);
                    }
                }
            }
            $this->getBatch()->clearExceptions();
        }


        return $results;
    }


    protected function addPostFiles(RequestInterface $request, array $files)
    {
        foreach ($files as $name => $info) {
            if (isset($info['tmp_name']) && '' !== $info['tmp_name']) {
                $request->addPostFile($name, $info['tmp_name']);
            }
        }
    }

    protected function buildResult(Response $response)
    {
        $contentType = $response->getContentType();
        $content = $response->getBody(true);
        if (empty($content)) return;

        if (stripos($contentType, 'json') !== false) {
            $result = json_decode($content,true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                throw new \RuntimeException('The response body can not be decoded to JSON', json_last_error());
            }
            return $result;
        }else{
            $crawlerClass = $this->getCrawlerClass();
            /** @var $crawler Crawler */
            $crawler = new $crawlerClass(null,$response->getRequest()->getUrl());

            if (stripos($contentType, 'html') !== false) {
                $charset = HtmlHelper::getCharsetFromContentType($contentType);
                if (!$charset){
                    $helper = new HtmlHelper($content);
                    $charset = $helper->getCharset();
                }
                if ($this->getCleanHtml()){
                    if (!isset($helper)){
                        $helper = new HtmlHelper($content,$contentType);
                    }else{
                        $helper->parseContentType($contentType);
                    }
                    $helper->clean();
                    $charset = $helper->getCharset();
                    $content = $helper->getContent();
                }

                $crawler->addHtmlContent($content,$charset);
            }

            if (stripos($contentType, 'xml') !== false) {
                $crawler->addXmlContent($content);
            }
            return $crawler;
        }
    }

    /**
     * @return \Guzzle\Common\Exception\ExceptionCollection[]
     */
    public function getExceptions()
    {
        return $this->exceptions;
    }

    public function clearExceptions()
    {
        $this->exceptions = array();
    }

    public function setCleanHtml($cleanHtml)
    {
        $this->cleanHtml = $cleanHtml;
    }

    public function getCleanHtml()
    {
        return $this->cleanHtml;
    }

    /**
     * Submits a form.
     *
     * @param Form  $form   A Form instance
     * @param array $values An array of form field values
     * @return Crawler
     *
     */
    public function submit(Form $form, array $values = array())
    {
        $form->setValues($values);
        $method = $form->getMethod();
        if ($form->getFiles()){
            $method = 'POST';
        }
        return $this->request( $form->getUri(), $method, null, $form->getPhpValues(), $form->getFiles());
    }

    /**
     * Clicks on a given link.
     *
     * @param Link $link A Link instance
     * @return Crawler
     *
     * @see Symfony\Component\BrowserKit\Client::Click
     */
    public function click(Link $link)
    {   // @codeCoverageIgnoreStart
        if ($link instanceof Form) {
            return $this->submit($link);
        }
        // @codeCoverageIgnoreEnd
        return $this->request($link->getUri(),$link->getMethod());
    }

    /**
     * @param \Guzzle\Common\Batch\BatchInterface $batch
     */
    public function setBatch(\Guzzle\Common\Batch\BatchInterface $batch)
    {
        $this->batch = $batch;
    }

    /**
     * @return Batch
     */
    public function getBatch()
    {
        if (!$this->batch){
            $this->batch = BatchBuilder::factory()
                        ->transferRequests($this->getBatchSize())
                        ->bufferExceptions()
                        ->build();
        }
        return $this->batch;
    }

    /**
     * @param int $batchSize Size of each batch
     */
    public function setBatchSize($batchSize)
    {
        $this->batchSize = $batchSize;
    }

    /**
     * @return int Size of each batch
     */
    public function getBatchSize()
    {
        return $this->batchSize;
    }


}
