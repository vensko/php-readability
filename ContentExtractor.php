<?php

namespace Readability;

use DOMXPath, DOMDocument, DOMNodeList, DOMElement, DOMNode, DOMText;
use Masterminds\HTML5;

/**
 * Arc90's Readability ported to PHP for FiveFilters.org
 * Based on readability.js version 1.7.1 (without multi-page support)
 * Updated to allow HTML5 parsing with Masterminds\HTML5
 * Updated with lightClean mode to preserve more images and youtube/vimeo/viddler embeds
 * ------------------------------------------------------
 * Original URL: http://lab.arc90.com/experiments/readability/js/readability.js
 * Arc90's project URL: http://lab.arc90.com/experiments/readability/
 * JS Source: http://code.google.com/p/arc90labs-readability
 * Ported by: Keyvan Minoukadeh, http://www.keyvan.net
 * More information: http://fivefilters.org/content-only/
 * License: Apache License, Version 2.0
 * Requires: PHP5
 * Date: 2012-09-19
 *
 * Differences between the PHP port and the original
 * ------------------------------------------------------
 * Arc90's Readability is designed to run in the browser. It works on the DOM
 * tree (the parsed HTML) after the page's CSS styles have been applied and
 * Javascript code executed. This PHP port does not run inside a browser.
 * We use PHP's ability to parse HTML to build our DOM tree, but we cannot
 * rely on CSS or Javascript support. As such, the results will not always
 * match Arc90's Readability. (For example, if a web page contains CSS style
 * rules or Javascript code which hide certain HTML elements from display,
 * Arc90's Readability will dismiss those from consideration but our PHP port,
 * unable to understand CSS or Javascript, will not know any better.)
 *
 * Another significant difference is that the aim of Arc90's Readability is
 * to re-present the main content block of a given web page so users can
 * read it more easily in their browsers. Correct identification, clean up,
 * and separation of the content block is only a part of this process.
 * This PHP port is only concerned with this part, it does not include code
 * that relates to presentation in the browser - Arc90 already do
 * that extremely well, and for PDF output there's FiveFilters.org's
 * PDF Newspaper: http://fivefilters.org/pdf-newspaper/.
 *
 * Finally, this class contains methods that might be useful for developers
 * working on HTML document fragments. So without deviating too much from
 * the original code (which I don't want to do because it makes debugging
 * and updating more difficult), I've tried to make it a little more
 * developer friendly. You should be able to use the methods here on
 * existing DOMElement objects without passing an entire HTML document to
 * be parsed.
 */

// Alternative usage (for testing only!)
// uncomment the lines below and call Readability.php in your browser
// passing it the URL of the page you'd like content from, e.g.:
// Readability.php?url=http://medialens.org/alerts/09/090615_the_guardian_climate.php

/*
if (!isset($_GET['url']) || $_GET['url'] == '') {
	die('Please pass a URL to the script. E.g. Readability.php?url=bla.com/story.html');
}
$url = $_GET['url'];
if (!preg_match('!^https?://!i', $url)) $url = 'http://'.$url;
$html = file_get_contents($url);
$r = new Readability($html, $url);
$r->init();
echo $r->articleContent->innerHTML;
*/

class ContentExtractor
{
	const HTML_NS = 'http://www.w3.org/1999/xhtml';

	use DOMHelpers;

	public $url = null; // optional - URL where HTML was retrieved
	public $lightClean = true; // preserves more content (experimental) added 2012-09-19
	protected $body = null; //
	protected $bodyCache = null; // Cache the body HTML in case we need to re-use it later
	protected $flags = 7; // 1 | 2 | 4;   // Start with all flags set.
	protected $success = false; // indicates whether we were able to extract or not

	protected $articleTitle;
	protected $articleContent;
	protected $articleOpenGraph = [];
	protected $articleTwitterMeta = [];
	protected $articleMeta = [];

	public $removeTags = [
		'script', 'noscript', 'applet', 'style', 'link', 'frameset',
		'form', 'button', 'input', 'select', 'textarea',
		'nav', 'menu', 'dialog', 'datalist', 'canvas', 'footer',
		'g:plusone', 'output',
	];

	public $removeRoles = [
		'banner', 'navigation', 'search',
	];

	/**
	 * @var array
	 */
	public $wrapperElements = [
		'body', 'article', 'main', 'section', 'table', 'tr', 'tbody', 'td', 'div', 'fieldset', 'center',
	];

	public $unlikelyContentBlockElements = [
		'aside', 'footer', 'header', 'nav', 'address', 'form',
	];

	public $paragraphElements = [
		'blockquote', 'p', 'pre',
		'li', 'dt', 'dd', // we're going to analyze their descendants of multilevel elements
	];

	public $nonSemanticBlockTextElements = [
		'div', 'td',
	];

	// Acceptable siblings for $this->contentElements
	public $acceptableTextSiblings = [
		// generated with merged asideContentElements, headerElements, listElements, imageElements, embedElements, inlineTextElements
	];

	// acceptable block siblings for paragraphs
	public $asideContentElements = [
		'aside', 'details', 'table', 'blockquote', 'pre', 'figure', 'footer',
	];

	public $headerElements = [
		'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
	];

	public $listElements = [
		'ol', 'ul', 'dl',
	];

	public $imageElements = [
		'img', 'svg', 'figure',
	];

	public $embedElements = [
		'video', 'audio', 'iframe', 'embed', 'object',
	];

	public $embedWhitelist = '^(?:https?\:)?//((player\.|www\.)?(youtube|vimeo|viddler)\.com)/';

	public $allowSelfHostedEmbed = true;

	public $scriptWhitelist = '^(?:https?\:)?//gist\.github\.com/';

	public $altImageSourceAttributes = [
		'data-image-src',
		'data-src',
	];

	public $imageExtensions = [
		'jpg',
		'jpeg',
	];

