<?php
/*
 * InlineStyle MIT License
 * Copyright (c) 2012 Christiaan Baartse
 */
namespace InlineStyle;

use Symfony\Component\CssSelector\CssSelector;
use Symfony\Component\CssSelector\Exception\ParseException;

class InlineStyle
{
    /** @var \DOMDocument */
    private $htmlDocument;

    public function __construct()
    {
        $this->htmlDocument = new \DOMDocument();
        $this->htmlDocument->formatOutput = true;
    }

    /**
     * @param string $filename
     * @return void
     */
    public function loadHTMLFile($filename)
    {
        $string = self::fetchHTMLFile($filename);
        $this->loadHTMLString($string);
    }

    /**
     * @param string $string
     * @return void
     */
    public function loadHTMLString($string)
    {
        $string = self::stripIllegalXmlUtf8Bytes($string);
        $this->htmlDocument->loadHTML($string);
    }

    private static function stripIllegalXmlUtf8Bytes($string)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $string);
    }

    private static function fetchHTMLFile($filename)
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            throw new \InvalidArgumentException('File could not be found');
        }
        return $contents;
    }

    /**
     * @param string $stylesheet
     * @return InlineStyle
     */
    public function applyStylesheet($stylesheet)
    {
        $stylesheet = (array) $stylesheet;
        foreach($stylesheet as $ss) {
            $parsed = $this->parseStylesheet($ss);
            $parsed = $this->sortSelectorsOnSpecificity($parsed);
            foreach($parsed as $arr) {
                list($selector, $style) = $arr;
                $this->applyRule($selector, $style);
            }
        }

        return $this;
    }

    /**
     * @param string $selector
     * @return array|\DOMNodeList
     */
    private function getNodesForCssSelector($selector)
    {
        try {
            $query = CssSelector::toXPath($selector);
            $xpath = new \DOMXPath($this->htmlDocument);
            return $xpath->query($query);
        } catch(ParseException $e) {
            return array();
        }
    }

    /**
     * @param string $selector
     * @param string $style
     * @return InlineStyle
     */
    public function applyRule($selector, $style)
    {
        if($selector) {
            $nodes = $this->getNodesForCssSelector($selector);
            $style = $this->styleToArray($style);

            foreach($nodes as $node) {
                $current = $node->hasAttribute("style") ?
                    $this->styleToArray($node->getAttribute("style")) :
                    array();

                $current = $this->mergeStyles($current, $style);
                $st = array();

                foreach($current as $prop => $val) {
                    $st[] = "{$prop}:{$val}";
                }

                $node->setAttribute("style", implode(";", $st));
            }
        }

        return $this;
    }

    /**
     * Returns the DOMDocument as html
     *
     * @return string the HTML
     */
    public function getHTML()
    {
        return $this->htmlDocument->saveHTML();
    }

    /**
     * Recursively extracts the stylesheet nodes from the DOMNode
     *
     * @param \DOMNode $node leave empty to extract from the whole document
     * @param string $base The base URI for relative stylesheets
     * @return array the extracted stylesheets
     */
    public function extractStylesheets($node = null, $base = '')
    {
        if(null === $node) {
            $node = $this->htmlDocument;
        }

        $stylesheets = array();

        if(strtolower($node->nodeName) === "style") {
            $stylesheets[] = $node->nodeValue;
            $node->parentNode->removeChild($node);
        }
        else if(strtolower($node->nodeName) === "link") {
            if($node->hasAttribute("href")) {
                $href = $node->getAttribute("href");

                if($base && false === strpos($href, "://")) {
                    $href = "{$base}/{$href}";
                }

                $ext = @file_get_contents($href);

                if($ext) {
                    $stylesheets[] = $ext;
                    $node->parentNode->removeChild($node);
                }
            }
        }

        if($node->hasChildNodes()) {
            foreach($node->childNodes as $child) {
                $stylesheets = array_merge($stylesheets,
                    $this->extractStylesheets($child, $base));
            }
        }

        return $stylesheets;
    }

    /**
     * Extracts the stylesheet nodes nodes specified by the xpath
     *
     * @param string $xpathQuery xpath query to the desired stylesheet
     * @return array the extracted stylesheets
     */
    public function extractStylesheetsWithXpath($xpathQuery)
    {
        $stylesheets = array();

        $nodes = $this->htmlDocumentXpathQuerier->query($xpathQuery);
        foreach ($nodes as $node)
        {
            $stylesheets[] = $node->nodeValue;
            $node->parentNode->removeChild($node);
        }

        return $stylesheets;
    }

    /**
     * Parses a stylesheet to selectors and properties
     * @param string $stylesheet
     * @return array
     */
    public function parseStylesheet($stylesheet)
    {
        $parsed = array();
        $stylesheet = $this->stripStylesheetOfComments($stylesheet);
        $stylesheet = trim(trim($stylesheet), "}");
        foreach(explode("}", $stylesheet) as $rule) {
            list($selector, $style) = explode("{", $rule, 2);
            foreach (explode(',', $selector) as $sel) {
                $parsed[] = array(trim($sel), trim(trim($style), ";"));
            }
        }

        return $parsed;
    }

    public function sortSelectorsOnSpecificity($parsed)
    {
        usort($parsed, array($this, 'sortOnSpecificity'));
        return $parsed;
    }

    private function sortOnSpecificity($a, $b)
    {
        $a = $this->getScoreForSelector($a[0]);
        $b = $this->getScoreForSelector($b[0]);

        foreach (range(0, 2) as $i) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] < $b[$i] ? -1 : 1;
            }
        }
        return 0;
    }

    public function getScoreForSelector($selector)
    {
        return array(
            preg_match_all('/#\w/i', $selector, $result), // ID's
            preg_match_all('/\.\w/i', $selector, $result), // Classes
            preg_match_all('/^\w|\ \w|\(\w|\:[^not]/i', $selector, $result) // Tags
        );
    }

    /**
     * Parses style properties to a array which can be merged by mergeStyles()
     * @param string $style
     * @return array
     */
    private function styleToArray($style)
    {
        $styles = array();
        $style = trim(trim($style), ";");
        if($style) {
            foreach(explode(";", $style) as $props) {
                $props = trim(trim($props), ";");
                preg_match('#^([-a-z0-9]+):(.*)$#i', $props, $matches);
                list($match, $prop, $val) = $matches;
                $styles[$prop] = $val;
            }
        }

        return $styles;
    }

    /**
     * Merges two sets of style properties taking !important into account
     * @param array $styleA
     * @param array $styleB
     * @return array
     */
    private function mergeStyles(array $styleA, array $styleB)
    {
        foreach($styleB as $prop => $val) {
            if(!isset($styleA[$prop])
                || substr(str_replace(" ", "", strtolower($styleA[$prop])), -10) !== "!important")
            {
                $styleA[$prop] = $val;
            }
        }

        return $styleA;
    }

    private function stripStylesheetOfComments($stylesheet)
    {
        return preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!','', $stylesheet);
    }
}
