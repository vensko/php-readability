<?php
/**
 * Author: Dzmitry Vensko
 * Date: 11.04.2015 4:05
 */
namespace Readability\ContentExtractor;

use DOMDocument, DOMElement, DOMNode;
use Readability\DOMHelpers;

class SemanticHtmlExtractor implements ContentExtractorInterface
{
	use DOMHelpers;

	protected $root;

	public function parse($root)
	{
		$this->root = $root;
	}

	public function getRoot()
	{
		$result = null;

		if ($nodeList = $this->root->getElementsByTagName('main') AND $nodeList->length === 1) {
			$result = $nodeList->item(0);
			$this->dbg('Found content base &lt;main&gt;.');
		}

		if ($nodeList = $this->root->getElementsByTagName('article')) {
			if ($nodeList->length === 1) {
				$this->dbg('Found an &ltarticle&gt; tag, using it.');
				$result = $nodeList->item(0);
			} else if ($nodeList->length > 1) {
				$this->dbg('Found more than one &ltarticle&gt; tag, skipping.');
			}
		}

		return $result;
	}
}