	/**
	 * All of the regular expressions in use within readability.
	 * Defined up here so we don't instantiate them repeatedly in loops.
	 **/
	public $regexps = [

		// removed anyway
		//'unlikelyUnconditional' => '/show-for-|hide-|hidden-for-|visible-for-|-hidden|hidden-|community|disqus|extra|remark|rss|shoutbox|sidebar|sponsor|partner|popular|follow|mail|pagination|pager|similar|related|rotate|avatar|popup|newsletter|breadcrumb|social|next|prev|cookie|addthis|bottom|navbar|recent|featured|trend|lightbox|subscribe|popover|masthead|advert|recommend|navigation|analytics|counter|other|share|btn-group|actions|modal/i',
		'unlikelyUnconditional' => '/show-for-|hide-|hidden-for-|visible-for-|hidden/i',
		// removed if no matches with positive
		'unlikelyCandidates' => '/community|disqus|extra|remark|rss|shoutbox|sidebar|sponsor|partner|popular|follow|mail|pagination|pager|similar|related|rotate|avatar|popup|newsletter|social|next|prev|cookie|addthis|bottom|navbar|recent|featured|trend|lightbox|subscribe|popover|masthead|advert|recommend|navigation|analytics|counter|other|share|btn-group|actions|modal|header|banner|calendar|ad-|ads-|discuss|teaser|comment|announce|reply|more|replies|footer|panel|dropdown|links|promo|tags|categories|like/i',

		// vote +
		'positive' => '/article|body|column|post|misspelling|content|entry|gallery|footnotes|hentry|main|page|attachment|text|blog|story|print/i',

		// vote -
		'negative' => '/twitter|menu|facebook|combx|widget|alert|menu|aside|sticky|offer|details|ad_|more|nav|editorial|entry-title|time|date|author|comm|com-|contact|foot|head|_nav|media|outbrain|promo|related|scroll|shoutbox|sponsor|shopping|tool/i',

		'divToPElements' => '/<(a|blockquote|dl|div|img|ol|p|pre|table|ul)/i',
		//'replaceBrs' => '/(<br[^>]*>[ \n\r\t]*){2,}/i',
		'replaceFonts' => '/<(\/?)font[^>]*>/i',
		'killBreaks' => '/(<br\s*\/?>(\s|&nbsp;?)*){1,}/',
		'video' => '!//(player\.|www\.)?(youtube|vimeo|viddler)\.com!i',
	];

	/* constants */
	const FLAG_STRIP_UNLIKELYS = 1;
	const FLAG_WEIGHT_CLASSES = 2;

	public static $resourceTags = [
		'img' => 'src',
		'a' => 'href',
		'embed' => 'src',
		'object' => 'data',
		'iframe' => 'src',
		'source' => 'src', // video tag
		'audio' => 'src',
	];

	protected $parser;

	protected $customExtractors = [];

	protected $postProcessors = [];

	protected $tools;

	protected $contentExtractors = [];

	protected $html;

	protected $nodeInfoStack = [];

	/**
	 * @var \SplObjectStorage
	 */
	protected $nodesToScore;

	/**
	 * @var \SplObjectStorage
	 */
	protected $nodeScores;

	/**
	 * Create instance of Readability
	 * @param string UTF-8 encoded string
	 * @param string (optional) URL associated with HTML
	 * @param string which parser to use for turning raw HTML into a DOMDocument (either 'libxml' or 'html5lib')
	 */
	function __construct($html, $url = null, HTML5 $parser = null)
	{
		$this->url = $url;
		$this->parser = $parser ?: new HTML5;

		if ($html instanceof DOMDocument) {
			$this->dom = $html;
			$this->html = $this->parser->saveHTML($html);
		} else {
			$html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
			$this->dom = $this->parser->loadHTML($html);
			$this->html = $html;
		}

		$this->dom->registerNodeClass(DOMElement::class, JSLikeHTMLElement::class);

		$this->xpath = new DOMXPath($this->dom);

		$this->acceptableTextSiblings = array_unique(array_merge(
			$this->asideContentElements,
			$this->headerElements,
			$this->listElements,
			$this->imageElements,
			$this->embedElements,
			$this->inlineTextElements
		));

		$this->contentExtractors = [
			new ContentExtractor\MicrodataExtractor($this),
			new ContentExtractor\SemanticHtmlExtractor($this),
		];

		$this->nodesToScore = new \SplObjectStorage();
		$this->nodeScores = new \SplObjectStorage();

		$this->scriptWhitelist = (array)$this->scriptWhitelist;
		$this->embedWhitelist = (array)$this->embedWhitelist;

		if ($this->allowSelfHostedEmbed AND $this->url) {
			$host = parse_url($this->url, PHP_URL_HOST);
			if ($host) {
				$this->embedWhitelist[] = '^https?://'.$host;
			}
		}

		$upperTags = [
			'removeTags',
			'wrapperElements',
			'unlikelyContentBlockElements',
			'paragraphElements',
			'nonSemanticBlockTextElements',
			'acceptableTextSiblings',
			'asideContentElements',
			'headerElements',
			'listElements',
			'imageElements',
			'embedElements',
		];

		foreach ($upperTags as $tags) {
			if (!empty($this->$tags)) {
				$this->$tags = array_map('strtoupper', (array)$this->$tags);
			}
		}

		$this->imageExtensions = array_map('strtolower', (array)$this->imageExtensions);
	}

	/**
	 * Get article title element
	 * @return DOMElement
	 */
	public function getTitle()
	{
		return $this->articleTitle;
	}

	/**
	 * Get article content element
	 * @return DOMElement
	 */
	public function getContent()
	{
		return $this->articleContent;
	}

	/**
	 * @param string $key
	 * @return string|null|array
	 */
	public function getOpenGraph($key = null)
	{
		if ($key) {
			return isset($this->articleOpenGraph[$key]) ? $this->articleOpenGraph[$key] : null;
		} else {
			return $this->articleOpenGraph;
		}
	}

	/**
	 * @param string $key
	 * @return string|null|array
	 */
	public function getTwitterMeta($key = null)
	{
		if ($key) {
			return isset($this->articleTwitterMeta[$key]) ? $this->articleTwitterMeta[$key] : null;
		} else {
			return $this->articleTwitterMeta;
		}
	}

	/**
	 * @param string $key
	 * @return string|null|array
	 */
	public function getMeta($key = null)
	{
		if ($key) {
			return isset($this->articleMeta[$key]) ? $this->articleMeta[$key] : null;
		} else {
			return $this->articleMeta;
		}
	}

