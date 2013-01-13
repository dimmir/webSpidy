<?php
namespace ZuZu\Tests\Grabber;

use ZuZu\Grabber\HtmlHelper;

class HtmlHelperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var HtmlHelper
     */
    protected $helper;

    public function setUp()
    {
        $content = <<<HTM
       <head><meta http-equiv="content-type" content="text/html; charset= utf-8" /></head>
HTM;
        $this->helper = new HtmlHelper($content);
    }
      /**
     * @covers ZuZu\Grabber\HtmlHelper::getCharsetFromContentType
     */
    public function testGetCharsetFromContentType()
    {
        $this->assertEquals('utf-8',HtmlHelper::getCharsetFromContentType('text/html; charset=utf-8'));
        $this->assertEquals('utf-8',HtmlHelper::getCharsetFromContentType('text/html; charset=  utf-8'));
    }

    /**
     * @covers ZuZu\Grabber\HtmlHelper::__construct
     * @covers ZuZu\Grabber\HtmlHelper::getContentType
     * @covers ZuZu\Grabber\HtmlHelper::getCharset
     * @covers ZuZu\Grabber\HtmlHelper::parseContentType
     */
    public function testCreateWithContentType()
    {
        $helper = new HtmlHelper(' ','text/html; charset=utf-8');
        $this->assertEquals('utf-8',$helper->getCharset());
        $this->assertEquals('text/html; charset=utf-8',$helper->getContentType());
    }

    /**
     * @covers ZuZu\Grabber\HtmlHelper::getContentType
     * @covers ZuZu\Grabber\HtmlHelper::getCharset
     * @covers ZuZu\Grabber\HtmlHelper::contentTypeFromHtml
     */
    public function testGetContentTypeAndCharset()
    {
        $this->assertEquals('utf-8',$this->helper->getCharset());
        $this->assertEquals('text/html; charset=utf-8',$this->helper->getContentType());
    }

    /**
     * @covers ZuZu\Grabber\HtmlHelper::clean
     */
    public function testClean()
    {
        $this->assertSame($this->helper,$this->helper->clean());
        $this->assertEquals('utf-8',$this->helper->getCharset());
    }
}

