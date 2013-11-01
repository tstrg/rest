<?php
namespace Positivezero;
use Positivezero\Rest\RestClientException;

/**
 * PHP REST client (https://github.com/positivezero/rest)
 *
 * Copyright (c) 2013 PositiveZero ltd. (http://www.positivezero.co.uk)
 *
 * For the full copyright and license information, please view
 * the file license.md that was distributed with this source code.
 *
 * original package: https://github.com/tcdent/php-restclient
 * (c) 2013 Travis Dent <tcdent@gmail.com>
 *
 * Usage: readme.md
 */

/**
 * Class RestClient
 * @package Positivezero
 *
 * It provides all standard REST call like GET, POST, PUT, DELETE
 * usage:
 * $client = new \Positivezero\RestClient( {options} );
 * $response = $client->put($endpoint, $data);
 */
class RestClient implements \Iterator, \ArrayAccess {
	/** @const DEBUG enable debug to screen */
	const DEBUG = true;
	/** @const VERBOSE if is debug enabled, you can choose verbose level 0=less|1=more */
	const VERBOSE = 0;
	/** @var array  */
	public $options;
	/** @var object cURL resource */
	public $handle;
	/** @var string $response body*/
	public $response;
	/** @var array $headers parsed response header object*/
	public $headers;
	/** @var array $info response object */
	public $info;
	/** @var string $error response error string */
	public $error;
	/** @var mixed $decoded_response */
	public $decoded_response;

	/** @var int */
	private $iterator_positon;

	/**
	 * @param array $options = array(
	 *      'headers' => array(),
	 *      'parameters' => array(),
	 *      'curl_options' => array(),
	 *      'user_agent' => "PHP RestClient/0.1.1",
	 *      'base_url' => NULL,
	 *      'format' => NULL,
	 *      'format_regex' => "/(\w+)\/(\w+)(;[.+])?/",
	 *      'decoders' => array(
	 *          'json' => 'json_decode',
	 *          'php' => 'unserialize'
	 *      ),
	 *      'username' => NULL,
	 *      'password' => NULL
	 *      );
	 */
	public function __construct($options=array()){
		$default_options = array(
			'headers' => array(),
			'parameters' => array(),
			'curl_options' => array(),
			'user_agent' => "PHP RestClient/0.1.1",
			'base_url' => NULL,
			'format' => NULL,
			'format_regex' => "/(\w+)\/(\w+)(;[.+])?/",
			'decoders' => array(
				'json' => 'json_decode',
				'php' => 'unserialize'
			),
			'username' => NULL,
			'password' => NULL
		);

		$this->options = array_merge($default_options, $options);
		if(array_key_exists('decoders', $options))
			$this->options['decoders'] = array_merge(
				$default_options['decoders'], $options['decoders']);
	}

	public function set_option($key, $value){
		$this->options[$key] = $value;
	}

	/**
	 * Decoder callbacks must adhere to the following pattern:
	 * @param $format
	 * @param $method
	 */
	public function register_decoder($format, $method){
		//   array my_decoder(string $data)
		$this->options['decoders'][$format] = $method;
	}

	// Iterable methods:
	public function rewind(){
		$this->decode_response();
		return reset($this->decoded_response);
	}

	public function current(){
		return current($this->decoded_response);
	}

	public function key(){
		return key($this->decoded_response);
	}

	public function next(){
		return next($this->decoded_response);
	}

	public function valid(){
		return is_array($this->decoded_response)
		&& (key($this->decoded_response) !== NULL);
	}

	// ArrayAccess methods:
	public function offsetExists($key){
		$this->decode_response();
		return is_array($this->decoded_response)?
			isset($this->decoded_response[$key]) : isset($this->decoded_response->{$key});
	}

	public function offsetGet($key){
		$this->decode_response();
		if(!$this->offsetExists($key))
			return NULL;

		return is_array($this->decoded_response)?
			$this->decoded_response[$key] : $this->decoded_response->{$key};
	}

	public function offsetSet($key, $value){
		throw new RestClientException("Decoded response data is immutable.");
	}