	public function getDom()
	{
		return $this->dom;
	}

	public function getHtml()
	{
		return $this->html;
	}

	public function getBody()
	{
		return $this->body;
	}

	public function getXPath()
	{
		return $this->xpath;
	}

	public static function getMetaProperties(DOMNode $dom, $ns = null)
	{
		$ns = $ns ? rtrim($ns, ':').':' : '';
		$metaNodes = $dom->getElementsByTagName('meta');

		$result = [];
		$attributes = ['name', 'property', 'itemprop'];

		foreach ($metaNodes as $meta) {
			if ($ns) {
				foreach ($attributes as $attr) {
					if ($meta->getAttribute($attr) AND strpos($meta->getAttribute($attr), $ns) === 0) {
						$property = substr($meta->getAttribute($attr), mb_strlen($ns));
						$result[$property] = $meta->getAttribute('content');
					}
				}
			} else {
				$property = $meta->getAttribute('name');
				if ($property AND strpos($property, ':') === false) {
					$result[$property] = $meta->getAttribute('content');
				}
			}
		}

		return $result;
	}

	/**
	 * Runs readability.
	 *
	 * Workflow:
	 *  1. Prep the document by removing script tags, css, etc.
	 *  2. Build readability's DOM tree.
	 *  3. Grab the article content from the current dom tree.
	 *  4. Replace the current DOM tree with the new one.
	 *  5. Read peacefully.
	 *
	 * @return boolean true if we found content, false otherwise
	 **/
	public function init()
	{
		if (!isset($this->dom->documentElement)) {
			return false;
		}

		$this->articleOpenGraph = static::getMetaProperties($this->dom, 'og');
		$this->articleTwitterMeta = static::getMetaProperties($this->dom, 'twitter');
		$this->articleMeta = static::getMetaProperties($this->dom);

		// Assume successful outcome
		$this->success = true;

		$bodyElems = $this->dom->getElementsByTagName('body');
		if ($bodyElems->length > 0) {
			if ($this->bodyCache == null) {
				$this->bodyCache = $bodyElems->item(0)->innerHTML;
			}
			if ($this->body == null) {
				$this->body = $bodyElems->item(0);
			}
		}
		if ($this->body == null) {
			$this->body = $this->dom->createElement('body');
			$this->dom->documentElement->appendChild($this->body);
		}

		/* Build readability's DOM tree */
		$articleTitle = $this->getArticleTitle();

		$articleContent = $this->grabArticle();

		if (!$articleContent) {
			$this->success = false;
			$articleContent = $this->dom->createElement('div');
			$articleContent->innerHTML = '<p>Sorry, Readability was unable to parse this page for content.</p>';
		} else if ($this->url) {
			static::absolutePaths($articleContent, $this->url, static::$resourceTags);
		}

		// postprocessing

		// Set title and content instance variables
		$this->articleTitle = $articleTitle;
		$this->articleContent = $this->parser->saveHTML($articleContent);

		return $this->success;
	}

	/**
	 * Get the article title as an H1.
	 *
	 * @return DOMElement
	 */
	protected function getArticleTitle()
	{
		$curTitle = $this->getOpenGraph('title') ?: $this->getTwitterMeta('title');

		if (!$curTitle) {
			$headTitle = $this->dom->getElementsByTagName('title');
			$curTitle = $headTitle->length ? $headTitle->item(0) : '';

			if (mb_strlen($curTitle) > 150 || mb_strlen($curTitle) < 15) {
				foreach (['h1', 'h2'] as $h) {
					$els = $this->dom->getElementsByTagName($h);
					if ($els->length === 1) {
						$curTitle = $this->getInnerText($els->item(0));
						break;
					}
				}
			}
		}

		if (!$curTitle) {
			$curTitle = '';
		}

		$curTitle = trim($curTitle);

		return $curTitle;
	}

	protected function getArticleDescription()
	{
		$desc = $this->getOpenGraph('description') ?: $this->getTwitterMeta('description');

		if (!$desc) {
			$desc = $this->getMeta('description') ?: '';
		}

		return $desc;
	}

	/**
	 * Initialize a node with the readability object. Also checks the
	 * className/id for special names to add to its score.
	 *
	 * @param Element
	 * @return void
	 **/
	protected function initializeNode(DOMNode $node)
	{
		if (!isset($this->nodeScores[$node])) {
			$this->nodeScores[$node] = 0;
		}

		if ($node->hasAttribute('itemprop')) {
			$this->nodeScores[$node] += 10;
			if ($node->getAttribute('itemprop') === 'articleBody') {
				$this->nodeScores[$node] += 50;
			}
		}

		$tagName = $this->getTag($node);

		if (in_array($tagName, $this->unlikelyContentBlockElements)) {
			$this->nodeScores[$node] -= 15;
		}

		switch ($tagName) { // unsure if strtoupper is needed, but using it just in case
			case 'MAIN':
			case 'ARTICLE':
			case 'SECTION':
			$this->nodeScores[$node] += 25;
				break;

			case 'DIV':
				$this->nodeScores[$node] += 5;
				break;

			case 'PRE':
			case 'TD':
			case 'BLOCKQUOTE':
				$this->nodeScores[$node] += 3;
				break;

			case 'ADDRESS':
			case 'OL':
			case 'UL':
			case 'DL':
			case 'DD':
			case 'DT':
			case 'LI':
				$this->nodeScores[$node] -= 3;
				break;

			case 'H1':
			case 'H2':
			case 'H3':
			case 'H4':
			case 'H5':
			case 'H6':
			case 'TH':
				$this->nodeScores[$node] -= 5;
				break;
		}

		$this->nodeScores[$node] += $this->getClassWeight($node);
	}

	protected function getSourceAttribute($tag)
	{
		return isset(static::$resourceTags[$tag]) ? static::$resourceTags[$tag] : '';
	}

	protected function isEmbed(DOMNode $node)
	{
		$tag = $this->getTag($node);
		$attr = $this->getSourceAttribute($tag);

		if (!$attr
			OR !in_array($tag, $this->embedElements)
			OR $node->nodeType !== XML_ELEMENT_NODE
			OR !$value = $node->hasAttribute($attr)
		) {
			return false;
		}

		foreach ($this->embedWhitelist as $regex) {
			if (preg_match($value, $regex)) {
				$this->dbg('Embedded content '.$this->getNodeInfo($node).' is whitelisted');
				return true;
			}
		}

		return false;
	}

