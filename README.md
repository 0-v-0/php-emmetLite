# php-emmetLite
A PHP version of emmetLite. https://github.com/0-v-0/emmetLite

# Installation

# Requirements
> PHP7

# Interface
```PHP
Emmet::__construct(array $tabbr = [], array $aabbr = [], array $itags = [], array $eabbr = [], bool $indented = true, string $cache = null);
Emmet::__invoke(string $input, callable $gettag = null)
Emmet::extractTabs(string s);
Emmet::getTabLevel(string s, bool twoSpaceTabs);
Emmet::getCache(string s);
Emmet::setCache(string key, string val);
```

# Usage

```PHP
<?php
require_once 'config.inc.php';

function callback($s) {
	global $emmet;
	return $emmet($s);
}
ob_start('callback');
?>
your emmet code here...
<?php
ob_end_flush(); // optional
?>
```
OR
```PHP
<?php ob_start(); ?>
your emmet code here...
<?php
echo (new Emmet())(ob_get_clean());
?>
```
(for Debug)
