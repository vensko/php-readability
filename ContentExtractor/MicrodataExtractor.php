<?php
/**
 * Author: Dzmitry Vensko
 * Date: 11.04.2015 2:19
 */
namespace Readability\ContentExtractor;

use DOMDocument, DOMElement, DOMNode;
use Readability\DOMHelpers;

class MicrodataExtractor implements ContentExtractorInterface
{
	use DOMHelpers;

	protected $root;

	protected $acceptableItempropSiblings = [
		'articleBody', 'alternativeHeadline', 'description',
		'associatedMedia', 'image', 'representativeOfPage', 'author',
	];

	/**
	 * @param $root
	 * @return bool|DOMElement|DOMNode
	 */
	public function parse($root)
	{
		$this->root = $root;

		if ($gallery = $this->selectByAttribute('itemtype', 'http://schema.org/ImageGallery', $root) AND $gallery->length === 1) {
			$this->dbg('Found gallery with itemtype http://schema.org/ImageGallery.');

			return $this->wrapNodeList($gallery);
		}

		if ($article = $this->findArticleBody($root)) {
			return $article;
		}

		return false;
	}

	/**
	 * @return DOMElement|null
	 */
	public function getRoot()
	{
		$result = null;

		if ($nodeList = $this->selectByAttribute('itemprop', 'mainContentOfPage', $this->root) AND $nodeList->length === 1) {
			$result = $nodeList->item(0);
			$this->dbg('Found content base @itemprop=mainContentOfPage.');
		} else if ($nodeList = $this->selectByAttribute('role', 'main', $this->root) AND $nodeList->length === 1) {
			$result = $nodeList->item(0);
			$this->dbg('Found content base @role=main.');
		}

		return $result;
	}

	protected function findArticleBody(DOMNode $node)
	{
		$nodes = [];

		foreach (['articleBody'] as $property) {
			$nodeList = $this->selectByAttribute('itemprop', $property, $node);
			if ($nodeList->length) {
				foreach ($nodeList as $item) {
					$nodes[] = $item;
				}
				$this->dbg('Found '.$nodeList->length.' elements with @itemprop='.$property.'.');
				break;
			}
		}

		if (!$nodes) {
			return null;
		}

		$results = [];
		foreach ($nodes as $node) {
			$supplementalEntities = $this->selectByAttribute('itemprop', $this->acceptableItempropSiblings, $node->parentNode);

			if ($supplementalEntities->length > 1) {
				$this->dbg('Found @itemprop='.$property.' with complemental attributes.');

				$results[] = $this->wrapNodeList($supplementalEntities);
			} else {
				$this->dbg('Found @itemprop='.$property.'.');

				$results[] = $node;
			}
		}

		return $this->wrapNodeList($results);
	}
}