	protected function isImage(DOMNode $node)
	{
		$tag = $this->getTag($node);
		$attr = $this->getSourceAttribute($tag);
		$isImage = ($tag AND in_array($tag, $this->imageElements));
		$src = ($attr AND $node->nodeType === XML_ELEMENT_NODE AND $node->getAttribute($attr));
		$isData = ($src OR strpos($src, 'data:') !== false);

		if (!$isImage OR !$attr) {
			return false;
		}

		if (!$src OR $isData) {
			foreach ($this->altImageSourceAttributes as $attr) {
				if ($altSrc = $node->getAttribute($attr)) {
					$src = $altSrc;
					break;
				}
			}
		}

		if (!$src OR $isData) {
			return false;
		}

		$ext = pathinfo($src, PATHINFO_EXTENSION);

		if (!$ext) {
			return false;
		}

		if (in_array(strtolower($ext), $this->imageExtensions)) {
			return true;
		}

		return false;
	}

	protected function isInline(DOMNode $node)
	{
		return in_array($this->getTag($node), $this->inlineTextElements);
	}

	protected function isLink(DOMNode $node)
	{
		return $this->getTag($node) === 'A';
	}

	protected function isWrapper(DOMNode $node)
	{
		return in_array($this->getTag($node), $this->wrapperElements);
	}

	protected function isParagraph(DOMNode $node)
	{
		return in_array($this->getTag($node), $this->paragraphElements);
	}

	protected function isScript(DOMNode $node)
	{
		return $this->getTag($node) === 'SCRIPT';
	}

	protected function isList(DOMNode $node)
	{
		return in_array($this->getTag($node), $this->listElements);
	}

	protected function isWhitelistedScript(DOMNode $node)
	{
		if (!$this->isScript($node)) {
			return false;
		}

		$src = $node->getAttribute('src');

		if (!$src) {
			return false;
		}

		foreach ($this->scriptWhitelist as $rule) {
			if (preg_match($src, $rule)) {
				$this->dbg('Script '.$this->getNodeInfo($node).' is whitelisted');
				return true;
			}
		}

		return false;
	}


	protected function isHeader(DOMNode $node)
	{
		$result = in_array($this->getTag($node), $this->headerElements);

		if ($result) {
			// not actually headers
			if ($node->nodeType === XML_ELEMENT_NODE AND $node->getAttribute('itemprop') === 'description') {
				$result = false;
			}
		}

		return $result;
	}

	protected function isBreak(DOMNode $node)
	{
		return (
			$this->getTag($node) === 'BR'
			AND (
				$this->isNonTextContent($node->previousSibling)
				OR $this->isNonTextContent($node->nextSibling)
			)
		);
	}

	protected function notIn(DOMNode $node, $tag)
	{
		while ($parentNode = $node->parentNode) {
			if ($this->getTag($parentNode) === $tag) {
				return false;
			}
			$node = $parentNode;
		}

		return true;
	}

	protected function isTable(DOMNode $node)
	{
		return $this->getTag($node) === 'TABLE';
	}

	protected function isColumn(DOMNode $node)
	{
		return $this->getTag($node) === 'TD' AND $this->pureText($this->getTopText($node));
	}

	protected function isText(DOMNode $node)
	{
		return $node->nodeType === XML_TEXT_NODE;
	}

	protected function getStackInfo(DOMNode $node)
	{
		$result = [];

		if ($node->nodeType === XML_ELEMENT_NODE) {
			if ($tag = $this->getTag($node)) {
				$result['tags'] = [$tag];
			}
			if ($id = $node->getAttribute('id')) {
				$result['id'] = [$id];
			}
			if ($class = $node->getAttribute('class')) {
				if ($class = array_filter(explode(' ', $class))) {
					$result['class'] = $class;
				}
			}
		} else if ($node->nodeType === XML_TEXT_NODE) {
			$result['tags'] = ['TEXT'];
		}

		return $result;
	}

	protected function getId(DOMNode $node)
	{
		return $node->nodeType === XML_ELEMENT_NODE ? $node->getAttribute('id') : null;
	}

	protected function getClass(DOMNode $node)
	{
		return $node->nodeType === XML_ELEMENT_NODE ? $node->getAttribute('class') : '';
	}

	protected function isContentChild(DOMNode $node)
	{
		if ($node->nodeType !== XML_ELEMENT_NODE AND $node->nodeType !== XML_TEXT_NODE) {
			return false;
		}

		if ($node->nodeType === XML_TEXT_NODE) {
			return $this->hasSentence($node);
		}

		$hasInlineContent = $this->hasInlineContent($node);

		if ($node->nodeType === XML_ELEMENT_NODE AND !$hasInlineContent AND ($this->getId($node) OR $this->getClass($node))) {
			return false;
		}

		return (

			$hasInlineContent
				AND (
					$this->isParagraph($node)
					OR $this->isInline($node)
				)

			OR $this->isNonTextContent($node)

			OR ($this->isTable($node) AND !$node->getElementsByTagName('table')->length)

			OR (
				($this->isList($node) OR $this->isTable($node))
				AND strlen($this->getPureNonLinkText($node)) > 25
			)

		);
	}

