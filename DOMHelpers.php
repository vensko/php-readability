<?php
/**
 * Author: Dzmitry Vensko
 * Date: 11.04.2015 2:30
 */
namespace Readability;

use DOMDocument, DOMXPath, DOMNodeList, DOMNode, DOMElement;

trait DOMHelpers
{
	public $debug = true;

	/**
	 * @var DOMDocument
	 */
	protected $dom;

	/**
	 * @var DOMXPath
	 */
	protected $xpath;

	public $inlineTextElements = [
		'i', 'em', 'b', 'strong', 'small', 'tt', 'mark', 'u',
		'abbr', 'acronym', 'cite', 'code', 'dfn', 'kbd', 'samp',
		'bdo', 'var', 'q', 'sub', 'sup',
	];

	public $spacesRegex = '/\s{2,}/';

	public function __construct(ContentExtractor $readability)
	{
		$this->dom = $readability->getDom();
		$this->xpath = $readability->getXPath();
	}

	/**
	 * Get the density of links as a percentage of the content
	 * This is the amount of text that is inside a link divided by the total text in the node.
	 *
	 * @param DOMElement $e
	 * @return number (float)
	 */
	public function getLinkDensity($e)
	{
		$links = $e->getElementsByTagName('a');
		$textLength = mb_strlen($this->getInnerText($e));
		$linkLength = 0;
		for ($i = 0, $il = $links->length; $i < $il; $i++) {
			$linkLength += mb_strlen($this->getInnerText($links->item($i)));
		}
		if ($textLength > 0) {
			return $linkLength / $textLength;
		} else {
			return 0;
		}
	}

	/**
	 * Get the number of times a string $s appears in the node $e.
	 *
	 * @param DOMElement $e
	 * @param string - what to count. Default is ","
	 * @return number (integer)
	 **/
	public function getCharCount($e, $s = ',')
	{
		return substr_count($this->getInnerText($e), $s);
	}

	/**
	 * Get the inner text of a node.
	 * This also strips out any excess whitespace to be found.
	 *
	 * @param DOMElement $
	 * @param boolean $normalizeSpaces (default: true)
	 * @return string
	 **/
	public function getInnerText($e, $normalizeSpaces = true)
	{
		if (!isset($e->textContent)) {
			return '';
		}

		$textContent = trim($e->textContent);

		if ($normalizeSpaces AND $textContent !== '') {
			return preg_replace($this->spacesRegex, ' ', $textContent);
		} else {
			return $textContent;
		}
	}

	protected function removeNode($node, $reason = '')
	{
		if ($node instanceof DOMNode) {
			$this->dbg('Removed 1 node '.$reason.' '.$this->getNodeInfo($node));
			$node->parentNode->removeChild($node);
			return true;
		} else if (($node instanceof DOMNodeList AND $node->length) OR (is_array($node) AND $node)) {

			$tagNames = [];
			$itemsToRemove = [];
			foreach ($node as $item) {
				if (isset($item->tagName)) {
					if (!isset($tagNames[$item->tagName])) {
						$tagNames[$item->tagName] = 1;
					} else {
						$tagNames[$item->tagName]++;
					}
				}
				$itemsToRemove[] = $item;
			}

			// https://php.net/manual/en/domnode.removechild.php#90292
			foreach ($itemsToRemove as $item) {
				$item->parentNode->removeChild($item);
			}

			foreach ($tagNames as $tag => $removed) {
				$this->dbg('Removed '.$removed.' &lt;'.$tag.'&gt; nodes'.$reason);
			}

			return (bool)$tagNames;
		}

		return false;
	}

	protected function selectByAttribute($attribute, $value = null, $root = null)
	{
		if ($value === null) {
			$query = '@'.$attribute;
		} else {
			$query = (array)$value;
			foreach ($query as &$prop) {
				$prop = 'contains(@'.$attribute.', "'.$prop.'")';
			}
			$query = implode(' or ', $query);
		}

		return $this->xpath->query('//*['.$query.']', $root);
	}

	/**
	 * @param DOMNodeList|array $nodes
	 * @return DOMElement
	 */
	protected function wrapNodeList($nodes)
	{
		$wrapper = $this->dom->createElementNS('http://www.w3.org/1999/xhtml', 'div');

		foreach ($nodes as $node) {
			$wrapper->appendChild($node);
		}

		return $wrapper;
	}

