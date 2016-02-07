<?php

namespace Readability;

use DOMElement;
use Masterminds\HTML5;

/**
 * JavaScript-like HTML DOM Element
 *
 * This class extends PHP's DOMElement to allow
 * users to get and set the innerHTML property of
 * HTML elements in the same way it's done in
 * JavaScript.
 *
 * Example usage:
 * @code
 * require_once 'JSLikeHTMLElement.php';
 * header('Content-Type: text/plain');
 * $doc = new DOMDocument();
 * $doc->registerNodeClass('DOMElement', 'JSLikeHTMLElement');
 * $doc->loadHTML('<div><p>Para 1</p><p>Para 2</p></div>');
 * $elem = $doc->getElementsByTagName('div')->item(0);
 *
 * // print innerHTML
 * echo $elem->innerHTML; // prints '<p>Para 1</p><p>Para 2</p>'
 * echo "\n\n";
 *
 * // set innerHTML
 * $elem->innerHTML = '<a href="http://fivefilters.org">FiveFilters.org</a>';
 * echo $elem->innerHTML; // prints '<a href="http://fivefilters.org">FiveFilters.org</a>'
 * echo "\n\n";
 *
 * // print document (with our changes)
 * echo $doc->saveXML();
 * @endcode
 *
 * @author Keyvan Minoukadeh - http://www.keyvan.net - keyvan@keyvan.net
 * @see http://fivefilters.org (the project this was written for)
 */

class JSLikeHTMLElement extends DOMElement implements \JsonSerializable
{
	/**
	 * Used for setting innerHTML like it's done in JavaScript:
	 * @code
	 * $div->innerHTML = '<h2>Chapter 2</h2><p>The story begins...</p>';
	 * @endcode
	 */
	public function __set($name, $value)
	{
		if ($name == 'innerHTML') {
			// first, empty the element
			for ($x = $this->childNodes->length - 1; $x >= 0; $x--) {
				$this->removeChild($this->childNodes->item($x));
			}

			if ($value !== '') {
				$value = mb_convert_encoding($value, 'HTML-ENTITIES', 'UTF-8');
				$dom = (new HTML5)->loadHTML('<htmlfragment>'.$value.'</htmlfragment>');
				$import = $dom->getElementsByTagName('htmlfragment')->item(0);

				foreach ($import->childNodes as $child) {
					$importedNode = $this->ownerDocument->importNode($child, true);
					$this->appendChild($importedNode);
				}
			}
		} else {
			$trace = debug_backtrace();
			trigger_error('Undefined property via __set(): '.$name.' in '.$trace[0]['file'].' on line '.$trace[0]['line'], E_USER_NOTICE);
		}
	}

	/**
	 * Used for getting innerHTML like it's done in JavaScript:
	 * @code
	 * $string = $div->innerHTML;
	 * @endcode
	 */
	public function __get($name)
	{
		if ($name == 'innerHTML') {
			$inner = '';
			foreach ($this->childNodes as $child) {
				$inner .= $this->ownerDocument->saveXML($child);
			}

			return $inner;
		}

		$trace = debug_backtrace();
		trigger_error('Undefined property via __get(): '.$name.' in '.$trace[0]['file'].' on line '.$trace[0]['line'], E_USER_NOTICE);

		return null;
	}

	public function jsonSerialize()
	{
		return $this->getInfo();
	}

	public function __toString()
	{
		return $this->getInfo();
	}

	protected function getInfo()
	{
		$id = $this->hasAttribute('id') ? '#'.$this->getAttribute('id') : '';
		$class = $this->hasAttribute('class') ? '.'.$this->getAttribute('class') : '';
		$src = $this->hasAttribute('src') ? '@src='.$this->getAttribute('src') : '';

		return $this->tagName.$id.$class.$src;
	}
}