	public function offsetUnset($key){
		throw new RestClientException("Decoded response data is immutable.");
	}

	/**
	 * Request method GET
	 * @param $url
	 * @param array $parameters
	 * @param array $headers
	 * @return RestClient
	 */
	public function get($url, $parameters=array(), $headers=array()){
		return $this->execute($url, 'GET', $parameters, $headers);
	}

	/**
	 * Request method POST
	 * @param $url
	 * @param array $parameters
	 * @param array $headers
	 * @return RestClient
	 */
	public function post($url, $parameters=array(), $headers=array()){
		return $this->execute($url, 'POST', $parameters, $headers);
	}

	/**
	 * Request method PUT
	 * @param $url
	 * @param array $parameters
	 * @param array $headers
	 * @return RestClient
	 */
	public function put($url, $parameters=array(), $headers=array()){
		return $this->execute($url, 'PUT', $parameters, $headers);
	}

	/**
	 * Request method DELETE
	 * @param $url
	 * @param array $parameters
	 * @param array $headers
	 * @return RestClient
	 */
	public function delete($url, $parameters=array(), $headers=array()){
		return $this->execute($url, 'DELETE', $parameters, $headers);
	}

	/**
	 * Executing CURL request
	 * @param $url
	 * @param string $method
	 * @param array $parameters
	 * @param array $headers
	 * @return RestClient
	 * @throws Rest\RestClientException
	 */
	protected  function execute($url, $method='GET', $parameters=array(), $headers=array()){
		$this->debugCounter = 0;
		if (self::DEBUG) $this->debug(0, 'executing curl');
		$client = clone $this;
		$client->url = $url;
		$client->handle = curl_init();
		$curlopt = array(
			'CURLOPT_HEADER' => TRUE,
			'CURLOPT_RETURNTRANSFER' => TRUE,
			'CURLOPT_USERAGENT' => $client->options['user_agent']
		);

		if($client->options['username'] && $client->options['password'])
			$curlopt['CURLOPT_USERPWD'] = sprintf("%s:%s",
				$client->options['username'], $client->options['password']);

		if(count($client->options['headers']) || count($headers)){
			$curlopt['CURLOPT_HTTPHEADER'] = array();
			$headers = array_merge($client->options['headers'], $headers);
			foreach($headers as $key => $value){
				$curlopt['CURLOPT_HTTPHEADER'][] = sprintf("%s:%s", $key, $value);
			}
		}

		if($client->options['format'])
			$client->url .= '.'.$client->options['format'];

		if (is_string($parameters)) {
			$parameters = implode('&',$client->options['parameters']) . $parameters;
		} else {
			$parameters = array_merge($client->options['parameters'], $parameters);
		}
		if(in_array(strtoupper($method), array('POST', 'DELETE', 'PUT'))){
			$curlopt['CURLOPT_CUSTOMREQUEST'] = strtoupper($method);
			$curlopt['CURLOPT_POST'] = TRUE;
			$curlopt['CURLOPT_POSTFIELDS'] = is_string($parameters) ? $parameters : $client->format_query($parameters);
		}
		elseif(count($parameters)){
			$client->url .= strpos($client->url, '?')? '&' : '?';
			$client->url .= is_string($parameters) ? $parameters : $client->format_query($parameters);
		}

		if($client->options['base_url']){
			if($client->url[0] != '/' || substr($client->options['base_url'], -1) != '/')
				$client->url = '/' . $client->url;
			$client->url = $client->options['base_url'] . $client->url;
		}
		$curlopt['CURLOPT_URL'] = $client->url;

		if($client->options['curl_options']){
			$curlopt = array_merge($curlopt, $client->options['curl_options']);
		}

		if (self::DEBUG) $this->debug(0,'curl options', $curlopt);
		$curloptparsed = array();
		foreach($curlopt as $key => $value) {
			$curloptparsed[constant($key)] = $value;
		}
		curl_setopt_array($client->handle, $curloptparsed);

		$client->parse_response(curl_exec($client->handle));
		$client->info = (object)curl_getinfo($client->handle);
		$client->error = curl_error($client->handle);

		curl_close($client->handle);

		$client->decode_response();
		if (self::DEBUG) $this->debug(0,'decoded response', $client->decoded_response);

		if ($client->info->http_code !== 200) {
			$message = '';
			switch ($client->info->http_code) {
				case 302: $httpcode = 'HTTP/1.1 302 Found'; $message = 'Redirected to: ' . $client->info->redirect_url; break;
				case 400: $httpcode = 'HTTP/1.1 400 Bad Request'; $message = 'Response: ' . $client->decoded_response->message; /*'Check parameters.';*/ break;
				default:
					$httpcode = 'HTTP/1.1 ' . $client->info->http_code . ' Unknown';
			}

			throw new RestClientException(
				$httpcode . '. ' . $message
			);
		}

		return $client;
	}