	protected function parseDom($node, $headerFound = false)
	{
		$childrenTags = [];
		$contentChildren = [];

		foreach ($node->childNodes as $child) {
			if ($child->nodeType === XML_ELEMENT_NODE OR ($child->nodeType === XML_TEXT_NODE AND trim($child->textContent))) {
				$childrenTags[] = $child;
			}
			if (($headerFound AND $this->isHeader($child)) OR $this->isContentChild($child)) {
				$contentChildren[] = $child;
			}
			if ($this->isHeader($child)) {
				if (!$headerFound) {
					$contentChildren = [];
				}
				$headerFound = true;
			}
		}

		if (!$contentChildren AND $this->hasSentence($node)) {
			$contentChildren = [$node];
		}

		if (count($contentChildren) === 1 AND !$headerFound) {
			foreach ($node->childNodes as $child) {
				if ($child->nodeType === XML_ELEMENT_NODE AND !$this->isHeader($child)) {
					foreach ($this->headerElements as $headerElement) {
						if ($child->getElementsByTagName($headerElement)->length) {
							$contentChildren = [];
							break 2;
						}
					}
				}
			}
		}

		$nodeInfo = $this->nodeInfoStack;
		$this->nodeInfoStack = array_merge_recursive($this->nodeInfoStack, $this->getStackInfo($node));

		$countBefore = $this->nodesToScore->count();

		if (count($contentChildren) === 1) {
			if ($contentChildren[0]->nodeType === XML_TEXT_NODE
				OR ($p = strlen($this->pureText($contentChildren[0]->textContent)) AND strlen($this->pureText($node->textContent)) / $p > 1.2)
			) {
				$this->nodesToScore[$node] = [
					'parents' => $this->nodeInfoStack,
					'children' => $childrenTags,
				];
			} else {
				$this->nodesToScore[$contentChildren[0]] = [
					'parents' => $this->nodeInfoStack,
					'children' => $childrenTags,
				];
			}
		} else if (count($contentChildren) > 1) {
			$this->nodesToScore[$node] = [
				'parents' => $this->nodeInfoStack,
				'children' => $childrenTags,
			];
		} else {
			foreach ($node->childNodes as $child) {
				if ($this->isWrapper($child) OR ($this->isList($child) AND strlen($this->getPureNonLinkText($child))) > 25) {
					$this->parseDom($child, $headerFound);
				}
			}
		}

		if ($this->nodesToScore->count() === $countBefore AND strlen($this->pureText($node->textContent)) > 100) {
			$this->nodesToScore[$node] = [
				'parents' => $this->nodeInfoStack,
				'children' => $childrenTags,
			];
		}

		$this->nodeInfoStack = $nodeInfo;

		return $contentChildren;
	}

	protected function isNonTextContent(DOMNode $node)
	{
		if ($node->nodeType !== XML_ELEMENT_NODE) {
			return false;
		}

		return (
			// is it an embedded content from a whitelisted source?
			$this->isEmbed($node)
			// is it a JPEG?
			OR $this->isImage($node)
			// is it a script from a whitelisted source?
			OR $this->isWhitelistedScript($node)
		);
	}

	protected function isPseudoParagraph($node)
	{
		return ($this->isWrapper($node) AND $this->hasInlineContent($node));
	}

	protected function hasInlineContent($node)
	{
		if ($this->getPureNonLinkText($node)) {
			return true;
		}

		foreach ($node->getElementsByTagName('*') as $child) {
			if ($this->isNonTextContent($child)) {
				return true;
			}
		}

		return false;
	}

	protected function hasSentence($text)
	{
		if ($text instanceof DOMNode) {
			if ($text->nodeType === XML_TEXT_NODE) {
				$text = $text->nodeValue;
			} else if ($text->nodeType === XML_ELEMENT_NODE) {
				$text = $this->getTopText($text, true, true);
			}
		}

		if (!is_string($text)) {
			throw new \Exception('Wrong variable type.');
		}

		return strpos($text, '.') !== false OR strpos($text, '?') !== false OR strpos($text, '!') !== false;
	}

