<?php
/**
 * xCSS class
 *
 * @author     Anton Pawlik
 * @version    0.9.5
 * @see        http://xcss.antpaw.org/docs/
 * @copyright  (c) 2009 Anton Pawlik
 * @license    http://xcss.antpaw.org/about/
 */

class xCSS
{
	// config vars
	private $path_css_dir;
	private $master_file;
	private $xcss_files;
	private $reset_files;
	private $hook_files;
	private $css_files;
	private $construct;
	private $compress_output_to_master;
	private $minify_output;
	private $master_content;
	private $debugmode;

	// hole content of the xCSS file
	private $filecont;

	// an array of keys(selectors) and values(propertys)
	private $parts;

	// nodes that will be extended some level later
	private $levelparts;

	// final css nodes as an array
	private $css;

	// vars declared in xCSS files
	private $xcss_vars;

	// output string for each CSS file
	private $final_file;

	// relevant to debugging
	private $debug;

	public function __construct(array $cfg)
	{
		header('Content-type: application/javascript; charset=utf-8');

		if(isset($cfg['disable_xCSS']) && $cfg['disable_xCSS'] === TRUE)
		{
			die("alert(\"xCSS Warning: xCSS was disabled via 'config.php'! Remove the xCSS <script> tag from your HMTL <head> tag.\");");
		}

		$this->levelparts = array();
		$this->path_css_dir = isset($cfg['path_to_css_dir']) ? $cfg['path_to_css_dir'] : '../';

		if(isset($cfg['xCSS_files']))
		{
			$this->xcss_files = array();
			$this->css_files = array();
			foreach($cfg['xCSS_files'] as $xcss_files => $css_file)
			{
				array_push($this->xcss_files, $xcss_files);
				// get rid of the media properties
				$file = explode(':', $css_file);
				array_push($this->css_files, trim($file[0]));
			}
		}
		else
		{
			$this->xcss_files = array('xcss.xcss');
			$this->css_files = array('xcss_generated.css');
		}

		// CSS master file
		$this->compress_output_to_master = (isset($cfg['compress_output_to_master']) && $cfg['compress_output_to_master'] === TRUE);

		if(isset($cfg['use_master_file']) && $cfg['use_master_file'] === TRUE)
		{
			$this->master_file = isset($cfg['master_filename']) ? $cfg['master_filename'] : 'master.css';
			$this->reset_files = isset($cfg['reset_files']) ? $cfg['reset_files'] : NULL;
			$this->hook_files = isset($cfg['hook_files']) ? $cfg['hook_files'] : NULL;

			if( ! $this->compress_output_to_master)
			{
				$xcssf = isset($cfg['xCSS_files']) ? $cfg['xCSS_files'] : NULL;

				$this->creat_master_file($this->reset_files, $xcssf, $this->hook_files);
			}
		}

		$this->construct = isset($cfg['construct_name']) ? $cfg['construct_name'] : 'self';

		$this->minify_output = isset($cfg['minify_output']) ? $cfg['minify_output'] : FALSE;

		$this->debugmode = isset($cfg['debugmode']) ? $cfg['debugmode'] : FALSE;

		if($this->debugmode)
		{
			$this->debug['xcss_time_start'] = $this->microtime_float();
			$this->debug['xcss_output'] = NULL;
		}

		// this is needed to be able to extend selectors across mulitple xCSS files
		$this->xcss_files = array_reverse($this->xcss_files);
		$this->css_files = array_reverse($this->css_files);

		$this->xcss_vars = array(
			// unsafe chars will be hidden as vars
			'$__doubleslash'			=> '//',
			'$__bigcopen'				=> '/*',
			'$__bigcclose'				=> '*/',
			'$__doubledot'				=> ':',
			'$__semicolon'				=> ';',
			'$__curlybracketopen'		=> '{',
			'$__curlybracketclosed'		=> '}',
		);
	}

