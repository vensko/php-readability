<?php
/**
 * Author: Dzmitry Vensko
 * Date: 11.04.2015 3:42
 */
namespace Readability\ContentExtractor;

interface ContentExtractorInterface
{
	public function parse($dom);
	public function getRoot();
}