	/***
	 * grabArticle - Using a variety of metrics (content score, classname, element types), find the content that is
	 *               most likely to be the stuff a user wants to read. Then return it wrapped up in a div.
	 *
	 * @return DOMElement
	 **/
	protected function grabArticle($page = null)
	{
		if (!$page) {
			$page = $this->body;
		}

		foreach ($this->contentExtractors as $extractor) {
			if ($result = $extractor->parse($page)) {
				return $result;
			}
			if ($root = $extractor->getRoot()) {
				$page = $root;
			}
		}

		foreach ($this->removeTags as $tag) {
			$this->removeNode($page->getElementsByTagName($tag));
		}

		$this->parseDom($page);

		if (!$this->nodesToScore->count()) {
			die('Nothing found.');
		} else if ($this->nodesToScore->count() === 1) {
			foreach ($this->nodesToScore as $node) {
				return $node;
			}
		} else {

			foreach ($this->nodesToScore as $node) {
				echo $this->getNodeInfo($node).' :: '.json_encode($this->nodesToScore[$node]).'<br>';
			}

			exit;
		}


		//var_dump($this->nodeInfo, $this->nodesToScore);

		/*
		 * First, node prepping. Trash nodes that look cruddy (like ones with the class name "comment", etc).
		 * Note: Assignment from index for performance. See http://www.peachpit.com/articles/article.aspx?p=31567&seqNum=5
		 */
		$tagElements = $page->getElementsByTagName('*');
		$nodesToScore = [];

		for ($nodeIndex = 0; ($node = $tagElements->item($nodeIndex)); $nodeIndex++) {
			$tagName = strtolower($node->tagName);

			if ($tagName === 'body') {
				continue;
			}

			if ($this->flagIsActive(self::FLAG_STRIP_UNLIKELYS)) {
				/* Remove unlikely candidates */
				$matchString = $node->getAttribute('class').':'.$node->getAttribute('id');

				if ($this->regexps['unlikelyUnconditional'] AND preg_match($this->regexps['unlikelyUnconditional'], $matchString)) {
					$this->removeNode($node, 'unconditionally');
					$nodeIndex--;
					continue;
				} else if (preg_match($this->regexps['unlikelyCandidates'], $matchString) &&
					!preg_match($this->regexps['positive'], $matchString)
				) {
					$this->removeNode($node, 'unlikely candidate');
					$nodeIndex--;
					continue;
				}
			}

			$nodeInfo = $this->getNodeInfo($node);

			/*
			if ($this->isImage($node)) {
				$this->dbg('Image candidate '.$nodeInfo);
				$nodesToScore[] = $node;
				continue;
			}

			if ($this->isEmbed($node)) {
				$this->dbg('Embed candidate '.$nodeInfo);
				$nodesToScore[] = $node;
				continue;
			}
			*/

			// skip wrappers with paragraphs
			if ($this->isWrapper($node)) {
				foreach ($node->childNodes as $child) {
					if ($this->isParagraph($child) OR $this->isWrapper($child)) {
						continue 2;
					}
				}
			}

			if ($this->isParagraph($node) OR $this->isWrapper($node)) {
				if (strlen($this->getTopText($node)) > 25) {
					$nodesToScore[] = $node;
				}
			}
		}

		/**
		 * Loop through all paragraphs, and assign a score to them based on how content-y they look.
		 * Then add their score to their parent node.
		 *
		 * A score is determined by things like number of commas, class names, etc. Maybe eventually link density.
		 **/
		$candidates = [];
		$orderPenalty = 5;
		$itempropMode = false;

		foreach ($nodesToScore as $node) {
			$tagName = $this->getTag($node);
			$nodeInfo = $this->getNodeInfo($node);
			$contentScore = 0;

			$parentNode = $node->parentNode;

			if (!$parentNode) {
				continue;
			}

			if (in_array($parentNode->tagName, $this->listElements)) {
				$parentNode = $parentNode->parentNode;
			}

			$parentNodeInfo = $this->getNodeInfo($parentNode);

			$grandParentNode = !$parentNode ? null : (($parentNode->parentNode instanceof DOMElement) ? $parentNode->parentNode : null);

			if ($parentNode->tagName === 'td') {
				while ($grandParentNode->tagName !== 'table' AND $grandParentNode) {
					$grandParentNode = $grandParentNode->parentNode;
				}
			} else if ($grandParentNode->tagName === 'td') {
				while ($grandParentNode->tagName !== 'table' AND $grandParentNode) {
					$grandParentNode = $grandParentNode->parentNode;
				}
			}

			if ($itempropMode AND !(
					$parentNode->hasAttribute('itemprop')
					OR $parentNode->hasAttribute('itemtype')
					OR ($grandParentNode
						AND (
							$grandParentNode->hasAttribute('itemprop')
							OR $grandParentNode->hasAttribute('itemprop'))))
			) {
				continue;
			}

			if ($node->hasAttribute('itemprop') OR $parentNode->hasAttribute('itemtype')) {
				$contentScore += 5;
				$this->dbg('+5 boost for itemprop '.$nodeInfo);
				if ($node->getAttribute('itemprop') === 'description') {
					$contentScore += 25;
					$this->dbg('+25 boost for itemprop=description '.$nodeInfo);
					$itempropMode = true;
				}
				if ($node->getAttribute('itemprop') === 'articleBody') {
					$contentScore += 50;
					$this->dbg('+50 boost for itemprop=articleBody '.$nodeInfo);
					$itempropMode = true;
				}
			}

			$innerHTML = $node->innerHTML;
			$parentHTML = $parentNode->innerHTML;

			/* Add points for line breaks */
			$headers = substr_count($innerHTML, '</h1') * 50;
			if ($headers) $this->dbg('+'.$headers.' boost for header '.$nodeInfo);
			$contentScore += $headers;

			$headers = substr_count($parentHTML, '</h1') * 25;
			if ($headers) $this->dbg('+'.$headers.' boost for parent with header '.$parentNodeInfo);
			$contentScore += $headers;

			if ($this->isParagraph($node) OR $this->isList($node)) {
				$contentScore += 15;
				$this->dbg('+15 boost for text in '.$nodeInfo);
			}

			foreach ($node->childNodes as $child) {
				$childInfo = $this->getNodeInfo($child);
				$isTextNode = $this->isText($child);
				$isInline = $this->isInline($child);
				$isBreak = $this->isBreak($child);
				$isImage = $this->isImage($child);
				$isEmbed = $this->isEmbed($child);
				$isMedia = ($isImage OR $isEmbed);

				$innerTEXT = ($isTextNode OR $isInline) ? $this->getTopText($node) : '';

				/* If this paragraph is less than 25 characters, don't even count it. */
				if (($isTextNode OR $isInline) AND strlen($innerTEXT) < 25) {
					continue;
				}

				$contentScore = 1;

				if (($isImage AND !$child->hasAttribute('id')) OR $isEmbed) {
					$this->dbg('+25 boost for media '.$childInfo);
					$contentScore += 25;
				}

				if ($isBreak) {
					$paragraphSiblings = substr_count($innerHTML, '<br');
					if ($paragraphSiblings) $this->dbg('+'.$paragraphSiblings.' boost for breaks '.$nodeInfo);
					$contentScore += $paragraphSiblings;
				}

				if ($innerTEXT) {
					/* Add points for any commas and dots within this paragraph */

					$commas = substr_count($innerTEXT, ',');
					if ($commas) $this->dbg('+'.$commas.' boost for commas '.$childInfo);
					$contentScore += $commas;

					$dots = substr_count($innerTEXT, '.');
					if ($dots) $this->dbg('+'.$dots.' boost for dots '.$childInfo);
					$contentScore += $dots;

					/* For every 100 characters in this paragraph, add another point. Up to 10 points. */
					//$contentScore += min(floor(mb_strlen($innerTEXT) / 100), 10);

					//$strlen = strlen($innerTEXT) / 2;
					//if ($strlen) $this->dbg('+'.$strlen.' boost for strlen / 2 '.$childInfo);
					//$contentScore += $strlen;
				}
			}

			if (!isset($this->nodeScores[$node])) {
				$candidates[] = $node;
			}

			if (!isset($this->nodeScores[$parentNode])) {
				$candidates[] = $parentNode;
			}

			$this->addScore($node, $contentScore);
			$this->dbg('+'.$contentScore.' FROM CHILDREN '.$nodeInfo);

			$paragraphSiblings = substr_count($parentHTML, '<'.$tagName);
			if ($paragraphSiblings) $this->dbg('+'.$paragraphSiblings.' boost for siblings with same tag in parent '.$nodeInfo);
			$contentScore += $paragraphSiblings;

			$this->addScore($parentNode, $contentScore / 2);
			$this->dbg('+'.($contentScore / 2).' FROM GRANDCHILDREN '.$this->getNodeInfo($parentNode));
		}

		/**
		 * After we've calculated scores, loop through all of the possible candidate nodes we found
		 * and find the one with the highest score.
		 **/
		$topCandidate = null;
		for ($c = 0, $cl = count($candidates); $c < $cl; $c++) {
			/**
			 * Scale the final candidates score based on link density. Good content should have a
			 * relatively small link density (5% or less) and be mostly unaffected by this operation.
			 **/
			$readability = $candidates[$c]->getAttributeNode('readability');
			$readability->value = $readability->value * (1 - $this->getLinkDensity($candidates[$c]));

			$this->dbg('Candidate: '.$candidates[$c]->tagName.' ('.$this->getNodeInfo($candidates[$c]).') with score '.$readability->value);

			if (!$topCandidate || $readability->value > (int)$topCandidate->getAttribute('readability')) {
				$topCandidate = $candidates[$c];
			}
		}

		/**
		 * If we still have no top candidate, just use the body as a last resort.
		 * We also have to copy the body node so it is something we can modify.
		 **/
		if ($topCandidate === null || strtoupper($topCandidate->tagName) == 'BODY') {
			$topCandidate = $this->dom->createElement('div');
			if ($page instanceof DOMDocument) {
				if (!isset($page->documentElement)) {
					// we don't have a body either? what a mess! :)
				} else {
					$topCandidate->innerHTML = $page->documentElement->innerHTML;
					$page->documentElement->innerHTML = '';
					$page->documentElement->appendChild($topCandidate);
				}
			} else {
				$topCandidate->innerHTML = $page->innerHTML;
				$page->innerHTML = '';
				$page->appendChild($topCandidate);
			}
			$this->initializeNode($topCandidate);
		}

		$this->dbg('TOP CANDIDATE: '.$this->getNodeInfo($topCandidate).' '.$topCandidate->getAttribute('readability'));

		/**
		 * Now that we have the top candidate, look through its siblings for content that might also be related.
		 * Things like preambles, content split by ads that we removed, etc.
		 **/
		$articleContent = $this->dom->createElementNS(static::HTML_NS, 'div');
		$siblingScoreThreshold = max(10, ((int)$topCandidate->getAttribute('readability')) * 0.2);
		$siblingNodes = $topCandidate->parentNode->childNodes;

		if (!isset($siblingNodes)) {
			$siblingNodes = new \stdClass;
			$siblingNodes->length = 0;
		}

		$siblings = [];
		foreach ($siblingNodes as $sibling) {
			$siblings[] = $sibling;
		}

		foreach ($siblings as $siblingNode) {
			$valid = false;

			$this->dbg('Looking at sibling node: '.$siblingNode->nodeName.(($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode->hasAttribute('readability')) ? (' with score '.$siblingNode->getAttribute('readability')) : ''));

			if ($siblingNode === $topCandidate) {
				$valid = true;
			}

			$contentBonus = 0;

			/* Give a bonus if sibling nodes and top candidates have the example same classname */
			if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode->getAttribute('class') == $topCandidate->getAttribute('class') && $topCandidate->getAttribute('class') != '') {
				$contentBonus += ((int)$topCandidate->getAttribute('readability')) * 0.2;
			}

			if ($siblingNode->nodeType === XML_ELEMENT_NODE && $siblingNode->hasAttribute('readability') && (((int)$siblingNode->getAttribute('readability')) + $contentBonus) >= $siblingScoreThreshold) {
				$valid = true;
			}

			if ($siblingNode instanceof DOMElement) {
				if ($siblingNode->hasAttribute('itemprop')) {
					$valid = true;
				}

				if ($siblingNode->getElementsByTagName('img')->length) {
					$valid = true;
				}
			}

			if (strtoupper($siblingNode->nodeName) == 'P') {
				$linkDensity = $this->getLinkDensity($siblingNode);
				$nodeContent = $this->getInnerText($siblingNode);
				$nodeLength = mb_strlen($nodeContent);

				if ($nodeLength > 80 && $linkDensity < 0.25) {
					$valid = true;
				} else if ($nodeLength < 80 && $linkDensity === 0 && preg_match('/\.( |$)/', $nodeContent)) {
					$valid = true;
				}
			}

			if ($valid) {

				/* To ensure a node does not interfere with readability styles, remove its classnames */
				//$siblingNode->removeAttribute('class');

				/* Append sibling and subtract from our list because it removes the node when you append to another node */

				if ($siblingNode === $topCandidate) {
					$this->dbg('Appending node: '.$this->getNodeInfo($siblingNode));
					$articleContent->appendChild($siblingNode);
				} else {
					$this->dbg('Appending node (EMULATION): '.$this->getNodeInfo($siblingNode));
				}
			}
		}