	private function creat_master_file(array $reset = array(), array $main = array(), array $hook = array())
	{
		$all_files = array_merge($reset, $main, $hook);

		$master_file_content = NULL;
		foreach($all_files as $file)
		{
			$file = explode(':', $file);
			$props = isset($file[1]) ? ' '.trim($file[1]) : NULL;
			$master_file_content .= '@import url("'.trim($file[0]).'")'.$props.';'."\n";
		}

		$this->creat_file($master_file_content, $this->master_file);
	}

	public function compile()
	{
		$for_c = count($this->xcss_files);
		for($i = 0; $i < $for_c; $i++)
		{
			$this->parts = NULL;
			$this->filecont = NULL;
			$this->css = NULL;

			$filename = $this->path_css_dir.$this->xcss_files[$i];
			$this->filecont = $this->read_file($filename);

			foreach($this->xcss_vars as $var => $unsafe_char)
			{
				$masked_unsafe_char = str_replace(array('*', '/'), array('\*', '\/'), $unsafe_char);
				$patterns[] = '/content(.*:.*(\'|").*)('.$masked_unsafe_char.')(.*(\'|"))/';
				$replacements[] = 'content$1'.$var.'$4';
			}

			$this->filecont = preg_replace($patterns, $replacements, $this->filecont);

			if(strlen($this->filecont) > 1)
			{
				$this->split_content();

				if( ! empty($this->parts))
				{
					$this->parse_level();

					$this->parts = $this->manage_order($this->parts);

					if( ! empty($this->levelparts))
					{
						$this->manage_global_extends();
					}

					$this->final_parse($this->css_files[$i]);
				}
			}
		}

		if( ! empty($this->final_file))
		{
			if($this->compress_output_to_master)
			{
				$master_content = NULL;
				foreach($this->reset_files as $fname)
				{
					$fname = explode(':', $fname);
					$master_content .= $this->read_file($this->path_css_dir.$fname[0])."\n";
				}
				rsort($this->final_file);
				foreach($this->final_file as $fcont)
				{
					$master_content .= $this->use_vars($fcont);
				}
				foreach($this->hook_files as $fname)
				{
					$fname = explode(':', $fname);
					$master_content .= $this->read_file($this->path_css_dir.$fname[0]);
				}
				$this->creat_file($master_content, $this->master_file);
			}
			else
			{
				foreach($this->final_file as $fname => $fcont)
				{
					$this->creat_file($this->use_vars($fcont), $fname)."\n";
				}
			}
		}
	}

	private function read_file($filepath)
	{
		$filecontent = NULL;

		if(file_exists($filepath))
		{
			$filecontent = str_replace('ï»¿', NULL, utf8_encode(file_get_contents($filepath)));
		}
		else
		{
			die("alert(\"xCSS Parse error: Cannot find '".$filepath."'.\");");
		}

		return $filecontent;
	}

	private function split_content()
	{
		// removes multiple line comments
		$this->filecont = preg_replace("/\/\*(.*)?\*\//Usi", NULL, $this->filecont);
		// removes inline comments, but not :// for http://
		$this->filecont .= "\n";
		$this->filecont = preg_replace("/[^:]\/\/.+?\n/", NULL, $this->filecont);

		$this->filecont = $this->change_braces($this->filecont);

		$this->filecont = explode('#c]}', $this->filecont);

		foreach($this->filecont as $i => $part)
		{
			$part = trim($part);
			if( ! empty($part))
			{
				list($keystr, $codestr) = explode('{[o#', $part);
				// adding new line to all (,) in selectors, to be able to find them for 'extends' later
				$keystr = str_replace(',', ",\n", trim($keystr));
				if($keystr == 'vars')
				{
					$this->setup_vars($codestr);
					unset($this->filecont[$i]);
				}
				else if( ! empty($keystr))
				{
					$this->parts[$keystr] = $codestr;
				}
			}
		}
	}

