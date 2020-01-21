<?php
namespace Emmet;

class Emmet
{
	const MAX_FNAME_LEN = 40;

	// minimum cache content length
	const MIN_CACHE_LEN = 60;

	// minimum cache time (ms)
	const MIN_CACHE_TIME = 30.0;

	/**
	 * @var string cache file extension.
	 */
	public $cacheFileExt = '.xml';
	/**
	 * @var integer the level of sub-directories to store cache files.
	 * If the system has huge number of cache files (e.g. one million), you may use a bigger value
	 * (usually no bigger than 3). Using sub-directories is mainly to ensure the file system
	 * is not over burdened with a single directory having too many files.
	 */
	public $dirLevel = 0;
	/**
	 * @var integer the probability (parts per million) that garbage collection (GC) should be performed
	 * when storing a piece of data in the cache. Defaults to 10, meaning 0.001% chance.
	 * This number should be between 0 and 1000000. A value 0 means no GC will be performed at all.
	 */
	public $gcProbability = 10;
	/**
	 * @var integer the permission to be set for newly created cache files.
	 * This value will be used by PHP chmod() function. No umask will be applied.
	 * If not set, the permission will be determined by the current environment.
	 */
	public $fileMode;
	/**
	 * @var integer the permission to be set for newly created directories.
	 * This value will be used by PHP chmod() function. No umask will be applied.
	 * Defaults to 0775, meaning the directory is read-writable by owner and group,
	 * but read-only for other users.
	 */
	public $dirMode = 0775;

	public $gettag;
	public $itags;
	public $tabbr;
	public $aabbr;
	public $eabbr;
	/**
	 * @var bool indented mode
	 */
	public $indented;
	/**
	 * @var string the directory to store cache files, should be end with DIRECTORY_SEPARATOR.
	 * You may use path alias here.
	 */
	public $cache;

	/**
	 * @param array $tabbr tag abbreviation table
	 * @param array $aabbr attribute abbreviation table
	 * @param array $itags implict tag names
	 * @param array $eabbr extended attribute abbreviation table
	 * @param bool $indented indented
	 * @param string $cache cache directory
	 * @throws \Exception
	 */
	public function __construct($tabbr = [], $aabbr = [], $itags = [], $eabbr = [], $indented = true, $cache = null)
	{
		if ($cache && !is_writable($cache)) {
			throw new \Exception(sprintf('Cache directory "%s" is not writable.', $this->cache));
		}
		$this->tabbr = $tabbr;
		$this->aabbr = $aabbr;
		$this->itags = $itags;
		$this->eabbr = $eabbr;
		$this->indented = $indented;
		$this->cache = $cache;
	}

