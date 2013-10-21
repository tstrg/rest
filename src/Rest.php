<?php
/**
 * Rest (https://github.com/positivezero/rest)
 *
 * Copyright (c) 2013 PositiveZero ltd. (http://www.positivezero.co.uk)
 *
 * For the full copyright and license information, please view
 * the file license.md that was distributed with this source code.
 *
 * Usage: readme.md
 */

namespace Positivezero;

use Positivezero\Rest\Exception;
use Positivezero\Rest\InvalidArgumentException;

class Rest
{
	private $debug = false;
	private $method;
	private $url;
	private $headers;

	public function __construct($method, $url, $headers = array())
	{
		$this->method = $method;
		$this->url = $url;
		if (!is_array($headers)) throw new InvalidArgumentException('Headers must be array of key => value pairs');
		$this->headers = $headers;
	}

	public function get($parameter = null)
	{
		return $this->send('get', $parameter);
	}


	public function post($parameter, $body)
	{
		return $this->send('post', $parameter, $body);
	}

	public function put($parameter, $body)
	{
		return $this->send('put', $parameter, $body);
	}

	private function send($type, $parameter = null, $body = null, $headers = array())
	{
		// Sanitize $parameter and build endpoint query
		$query = '';
		if (!is_null($parameter)) {
			if (is_string($parameter) || is_numeric($parameter)) {
				$query = ltrim($parameter, '/');
			} elseif (is_array($parameter)) {
				$query = ltrim(implode('/', $parameter));
			} else {
				throw new InvalidArgumentException('Passed argument Parameter must be string, numeric or array! ' .
					gettype($parameter) . ' given.');
			}
			if ($query) $query = '/' . $query;
		}

		// Build headers for curl
		if (!is_array($headers)) {
			throw new InvalidArgumentException('Headers must be array of key => value pairs! ' . gettype($headers) .
				' given.');
		}
		$tmp = array();
		if (!empty($this->headers) || !empty($headers)) {
			$mergedHeaders = array_merge($this->headers, $headers);
			foreach ($mergedHeaders as $key => $value) {
				array_push($tmp, $key . ': ' . $value);
			}
		}
		$sendHeaders = $tmp;

		if ($body) {
			if (gettype($body) == 'object') $body = (string) $body;
			if (!is_array($body) && !is_string($body)) throw new InvalidArgumentException('Body must be string or
				array of key => value pairs! ' . gettype($body) . ' given!');
		}

		// Create final URL and process curl
		$url = $this->url . '/' . $this->method . $query;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$this->debug('CURLOPT_URL', $url);
		$this->debug('CURLOPT_RETURNTRANSFER', true);

		if (!empty($sendHeaders)) {
			$this->debug('CURLOPT_POSTFIELDS',print_r($sendHeaders,true));
			curl_setopt($ch, CURLOPT_HTTPHEADER, $sendHeaders);
		}

		if ($type == 'put') {
			$this->debug('CURLOPT_CUSTOMREQUEST', 'PUT');
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
		}

		if ($type == 'put' || $type == 'post') {
			$this->debug('CURLOPT_POST',true);
			$this->debug('CURLOPT_POSTFIELDS',print_r($body,true));
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
		}

		$result = curl_exec($ch);
		if ($result === false) {
			throw new Exception(curl_error($ch), curl_errno($ch));
		}

		curl_close($ch);
		$this->debug('RESPONSE',$result);
		return json_decode($result);
	}

	private function debug($name, $value)
	{
		if ($this->debug) {
			echo str_pad($name, 10,' ', STR_PAD_RIGHT) . ': ' . $value . PHP_EOL;
		}
	}

}