		/**
		 * So we have all of the content that we need. Now we clean it up for presentation.
		 **/
		//$this->prepArticle($articleContent);


		/**
		 * Now that we've gone through the full algorithm, check to see if we got any meaningful content.
		 * If we didn't, we may need to re-run grabArticle with different flags set. This gives us a higher
		 * likelihood of finding the content, and the sieve approach gives us a higher likelihood of
		 * finding the -right- content.
		 **/
		/*
		if (mb_strlen($this->getInnerText($articleContent, false)) < 250) {
			// TODO: find out why element disappears sometimes, e.g. for this URL http://www.businessinsider.com/6-hedge-fund-etfs-for-average-investors-2011-7
			// in the meantime, we check and create an empty element if it's not there.
			if (!isset($this->body->childNodes)) $this->body = $this->dom->createElement('body');
			$this->body->innerHTML = $this->bodyCache;

			if ($this->flagIsActive(self::FLAG_STRIP_UNLIKELYS)) {
				$this->removeFlag(self::FLAG_STRIP_UNLIKELYS);

				return $this->grabArticle($this->body);
			} else if ($this->flagIsActive(self::FLAG_WEIGHT_CLASSES)) {
				$this->removeFlag(self::FLAG_WEIGHT_CLASSES);

				return $this->grabArticle($this->body);
			} else {
				return false;
			}
		}
		*/

