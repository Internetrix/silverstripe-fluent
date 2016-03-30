<?php
/**
 * have to move fluent related extension to bottom of ext list to make it work for Versioned extension.
 *
 * TODO find a better way to define extensions order....
 *
 * e.g. SiteTree extension order need to be like that. 'Versioned' should be above 'FluentSiteTree' or 'ExtraTable_FluentExtension'
 *
	 1 => string 'Hierarchy'
	 2 => string 'Versioned('Stage', 'Live')'
	 3 => string 'SiteTreeLinkTracking'
	 4 => string 'ExtraTable_FluentSiteTree'
 
 
 	replicate the following setting in your mysite/_config.php if you add ExtraTable_FluentExtension for Versioned DataObject like SiteTree.
 	
	Don't worry about sub classes of SiteTree or Versioned DataObject. 
 *
 */
$data = Config::inst()->get('SiteTree', 'extensions');

foreach ($data as $number => $extName){
	if($extName == 'FluentSiteTree'){
		unset($data[$number]);
	}
}

$data[] = 'FluentSiteTree';

Config::inst()->remove('SiteTree', 'extensions');
Config::inst()->update('SiteTree', 'extensions', $data);