	/**
	 * @param string $input emmet code
	 * @param callable $gettag
	 * @return string xml code
	 */
	function __invoke($input, $gettag = null)
	{
		if ($this->indented)
			$input = $this->extractTabs($input);
		if (!is_callable($gettag))
			$gettag = [$this, 'getFullTag'];
		$input = preg_replace('/<!--[\S\s]*?-->/', '', $input);
		if (strlen($input) >= Emmet::MIN_CACHE_LEN)
		{
			$buffer = $this->getCache($input);
			if ($buffer)
				return $buffer;
			$st = microtime(true);
		}
		$s = [];
		$buffer = '';
		$l = 0;
		$taglist = [];
		$grouplist = [];
		$lastgroup = [];
		$result = [];

		$closeTag = function($ret = FALSE) use (&$taglist, &$result) {
			$tag = array_pop($taglist);
			if ($tag && !preg_match('/!|(area|base|br|col|embed|frame|hr|img|input|link|meta|param|source|wbr)\b/i', $tag)) {
				$tag = '</' . $tag . '>';
				return $ret ? $tag : array_push($result, $tag);
			}
			return '';
		};

		$len = strlen($input);
		for ($i = 0; $i < $len; $i++) {
			$c = $input[$i];
			switch ($c) {
				case '{' :
					if ($l >= 0)
						$l++;
					break;
				case '[':
					if ($l < 1)
						$l--;
					break;

				case '+':
				case '>':
				case '^':
				case '(':
				case ')':
					if (!$l) {
						if ($buffer) {
							array_push($s, $buffer);
							$buffer = '';
						}
						array_push($s, $c);
						$c = '';
					}
					break;

				case '*':
					if ($buffer && !$l) {
						array_push($s, $buffer);
						$buffer = '';
					}
					break;

				case "}":
					if ($l > 0) $l--;
					if (!$l) {
						array_push($s, $buffer . $c);
						$c = $buffer = '';
					}
					break;

				case "]":
					if ($l < 0) $l++;
					break;
			}
			if (!$l && $c != '*' && $i && $input[$i - 1] == '}')
				array_push($s, '+');
			$buffer .= $c;
		}
		if ($buffer) array_push($s, $buffer);
		for ($i = 0, $len = count($s); $i < $len; $i++) {
			$set = $s[$i];
			$content = '';
			$cls = [];
			$attr = [''];
			$n = null;
			switch ($set) {
				case '^':
					$lasttag = end($result);
					if ($lasttag && substr($lasttag, 0, 2) != '</') $closeTag();
					$closeTag();
				case '>':
					break;

				case '+':
					$lasttag = end($result);
					if ($lasttag && substr($lasttag, 0, 2) != '</') $closeTag();
					break;

				case '(':
					array_push($grouplist, [count($result), count($taglist)]);
					break;

				case ')':
					$prevg = end($grouplist);
					$l = $prevg[1];
					for ($g = count($taglist); $g > $l; $g--) $closeTag();
					$lastgroup = array_slice($result, $prevg[0]);
					break;

				default:
					if ($set[0] == '*') {
						$times = intval(substr($set, 1));
						$repeatTag = [];
						if (!empty($lastgroup)) {
							$repeatTag = $lastgroup;
							array_splice($result, array_pop($grouplist)[0]);
						} elseif (!empty($result))
							array_push($repeatTag, array_pop($result), $closeTag(true));
						for ($n = 0; $n < $times; $n++) {
							$l = count($repeatTag);
							for ($r = 0; $r < $l; $r++) {
								array_push($result, preg_replace_callback('/(\$+)(?:@(-?)(\d*))?/', function ($match) use (&$n, &$times) {
									$r = $match[2] == '-';
									$v = intval($match[3]);
									$v = ($r ? -$n : $n) + ($v ? $v : ($r ? $times - 1 : 0)) . '';
									return str_pad($v, strlen($match[1]) - strlen($v), '0', STR_PAD_LEFT);
								},
								$repeatTag[$r]));
							}
						}
					} else {
						$tag = '';
						$n = 0;
						$lastgroup = [];
						preg_match_all('/(\{.+\})|(\[.+\])|([\.#]?[\w:=!\$\@\-]+)/', $set, $pattern);
						$pattern = $pattern[0];
						for ($l = count($pattern); $n < $l; $n++) {
							$buffer = $pattern[$n];
							switch ($buffer[0]) {
								case '.':
									array_push($cls, substr($buffer, 1));
									break;

								case '#':
									array_push($attr, 'id="' . substr($buffer, 1) . '"');
									break;

								case '[':
									/*$a = substr($pattern[$n], 1, -1);
									$s = explode('=', $a);
									array_push($attr, isset($s[1]) ? $s[0] . '="' . preg_replace('/\"/', '\\"', str_replace('\\', '\\\\', trim($s[1], '"'))) . '"' : $a);*/
									array_push($attr, preg_replace_callback('/([^=\s]+)=([^"\'\s]*)(\s|$)/', function ($match) use (&$buffer) {
											list($m, $a, $b, $c) = $match;
											return ($this->aabbr[$a] ?: $a) . (strpos($b, '"') !== false ? "='" . $b . "'" : '="'. $b . '"') . $c;
										}, substr($buffer, 1, -1)));
									break;

								case '{':
									$content = substr($buffer, 1, -1);
									break;

								default:
									$tag = $buffer;
									break;
							}
						}
						if (!empty($cls))
							$attr[0] = ' class="' . join(' ', $cls) . '"';
						if (!$content || $tag || isset($attr[1]) || $attr[0]) {
							$gettag($tag, $taglist, $result);
							array_push($result, '<' . $tag . join(' ', $attr) . '>' . $content);
						} else
							array_push($result, $content);
					}
			}
		}
		for ($i = count($taglist); $i-- ;) $closeTag();
		$buffer = join('', $result);
		if ($this->cache && microtime(true) - $st > Emmet::MIN_CACHE_TIME) {
			$this->setCache($input, $buffer);
		}
		return $buffer;
	}

	// get full tag name from abbreviation
	function getFullTag(&$tag, &$list, $result) {
		$s = explode(":", $tag);
		if ($tag = $s[0]) {
			for ($t = strtolower($tag); $this->tabbr[$t] && ($t = str_replace($t, $this->tabbr[$t], $t)) != $tag;) $tag = $t;
		}
		if (!$tag) {
			if (($t = end($list)) && isset($this->itags[$t]))
				$tag = $this->itags[$t];
			else
				$tag = (($t = end($result)) && ($t = strtolower(preg_replace('/[\s>][\S\s]*/', '', substr($t, 1), 1))) && isset($this->itags[$t])) ?
					$this->itags[$t] : "div";
		}
		array_push($list, $tag);
		$tag = $tag . $this->eabbr[$s[1]];
	}

	// two fns for counting single line nest tokens (.a>.b^.c)
	protected function countTokens($s, $c) {
		// dont count >^ in quotes or curlys ex: div{>>> my h^t^m^l >>>}
		return substr_count(preg_replace(['/[^\\\\]?".+?[^\\\\]"/', '/[^\\\\]?\'.+?[^\\\\]\'/', '/[^\\\\]?\{.+?[^\\\\]\}/'], '', $s), $c);
	}

	// # of actual tabs and spaces at the start of the line
	function getTabLevel($s, $twoSpaceTabs) {
		$s = str_replace($twoSpaceTabs ? '    ' : '  ', "\t", $s);
		return strlen($s) - strlen(trim($s ,"\t"));
	}

