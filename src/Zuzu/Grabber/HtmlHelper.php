<?php

namespace ZuZu\Grabber;

/**
 * This helper allows to parse the MIME type and charset from the header or html body,
 * to clean and  to convert  charset of the html body in utf-8
 */
class HtmlHelper
{
    protected  $charset;

    /**
     * @var MIME type
     */
    protected  $type;

    protected  $content;

    public static function  getCharsetFromContentType($type)
    {
        $charset = null;
        $contenttype = strtolower($type);
        if (false !== $pos = strpos($contenttype, 'charset=')) {
            $charset = substr($contenttype, $pos + 8);
            if (false !== $pos = strpos($charset, ';')) {
              $charset = substr($charset, 0, $pos);
            }
            $charset = trim($charset);
        }
        return $charset;
    }


    public function __construct($content,$contentType=null)
    {
        $this->content = $content;
        if ($contentType){
            $this->parseContentType($contentType);
        }
        if (empty($this->charset) || empty($this->type)){
            $this->contentTypeFromHtml($content);
        }
    }

    public  function parseContentType($contentType)
    {
        $contenttype = strtolower($contentType);
        if (false !== $pos = strpos($contenttype, ';')) {
            $this->type = trim(substr($contenttype, 0, $pos));
        }
        if ($charset =  self::getCharsetFromContentType($contenttype)){
            $this->charset =$charset;
        }

    }

    public function getCharset()
    {
        return $this->charset ?: 'utf-8';
    }

    public function getContent()
    {
        return $this->content;
    }

    /**
     * @return string
     */
    public function getContentType()
    {
        return $this->type.'; charset='.$this->charset;
    }

    protected function contentTypeFromHtml($content)
    {
        if (preg_match ("~<meta.*?content\s*=\s*[\"']([^;\s]+).*? charset\s*=([^\"']+)~i",$content,$result))
        {
            $this->charset = trim($result[2]);
            if (empty($this->type)){
                $this->type = trim($result[1]);
            }
        }
    }

    /**
     * Convert html to xml encoded utf-8
     * @param array $config  Configure Tidy
     * @return HtmlHelper
     */
    public function clean($config=array())
    {
        $content = $this->getContent();
        $inputCharset = $this->getCharset();
        if ($inputCharset and strtolower($inputCharset) != 'utf-8'){
            if (function_exists('mb_convert_encoding')) {
                $content = mb_convert_encoding($content, 'UTF-8', $inputCharset);
            }
        }
        $tidy_config = array(
            'input-encoding' => 'utf8',
            'output-encoding' => 'utf8',
            'output-xml' => true,
            'numeric-entities' => true,
            'hide-comments' => true
        );
        $tidy_config = array_merge($tidy_config,$config);
        $tidy = new \Tidy();
        $tidy->parseString($content, $tidy_config,'utf8');
        $tidy->CleanRepair();
        $this->content = $tidy->html()->value;
        return $this;
    }



}