		return $articleContent;
	}

	protected function addScore(DOMNode $node, $score)
	{
		if ($node->nodeType !== XML_ELEMENT_NODE) {
			throw new \Exception('Non-element node '.print_r($node, true));
		}

		if (!$this->nodeScores->contains($node)) {
			$this->initializeNode($node);
		}

		$this->nodeScores[$node] += $score;
	}

	/**
	 * Remove the style attribute on every $e and under.
	 *
	 * @param DOMNode $e
	 * @return void
	 */
	public function cleanStyles(DOMNode $e)
	{
		if ($e->nodeType !== XML_ELEMENT_NODE) {
			return;
		}

		foreach ($this->xpath->query('//*[@style]', $e) as $elem) {
			$elem->removeAttribute('style');
		}
	}

	/**
	 * Get an elements class/id weight. Uses regular expressions to tell if this
	 * element looks good or bad.
	 *
	 * @param DOMElement $e
	 * @return number (Integer)
	 */
	public function getClassWeight($e)
	{
		if (!$this->flagIsActive(self::FLAG_WEIGHT_CLASSES)) {
			return 0;
		}

		$weight = 0;

		/* Look for a special classname */
		if ($e->hasAttribute('class') && $e->getAttribute('class') != '') {
			if (preg_match($this->regexps['negative'], $e->getAttribute('class')) OR preg_match($this->regexps['unlikelyCandidates'], $e->getAttribute('class'))) {
				$weight -= 25;
			}
			if (preg_match($this->regexps['positive'], $e->getAttribute('class'))) {
				$weight += 25;
			}
		}

		/* Look for a special ID */
		if ($e->hasAttribute('id') && $e->getAttribute('id') != '') {
			if (preg_match($this->regexps['negative'], $e->getAttribute('id')) OR preg_match($this->regexps['unlikelyCandidates'], $e->getAttribute('class'))) {
				$weight -= 25;
			}
			if (preg_match($this->regexps['positive'], $e->getAttribute('id'))) {
				$weight += 25;
			}
		}

		return $weight;
	}

	/**
	 * Remove extraneous break tags from a node.
	 *
	 * @param DOMElement $node
	 * @return void
	 */
	public function killBreaks($node)
	{
		$html = $node->innerHTML;
		$html = preg_replace($this->regexps['killBreaks'], '<br />', $html);
		$node->innerHTML = $html;
	}

	/**
	 * Clean a node of all elements of type "tag".
	 * (Unless it's a youtube/vimeo video. People love movies.)
	 *
	 * Updated 2012-09-18 to preserve youtube/vimeo iframes
	 *
	 * @param DOMElement $e
	 * @param string $tag
	 * @return void
	 */
	public function clean($e, $tag)
	{
		$targetList = $e->getElementsByTagName($tag);
		$isEmbed = ($tag == 'iframe' || $tag == 'object' || $tag == 'embed');

		for ($y = $targetList->length - 1; $y >= 0; $y--) {
			/* Allow youtube and vimeo videos through as people usually want to see those. */
			if ($isEmbed) {
				$attributeValues = '';
				for ($i = 0, $il = $targetList->item($y)->attributes->length; $i < $il; $i++) {
					$attributeValues .= $targetList->item($y)->attributes->item($i)->value.'|'; // DOMAttr? (TODO: test)
				}

				/* First, check the elements attributes to see if any of them contain youtube or vimeo */
				if (preg_match($this->regexps['video'], $attributeValues)) {
					continue;
				}

				/* Then check the elements inside this element for the same. */
				if (preg_match($this->regexps['video'], $targetList->item($y)->innerHTML)) {
					continue;
				}
			}
			$this->removeNode($targetList->item($y));
		}
	}

	/**
	 * Prepare the article node for display. Clean out any inline styles,
	 * iframes, forms, strip extraneous <p> tags, etc.
	 *
	 * @param DOMElement
	 * @return void
	 */
	function prepArticle($articleContent)
	{
		$this->cleanStyles($articleContent);

		$this->killBreaks($articleContent);

		/* Clean out junk from the article content */
		$this->clean($articleContent, 'object');

		/**
		 * If there is only one h2, they are probably using it
		 * as a header and not a subheader, so remove it since we already have a header.
		 ***/
		if (!$this->lightClean AND $articleContent->getElementsByTagName('h2')->length === 1 AND $this->dom->getElementsByTagName('h1')->length === 0) {
			$this->clean($articleContent, 'h2');
		}

		$this->clean($articleContent, 'h1');

		$this->clean($articleContent, 'iframe');

		$this->cleanHeaders($articleContent);

		/* Remove extra paragraphs */
		$articleParagraphs = $articleContent->getElementsByTagName('p');
		for ($i = $articleParagraphs->length - 1; $i >= 0; $i--) {
			$imgCount = $articleParagraphs->item($i)->getElementsByTagName('img')->length;
			$embedCount = $articleParagraphs->item($i)->getElementsByTagName('embed')->length;
			$objectCount = $articleParagraphs->item($i)->getElementsByTagName('object')->length;
			$iframeCount = $articleParagraphs->item($i)->getElementsByTagName('iframe')->length;

			if ($imgCount === 0 && $embedCount === 0 && $objectCount === 0 && $iframeCount === 0 && $this->getInnerText($articleParagraphs->item($i), false) == '') {
				$this->removeNode($articleParagraphs->item($i));
			}
		}

		$image = $this->getOpenGraph('image');

		if ($image AND substr_count($articleContent->innerHTML, '<img ') === 0) {
			$articleContent->innerHTML = '<p><img src="'.$image.'"></p>'.$articleContent->innerHTML;
		}
	}

	/**
	 * Clean out spurious headers from an Element. Checks things like classnames and link density.
	 *
	 * @param DOMElement $e
	 * @return void
	 */
	public function cleanHeaders($e)
	{
		for ($headerIndex = 1; $headerIndex < 3; $headerIndex++) {
			$headers = $e->getElementsByTagName('h'.$headerIndex);
			for ($i = $headers->length - 1; $i >= 0; $i--) {
				if ($this->getClassWeight($headers->item($i)) < 0 || $this->getLinkDensity($headers->item($i)) > 0.33) {
					$this->removeNode($headers->item($i));
				}
			}
		}
	}

	public function flagIsActive($flag)
	{
		return ($this->flags & $flag) > 0;
	}

	public function addFlag($flag)
	{
		$this->flags = $this->flags | $flag;
	}

	public function removeFlag($flag)
	{
		$this->flags = $this->flags & ~$flag;
	}
}
