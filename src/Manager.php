<?php
/**
 * @package    Fuel\Security
 * @version    2.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010 - 2015 Fuel Development Team
 * @link       http://fuelphp.com
 */

namespace Fuel\Security;

/**
 * Security Manager class
 *
 * Container for various Security handlers.
 */
class Manager
{
	/**
	 * @var array
	 */
	protected $config = [];

	/**
	 * @var Csrf
	 */
	protected $csrf;

	/**
	 * @var array
	 */
	protected $filters = [];

	/**
	 * @var array
	 */
	protected $cleaned = [];

	/**
	 * @param array $config
	 */
	public function __construct(array $config = [])
	{
		// store the config passed
		$this->config = $config;

		// make sure required config keys exist
		if ( ! isset($this->config['uri_filter']))
		{
			$this->config['uri_filter'] = [];
		}
		if ( ! isset($this->config['input_filter']))
		{
			$this->config['input_filter'] = [];
		}
		if ( ! isset($this->config['output_filter']))
		{
			$this->config['output_filter'] = [];
		}
	}

	/**
	 * Returns a Csrf instance
	 *
	 * @return Csrf
	 */
	public function csrf()
	{
		if ( ! $this->csrf)
		{
			$this->csrf = \Dependency::resolve('security.csrf', array($this->config, \Application::getInstance()->getSession()));
		}

		return $this->csrf;
	}

	/**
	 * Cleans the request URI
	 *
	 * @param string  $uri
	 * @param boolean $strict
	 */
	public function cleanUri($uri, $strict = false)
	{
		$filters = $this->config['uri_filter'];
		$filters = is_array($filters) ? $filters : array($filters);

		if ($strict)
		{
			$uri = preg_replace(array("/\.+\//", '/\/+/'), '/', $uri);
		}

		return $this->clean($uri, $filters);
	}

	/**
	 * Generic variable clean method
	 *
	 * @param mixed  $var
	 * @param mixed  $filters
	 * @param string $type
	 *
	 */
	public function clean($var, $filters = null, $type = 'input_filter')
	{
		// if no filters are given, load the defaults from config
		if ($filters === null)
		{
			$filters = isset($this->config[$type]) ? $this->config[$type] : [];
		}

		// and make sure it's an array
		$filters = is_array($filters) ? $filters : [$filters];

		foreach ($filters as $filter)
		{
			// do we have this filter loaded? or can we load it?
			if (array_key_exists(strtolower($filter), $this->filters) or $this->loadFilter($filter))
			{
				$filter = $this->filters[strtolower($filter)];
			}

			// does the filter have a callable clean() method?
			if (is_callable(array($filter, 'clean')))
			{
				$var = $filter->clean($var);
			}

			// is the filter callable in itself?
			elseif (is_callable($filter))
			{
				$var = $filter($var);
			}

			// assume it's a regex of characters to filter
			else
			{
				$var = $this->filterRegex($var, $filter);
			}
		}

		return $var;
	}

	/**
	 * @param mixed $input
	 *
	 * @return boolean
	 */
	public function isCleaned($input)
	{
		return in_array($input, $this->cleaned, true);
	}

	/**
	 * @param mixed $input
	 */
	public function isClean($input)
	{
		$this->cleaned[] = $input;
	}

	/**
	 * @param mixed $input
	 *
	 * @return mixed
	 */
	public function stripTags($value)
	{
		if ( ! is_array($value))
		{
			$value = filter_var($value, FILTER_SANITIZE_STRING);
		}
		else
		{
			foreach ($value as $k => $v)
			{
				$value[$k] = $this->stripTags($v);
			}
		}

		return $value;
	}

	/**
	 * @param mixed $input
	 *
	 * @return mixed
	 */
	public function xssClean($value)
	{
		if ( ! is_array($value))
		{
			if ( ! function_exists('htmLawed'))
			{
				if ( ! file_exists($file = VENDORPATH.'htmlawed'.DS.'htmlawed'.DS.'htmLawed.php'))
				{
					throw new \RuntimeException('You need to install the "htmlawed/htmlawed" composer package to use Security::xss_clean()');
				}
				require_once $file;
			}

			return htmLawed($value, array('safe' => 1, 'balanced' => 0));
		}

		foreach ($value as $k => $v)
		{
			$value[$k] = $this->xss_clean($v);
		}

		return $value;
	}

	/**
	 * @param string $filter
	 *
	 * @return boolean
	 */
	protected function loadFilter($filter)
	{
		static $misses = [];

		if ( ! in_array($filter, $misses))
		{
			try
			{
				if ($obj = \Dependency::resolve('security.filter.'.strtolower($filter), array($this)))
				{
					$this->filters[strtolower($filter)] = $obj;

					return true;
				}
			}
			catch (\Fuel\Dependency\ResolveException $e)
			{
				// we don't have a class for this filter
				$misses[] = $filter;
			}
		}

		return false;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public function getConfig($key, $default)
	{
		return isset($this->config[$key]) ? $this->config[$key] : $default;
	}

	/**
	 * @param mixed  $var
	 * @param string $filter
	 *
	 * @return mixed
	 */
	protected function filterRegex($var, $filter)
	{
		if (is_array($var))
		{
			foreach($var as $key => $value)
			{
				$var[$key] = preg_replace('#['.$filter.']#ui', '', $value);
			}
		}
		else
		{
			$var = preg_replace('#['.$filter.']#ui', '', $var);
		}

		return $var;
	}
}