	// make `^>+` out of tabs (normally emmet does nesting like ".a>.b" and unnesting like ".b^.a_sibling", now we can use tabs)
	function extractTabs($s) {
		$e = explode("\n", $s);
		$r = -1;
		foreach ($e as &$t) {
			$o = $this->getTabLevel($t, isset($e[2]) && $e[2]==' ');
			$i = $t;
			$t = trim($t);
			if (strlen($t)) {
				if($r >= 0)
					$t = ($o > $r ? '>' :
					($o == $r ? '+' :
					str_repeat('^', $r - $o))) . $t;
				$r = $o +  $this->countTokens($i, '>') -  $this->countTokens($i, '^');
			}
		}
		return join($e);
	}

	/**
	 * Returns the cache file path given the cache key.
	 * @param string $key cache key
	 * @return string result, or null if cache path is incorrect for caching or cache doesn't exist
	 */
	protected function getCacheFile($key)
	{
		if (null === $this->cache || !is_dir($this->cache) || !is_readable($this->cache))
			return null; // Incorrect cache path for caching.

		$len = strlen($key);
		$key = $this->cache . base64_encode(hash('fnv1a32', $key, true)) . $this->cacheFileExt;

		if ($this->dirLevel > 0) {
			$base = $this->cache;
			for ($i = 0; $i < $this->dirLevel; ++$i)
				if (($prefix = substr($key, $i + $i, 2)) !== false)
					$base .= DIRECTORY_SEPARATOR . $prefix;
			return $base . DIRECTORY_SEPARATOR . $key . $this->cacheFileExt;
		}
		return $this->cache . DIRECTORY_SEPARATOR . $key . $this->cacheFileExt;
	}

	/**
	 * Retrieves a value from cache with a specified key.
	 * @param mixed $key a key identifying the cached value. This can be a simple string or
	 * a complex data structure consisting of factors representing the key.
	 * @return string the value stored in cache, false if the value is not in the cache, expired,
	 * or the dependency associated with the cached data has changed.
	 */
	function getCache($key)
	{
		$file = $this->getCacheFile($key);
		if (!$file || !is_readable($file))
			return false;

		if (filemtime($file) > time() && ($fp = fopen($file, 'r')) !== false) {
				flock($fp, LOCK_SH);
				$result = stream_get_contents($fp);
				flock($fp, LOCK_UN);
				fclose($fp);
				return $result;
			}
		return false;
	}

	/**
	 * Stores a value identified by a key in cache.
	 * @param string $input file path
	 * @param string $key the key identifying the value to be cached
	 * @param string $val the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean true if the value is successfully stored into cache, false otherwise
	 */
	function setCache($key, $val)
	{
		$this->gc();
		$file = $this->getCacheFile($key);
		if (!$file)
			return false;
		if ($this->dirLevel > 0)
			mkdir(dirname($file), $this->dirMode, true);
		if (file_put_contents($file, $val, LOCK_EX) !== false) {
			if ($this->fileMode !== null)
				chmod($file, $this->fileMode);
			if ($duration <= 0)
				$duration = 31536000; // 1 year

			return touch($file, $duration + time());
		}
		return false;
	}

	/**
	 * Deletes a value with the specified key from cache
	 * @param mixed $key a key identifying the value to be deleted from cache. This can be a simple string or
	 * a complex data structure consisting of factors representing the key.
	 * @return boolean if no error happens during deletion
	 */
	function deleteCache($key)
	{
		return unlink($this->getCacheFile($key));
	}


	/**
	 * Removes expired cache files.
	 * @param boolean $force whether to enforce the garbage collection regardless of [[gcProbability]].
	 * Defaults to false, meaning the actual deletion happens with the probability as specified by [[gcProbability]].
	 * @param boolean $expiredOnly whether to removed expired cache files only.
	 * If false, all cache files under [[cache]] will be removed.
	 */
	function gc($force = false, $expiredOnly = true)
	{
		if ($force || mt_rand(0, 1000000) < $this->gcProbability)
			$this->gcRecursive($this->cache, $expiredOnly);
	}

	/**
	 * Recursively removing expired cache files under a directory.
	 * This method is mainly used by [[gc()]].
	 * @param string $path the directory under which expired cache files are removed.
	 * @param boolean $expiredOnly whether to only remove expired cache files. If false, all files
	 * under `$path` will be removed.
	 * @return boolean if no error happens during deletion
	 */
	protected function gcRecursive($path, $expiredOnly)
	{
		if (($handle = opendir($path)) === false)
			return false;
		$result = true;
		while (($file = readdir($handle)) !== false) {
			if ($file[0] === '.')
				continue;
			$fullPath = $path . DIRECTORY_SEPARATOR . $file;
			if (is_dir($fullPath)) {
				$this->gcRecursive($fullPath, $expiredOnly);
				if (!$expiredOnly)
					if (!rmdir($fullPath)) $result = false;
			} elseif (!$expiredOnly || $expiredOnly && filemtime($fullPath) < time())
				if (!unlink($fullPath)) $result = false;
		}
		closedir($handle);
		return $result;
	}
}