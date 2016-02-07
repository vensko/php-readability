<?php
/**
 * Author: Dzmitry Vensko
 * Date: 08.04.2015 14:56
 */

class AddFootnotes
{
	protected $skipFootNoteLink = '/^\s*(\[?[a-z0-9]{1,2}\]?|^|edit|citation needed)\s*$/i';

	/**
	 * For easier reading, convert this document to have footnotes at the bottom rather than inline links.
	 * @see http://www.roughtype.com/archives/2010/05/experiments_in.php
	 *
	 * @return void
	 **/
	public function addFootnotes(Readability $readability, $articleContent)
	{
		if (preg_match('/wikipedia\.org/', $readability->url)) {
			return;
		}

		$footnotesWrapper = $this->dom->createElement('div');
		$footnotesWrapper->setAttribute('id', 'readability-footnotes');
		$footnotesWrapper->innerHTML = '<h3>References</h3>';

		$articleFootnotes = $this->dom->createElement('ol');
		$articleFootnotes->setAttribute('id', 'readability-footnotes-list');
		$footnotesWrapper->appendChild($articleFootnotes);

		$articleLinks = $articleContent->getElementsByTagName('a');

		$linkCount = 0;
		for ($i = 0; $i < $articleLinks->length; $i++) {
			$articleLink = $articleLinks->item($i);
			$footnoteLink = $articleLink->cloneNode(true);
			$refLink = $this->dom->createElement('a');
			$footnote = $this->dom->createElement('li');
			$linkDomain = @parse_url($footnoteLink->getAttribute('href'), PHP_URL_HOST);
			if (!$linkDomain && isset($this->url)) $linkDomain = @parse_url($this->url, PHP_URL_HOST);
			//linkDomain   = footnoteLink.host ? footnoteLink.host : document.location.host,
			$linkText = $this->getInnerText($articleLink);

			if ((strpos($articleLink->getAttribute('class'), 'readability-DoNotFootnote') !== false) || preg_match($this->regexps['skipFootnoteLink'], $linkText)) {
				continue;
			}

			$linkCount++;

			/** Add a superscript reference after the article link */
			$refLink->setAttribute('href', '#readabilityFootnoteLink-'.$linkCount);
			$refLink->innerHTML = '<small><sup>['.$linkCount.']</sup></small>';
			$refLink->setAttribute('class', 'readability-DoNotFootnote');
			$refLink->setAttribute('style', 'color: inherit;');

			//TODO: does this work or should we use DOMNode.isSameNode()?
			if ($articleLink->parentNode->lastChild == $articleLink) {
				$articleLink->parentNode->appendChild($refLink);
			} else {
				$articleLink->parentNode->insertBefore($refLink, $articleLink->nextSibling);
			}

			$articleLink->setAttribute('style', 'color: inherit; text-decoration: none;');
			$articleLink->setAttribute('name', 'readabilityLink-'.$linkCount);

			$footnote->innerHTML = '<small><sup><a href="#readabilityLink-'.$linkCount.'" title="Jump to Link in Article">^</a></sup></small> ';

			$footnoteLink->innerHTML = ($footnoteLink->getAttribute('title') != '' ? $footnoteLink->getAttribute('title') : $linkText);
			$footnoteLink->setAttribute('name', 'readabilityFootnoteLink-'.$linkCount);

			$footnote->appendChild($footnoteLink);
			if ($linkDomain) $footnote->innerHTML = $footnote->innerHTML.'<small> ('.$linkDomain.')</small>';

			$articleFootnotes->appendChild($footnote);
		}

		if ($linkCount > 0) {
			$articleContent->appendChild($footnotesWrapper);
		}
	}
}