	public function format_query($parameters, $primary='=', $secondary='&'){
		$query = "";
		foreach($parameters as $key => $value){
			$pair = array(urlencode($key), urlencode($value));
			$query .= implode($primary, $pair) . $secondary;
		}
		return rtrim($query, $secondary);
	}

	public function parse_response($response){
		$headers = null;
		$parts = explode("\r\n\r\n", $response);
		$body = '';
		if (self::DEBUG) $this->debug(1, 'curl response', $response);
		foreach($parts as $index => $part) {
			if (self::DEBUG) $this->debug(1, 'parsing part');
			if (preg_match('/^http/i',$part)) {
				if (self::DEBUG) $this->debug(1, 'part ' . $index . ' is header', $part);
				$http_ver = strtok($part, "\n");
				if (isset($headers)) {
					if (!array_key_exists('previous', $headers)) {
						$headers = array('previous' => $headers);
					} else {
						$tmp = $headers;
						unset($tmp['previous']);
						$headers['previous'][] = $tmp;
					}
				}
				$headers['http_code'] = trim($http_ver);
				while(($line = strtok("\n")) !== false){
					if(strlen(trim($line)) == 0) break;
					list($key, $value) = explode(':', $line, 2);
					$key = trim(strtolower(str_replace('-', '_', $key)));
					$value = trim($value);
					if (empty($headers[$key])) {
						$headers[$key] = $value;
					} elseif (is_array($headers[$key])) {
						$headers[$key][] = $value;
					} else {
						$headers[$key] = array($headers[$key], $value);
					}
				}
			} else {
				if (self::DEBUG) $this->debug(1, 'part ' . $index . ' is body', $part);
				$body = $part;
			}
		}
		if (self::DEBUG) $this->debug(0, 'decoded header', $headers);
		$this->headers = (object) $headers;
		$this->response = $body;
	}

	public function get_response_format(){
		if(!$this->response)
			throw new RestClientException(
				"A response must exist before it can be decoded.");

		// User-defined format.
		if(!empty($this->options['format']))
			return $this->options['format'];
		// Extract format from response content-type header.
		if(!empty($this->headers->content_type)) {
			if(preg_match($this->options['format_regex'], $this->headers->content_type, $matches)) {
				return $matches[2];
			}
		}

		throw new RestClientException(
			"Response format could not be determined.". print_r($this->headers,true));
	}

	public function decode_response(){
		if(empty($this->decoded_response)){
			$format = $this->get_response_format();
			if(!array_key_exists($format, $this->options['decoders']))
				throw new RestClientException("'${format}' is not a supported ".
					"format, register a decoder to handle this response.");

			$this->decoded_response = call_user_func(
				$this->options['decoders'][$format], $this->response);
		}

		return $this->decoded_response;
	}

	/**
	 * Debug function
	 */
	private function debug()
	{
		$args = func_get_args();
		if ($args[0] > self::VERBOSE ) return;
		if (func_num_args()>2) {
			print str_pad( '=== ' . strtoupper($args[1]) . ' =', 40, '=', STR_PAD_RIGHT) . "\n";
			if (is_array($args[2]))  {
				print_r($args[2]);
				print "\n";
			} else {
				var_dump($args[2]);
			}
		} else {
			print str_pad( '=== ' . strtoupper($args[1]) . ' =', 40,'=', STR_PAD_RIGHT) . "\n";
		}
	}
}