	public static function absolutePaths(DOMNode $dom, $url, $elements = [])
	{
		$base = $dom->getElementsByTagName('base');

		if ($base->length AND $base = $base->item(0)->getAttribute('href')) {
			$base = rtrim($base, '/');
		} else {
			$base = dirname($url);
		}

		$url = parse_url($url);
		$root = $url['scheme'].'://';
		if (isset($url['user']) AND $url['user'] !== '') {
			$root .= (isset($url['pass']) AND $url['pass'] !== '') ? $url['user'].':'.$url['pass'] : $url['user'];
			$root .= '@';
		}
		$root .= $url['host'];

		foreach ($elements as $element => $attr) {
			$images = $dom->getElementsByTagName($element);

			foreach ($images as $image) {

				if (!$prop = $image->getAttribute($attr)) {
					continue;
				}

				if ($prop[0] !== '/' AND strpos($prop, '://') === false) {
					$image->setAttribute($attr, $base.'/'.$prop);
				}

				$prop = $image->getAttribute($attr);

				if ($prop[0] === '/' AND (!isset($prop[1]) OR $prop[1] !== '/')) {
					$image->setAttribute($attr, $root.$prop);
				}
			}
		}
	}

	/**
	 * Debug
	 */
	protected function dbg($msg, $return = false)
	{
		if ($this->debug) {
			$msg = '* '.$msg.'<br>';
			if ($return) {
				return $msg;
			} else {
				echo $msg;
			}
		}
	}

	protected function nodeHasChildren($node, $tags)
	{
		$tags = array_map('strtoupper', (array)$tags);

		foreach ($node->childNodes as $child) {
			foreach ($tags as $tag) {
				if ($this->getTag($child) === $tag) {
					return true;
				}
			}
		}

		return false;
	}

	protected function pureText($text, $withDots = true)
	{
		$dots = $withDots ? '\\.\\?\\!' : '';
		$text = preg_replace('/[^\w\d'.$dots.' ]+/u', ' ', $text);
		$text = preg_replace($this->spacesRegex, ' ', $text);
		$text = trim($text);

		return $text;
	}

	protected function getTopText($node, $subTags = true, $withLinks = false)
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			$result = $node->nodeValue;
		} else {
			$result = '';
			foreach ($node->childNodes as $child) {
				$tag = $this->getTag($child);
				if ($child->nodeType === XML_TEXT_NODE) {
					$result .= ' '.$child->nodeValue;
				} else if ($subTags AND in_array($tag, $this->inlineTextElements)) {
					if ($tag === 'BR') {
						$result .= " \n";
					} else {
						if ($tag === 'A' AND !$withLinks) {
							continue;
						}
						$result .= call_user_func([$this, __FUNCTION__], $child);
					}
				}
			}
		}

		$result = $this->pureText($result);

		return $result;
	}

	protected function getPureNonLinkText($node)
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			return $this->pureText($node->nodeValue);
		} else if ($node->nodeType === XML_ELEMENT_NODE) {
			$clone = $node->cloneNode(true);

			foreach ($clone->getElementsByTagName('a') as $link) {
				$link->parentNode->removeChild($link);
			}

			$text = $this->pureText($clone->textContent);

			return $text;
		} else {
			return '';
		}
	}

	protected function getTag($node)
	{
		return isset($node->tagName) ? $node->tagName : '';
	}

	protected function getNodeInfo($node)
	{
		$tag = $this->getTag($node);

		if ($tag) {
			$tag = '&lt;'.$tag.'&gt;';
		} else if ($node->nodeType === XML_TEXT_NODE) {
			$tag = 'TEXT';
		}

		if (method_exists($node, 'hasAttribute')) {
			$id = $node->hasAttribute('id') ? ' #'.$node->getAttribute('id') : '';
			$class = $node->hasAttribute('class') ? ' .'.$node->getAttribute('class') : '';
			$src = $node->hasAttribute('src') ? ' src='.$node->getAttribute('src') : '';
			return $tag.$id.$class.$src;
		} else {
			return $tag;
		}
	}
}