	private function setup_vars($codestr)
	{
		$codes = explode(';', $codestr);
		if( ! empty($codes))
		{
			foreach($codes as $code)
			{
				$code = trim($code);
				if( ! empty($code))
				{
					list($varkey, $varcode) = explode('=', $code);
					$varkey = trim($varkey);
					$varcode = trim($varcode);
					if(strlen($varkey) > 0)
					{
						$this->xcss_vars[$varkey] = $this->use_vars($varcode);
					}
				}
			}
			$this->xcss_vars[': var_rule'] = NULL;
		}
	}

	private function use_vars($cont)
	{
		return strtr($cont, $this->xcss_vars);
	}

	private function parse_level()
	{
		// this will manage xCSS rule: 'extends'
		$this->parse_extends();

		// this will manage xCSS rule: child objects inside of a node
		$this->parse_childs();
	}

	private function manage_global_extends()
	{
		// helps to find all the extenders of the global extended selector

		foreach($this->levelparts as $keystr => $codestr)
		{
			if(strpos($keystr, 'extends') !== FALSE)
			{
				preg_match_all('/((\S|\s)+?) extends ((\S|\n)[^,]+)/', $keystr, $result);

				$child = trim($result[1][0]);
				$parent = trim($result[3][0]);

				foreach($this->parts as $p_keystr => $p_codestr)
				{
					// to be sure we get all the children we need to find the parent selector
					// this must be the one that has no , after his name
					if(strpos($p_keystr, ",\n".$child) !== FALSE && ( ! strpos($p_keystr, $child.',') !== FALSE))
					{
						$p_keys = explode(",\n", $p_keystr);
						foreach($p_keys as $p_key)
						{
							$this->levelparts[$p_key.' extends '.$parent] = NULL;
						}
					}
				}
			}
		}
	}

	private function manageMultipleExtends()
	{
		//	To be able to manage multiple extends, you need to
		//	destroy the actual node and creat many nodes that have
		//	mono extend. the first one gets all the css rules
		foreach($this->parts as $keystr => $codestr)
		{
			if(strpos($keystr, 'extends') !== FALSE)
			{
				preg_match_all('/((\S|\s)+?) extends ((\S|\n)[^,]+)/', $keystr, $result);

				$parent = trim($result[3][0]);
				$child = trim($result[1][0]);

				if(strpos($parent, '&') !== FALSE)
				{
					$kill_this = $child.' extends '.$parent;

					$parents = explode(' & ', $parent);
					$with_this_key = $child.' extends '.$parents[0];

					$add_keys = array();
					$for_c = count($parents);
					for($i = 1; $icompress_ < $for_c; $i++)
					{
						array_push($add_keys, $child.' extends '.$parents[$i]);
					}

					$this->parts = $this->add_node_at_order($kill_this, $with_this_key, $codestr, $add_keys);
				}
			}
		}
	}

	private function add_node_at_order($kill_this, $with_this_key, $and_this_value, $additional_key = array())
	{
		foreach($this->parts as $keystr => $codestr)
		{
			if($keystr == $kill_this)
			{
				$temp[$with_this_key] = $and_this_value;

				if( ! empty($additional_key))
				{
					foreach($additional_key as $empty_key)
					{
						$temp[$empty_key] = NULL;
					}
				}
			}
			else
			{
				$temp[$keystr] = $codestr;
			}
		}
		return $temp;
	}

	private function parse_extends()
	{
		// this will manage xCSS rule: 'extends &'
		$this->manageMultipleExtends();

		foreach($this->levelparts as $keystr => $codestr)
		{
			if(strpos($keystr, 'extends') !== FALSE)
			{
				preg_match_all('/((\S|\s)+?) extends ((\S|\n)[^,]+)/', $keystr, $result);

				$parent = trim($result[3][0]);
				$child = trim($result[1][0]);

				// TRUE means that the parent node was in the same file
				if($this->search_for_parent($child, $parent))
				{
					// remove extended rule
					unset($this->levelparts[$keystr]);
				}
			}
		}

		foreach($this->parts as $keystr => $codestr)
		{
			if(strpos($keystr, 'extends') !== FALSE)
			{
				preg_match_all('/((\S|\s)+?) extends ((\S|\n)[^,]+)/', $keystr, $result);
				if(count($result[3]) > 1)
				{
					unset($this->parts[$keystr]);
					$keystr = str_replace(' extends '.$result[3][0], NULL, $keystr);
					$keystr .= ' extends '.$result[3][0];
					$this->parts[$keystr] = $codestr;
					$this->parse_extends();
					break;
				}

				$parent = trim($result[3][0]);
				$child = trim($result[1][0]);
				// TRUE means that the parent node was in the same file
				if($this->search_for_parent($child, $parent))
				{
					// if not empty, creat own node with extended code
					$codestr = trim($codestr);
					if( ! empty($codestr))
					{
						$this->parts[$child] = $codestr;
					}

					unset($this->parts[$keystr]);
				}
				else
				{
					$codestr = trim($codestr);
					if( ! empty($codestr))
					{
						$this->parts[$child] = $codestr;
					}
					unset($this->parts[$keystr]);
					// add this node to levelparts to find it later
					$this->levelparts[$keystr] = $codestr;
				}
			}
		}
	}

	private function search_for_parent($child, $parent)
	{
		$parent_found = FALSE;
		foreach ($this->parts as $keystr => $codestr)
		{
			$sep_keys = explode(",\n", $keystr);
			foreach ($sep_keys as $s_key)
			{
				if($parent == $s_key)
				{
					$this->parts = $this->add_node_at_order($keystr, $child.",\n".$keystr, $codestr);

					// finds all the parent selectors with another bind selectors behind
					foreach ($this->parts as $keystr => $codestr)
					{
						$sep_keys = explode(",\n", $keystr);
						foreach ($sep_keys as $s_key)
						{
							if($parent != $s_key && strpos($s_key, $parent) !== FALSE)
							{
								$childextra = str_replace($parent, $child, $s_key);

								if( ! strpos($childextra, 'extends') !== FALSE)
								{
									// get rid off not extended parent node
									$this->parts = $this->add_node_at_order($keystr, $childextra.",\n".$keystr, $codestr);
								}
							}
						}
					}
					$parent_found = TRUE;
				}
			}
		}
		return $parent_found;
	}

	private function parse_childs()
	{
		$still_childs_left = FALSE;
		foreach($this->parts as $keystr => $codestr)
		{
			if(strpos($codestr, '{') !== FALSE)
			{
				$keystr = trim($keystr);
				unset($this->parts[$keystr]);
				unset($this->levelparts[$keystr]);
				$this->manage_children($keystr, $this->construct."{}\n".$codestr);
				$still_childs_left = TRUE; // maybe
			}
		}
		if($still_childs_left)
		{
			$this->parse_level();
		}
	}

	private function manage_children($keystr, $codestr)
	{
		$codestr = $this->change_braces($codestr);

		$c_parts = explode('#c]}', $codestr);
		foreach ($c_parts as $c_part)
		{
			$c_part = trim($c_part);
			if( ! empty($c_part))
			{
				list($c_keystr, $c_codestr) = explode('{[o#', $c_part);
				$c_keystr = trim($c_keystr);

				if( ! empty($c_keystr))
				{
					$betterKey = NULL;
					$c_keystr = str_replace(',', ",\n".$keystr, $c_keystr);

					$sep_keys = explode(",\n", $keystr);
					foreach ($sep_keys as $s_key)
					{
						$betterKey .= trim($s_key).' '.$c_keystr.",\n";
					}

					if(strpos($betterKey, $this->construct) !== FALSE)
					{
						$betterKey = str_replace(' '.$this->construct, NULL, $betterKey);
					}
					$this->parts[substr($betterKey, 0, -2)] = $c_codestr;
				}
			}
		}
	}

	private function change_braces($str)
	{
		/*
			This function was writen by Gumbo
			http://www.tutorials.de/forum/members/gumbo.html
			Thank you very much!

			finds the very outer braces and changes them to {[o# code #c]}
		*/
		$buffer = NULL;
		$depth = 0;
		$for_c = strlen($str);
		for($i = 0; $i < $for_c; $i++)
		{
			$char = $str[$i];
			switch ($char)
			{
				case '{':
					$depth++;
					$buffer .= ($depth === 1) ? '{[o#' : $char;
					break;
				case '}':
					$depth--;
					$buffer .= ($depth === 0) ? '#c]}' : $char;
					break;
				default:
					$buffer .= $char;
			}
		}
		return $buffer;
	}

	private function manage_order(array $parts)
	{
		/*
			this function brings the CSS nodes in the right order
			because the last value always wins
		*/
		foreach ($parts as $keystr => $codestr)
		{
			// ok let's find out who has the most 'extends' in his key
			// the more the higher this node will go
			$sep_keys = explode(",\n", $keystr);
			$order[$keystr] = count($sep_keys) * -1;
		}
		asort($order);
		foreach ($order as $keystr => $order_nr)
		{
			// with the sorted order we can now redeclare the values
			$sorted[$keystr] = $parts[$keystr];
		}
		// and give it back
		return $sorted;
	}

	private function final_parse($filename)
	{
		foreach($this->parts as $keystr => $codestr)
		{
			$codestr = trim($codestr);
			if( ! empty($codestr))
			{
				if( ! isset($this->css[$keystr]))
				{
					$this->css[$keystr] = array();
				}
				$codes = explode(';', $codestr);
				foreach($codes as $code)
				{
					$code = trim($code);
					if( ! empty($code))
					{
						$codeval = explode(':', $code);
						if(isset($codeval[1]))
						{
							$this->css[$keystr][trim($codeval[0])] = trim($codeval[1]);
						}
						else
						{
							$this->css[$keystr][trim($codeval[0])] = 'var_rule';
						}
					}
				}
			}
		}
		$this->final_file[$filename] = $this->creat_css();
	}

	private function creat_css()
	{
		$result = NULL;
		if(is_array($this->css))
		{
			foreach($this->css as $selector => $properties)
			{
				// feel free to modifie the indentations the way you like it
				$result .= "$selector {\n";
				foreach($properties as $property => $value)
				{
					$result .= "	$property: $value;\n";
				}
				$result .= "}\n";
			}
			$result = preg_replace('/\n+/', "\n", $result);
		}
		return $result;
	}

	private function creat_file($content, $filename)
	{
		if($this->debugmode)
		{
			$this->debug['xcss_output'] .= "/*\nFILENAME:\n".$filename."\nCONTENT:\n".$content."*/\n//------------------------------------\n";
		}

		if($this->minify_output)
		{
			// let's remove big spaces, tabs and newlines
			$content = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '   ', '    '), NULL, $content);
		}

	  // absolute filename
    if (substr($filename, 0, 1) === '/')
    {
      $filepath = $filename;
    }
    else
    {
      $filepath = $this->path_css_dir.$filename;
    }

    if (file_exists($filepath))
    {
  		if( ! is_writable($filepath))
  		{
  			die("alert(\"xCSS Parse error: Cannot write to the output file '".$filepath."'.\");");
  		}
    }
    else
    {
      if( ! is_writable(dirname($filepath)))
      {
        die("alert(\"xCSS Parse error: Cannot write to the output file '".$filepath."'.\");");
      }
    }

		file_put_contents($filepath, pack("CCC",0xef,0xbb,0xbf).utf8_decode($content));
	}

	private function microtime_float()
	{
	    list($usec, $sec) = explode(' ', microtime());
	    return ((float)$usec + (float)$sec);
	}

	public function __destruct()
	{
		if($this->debugmode)
		{
			$time = $this->microtime_float() - $this->debug['xcss_time_start'];
			echo '// Parsed xCSS in: '.round($time, 6).' seconds'."\n//------------------------------------\n".$this->debug['xcss_output'];
		}
	}
}