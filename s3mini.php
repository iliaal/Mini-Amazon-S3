<?php
/**
* Mini Amazon S3 PHP Class
* @version 0.1.0
*
* Copyright (c) 2012, Ilia Alshanetsky.  All rights reserved.
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions are met:
*
* - Redistributions of source code must retain the above copyright notice,
*   this list of conditions and the following disclaimer.
* - Redistributions in binary form must reproduce the above copyright
*   notice, this list of conditions and the following disclaimer in the
*   documentation and/or other materials provided with the distribution.
*
* THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
* AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
* IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
* ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
* LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
* CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
* SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
* INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
* CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
* ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
* POSSIBILITY OF SUCH DAMAGE.
*
* Amazon S3 is a trademark of Amazon.com, Inc. or its affiliates.
*/

class AmazonS3 {
	const ACL_PRIVATE = 'private';
	const ACL_PUBLIC_READ = 'public-read';
	const ACL_PUBLIC_READ_WRITE = 'public-read-write';
	const ACL_AUTHENTICATED_READ = 'authenticated-read';

	const STORAGE_CLASS_STANDARD = 'STANDARD';
	const STORAGE_CLASS_RRS = 'REDUCED_REDUNDANCY';

	private $s3_host = 's3.amazonaws.com';
	private $use_ssl = 1;
	private $secret_key;
	private $public_key;

	const DEBUG_MODE = 0;

	private $amz_headers = array();
	private $error = array();

	/**
	 * Initialize class
	 *
	 * @param string $public_key Public key
	 * @param string $secret_key Secret key
	 * @param string $use_ssl Whether or not SSL should be used for communication with S3
	 * @return void
	 */
	public function __construct($public_key, $secret_key, $use_ssl = 1) {
		$this->use_ssl = $use_ssl;
		$this->secret_key = $secret_key;
		$this->public_key = $public_key;
	}

	/**
	 * Change S3 hostname
	 *
	 * @param string $host Alternate S3 hostname
	 * @return void
	 */
	public function setS3Host($host) {
		$this->s3_host = $host;
	}

	/**
	 * Returns error state
	 *
	 * @return array
	 */
	public function getErrorInfo() {
		return $this->error;
	}

	/**
	 * Delete a file
	 *
	 * @param string $filename name of the file to delete
	 * @param string $bucket name of the bucket where the file is located
	 * @return boolean
	 */
	public function deleteFile($filename, $bucket) {
		$this->cleanState();
		return $this->sendRequest('DELETE', $this->buildURL($bucket, $filename));
	}

	/**
	 * Delete a bucket
	 *
	 * @param string $name Bucket name
	 * @return boolean
	 */
	public function deleteBucket($bucket) {
		$this->cleanState();
		return $this->sendRequest('DELETE', $this->buildURL($bucket));
	}

	/**
	 * Create a file on S3 on a basis of a supplied file
	 *
	 * @param string $filename filename to upload
	 * @param string $bucket bucket name
	 * @param string $s3_name the name to assign the file on S3
	 * @param string $mime mime type of the file
	 * @param string $acl file access permissions
	 * @param string $storage file storage mode
	 * @param boolean $encrypt whether or not the file is to be stored in encrypted form
	 * @return boolean
	 */
	public function storeFile($filename, $bucket, $s3_name, $mime = null, $acl = self::ACL_PRIVATE, $storage = self::STORAGE_CLASS_STANDARD, $encrypt = 1) {
		if (!file_exists($filename) || !is_readable($filename)) {
			throw new Exception('Cannot access file: ' . $filename);
		}

		$this->initStorage($storage, $encrypt, $acl);
		$this->populateMime($mime, $filename, 1);

		$this->headers['Content-MD5'] = base64_encode(md5_file($filename, true));

		$this->filesize = filesize($filename);

		return $this->sendRequest('PUT', $this->buildURL($bucket, $s3_name), fopen($filename, 'rb'));
	}

	/**
	 * Create a file on S3 on a basis of a supplied string
	 *
	 * @param string $date file contents
	 * @param string $bucket bucket name
	 * @param string $s3_name the name to assign the file on S3
	 * @param string $mime mime type of the file
	 * @param string $acl file access permissions
	 * @param string $storage file storage mode
	 * @param boolean $encrypt whether or not the file is to be stored in encrypted form
	 * @return boolean
	 */
	public function storeString($data, $bucket, $s3_name, $mime = null, $acl = self::ACL_PRIVATE, $storage = self::STORAGE_CLASS_STANDARD, $encrypt = 1) {
		$this->initStorage($storage, $encrypt, $acl);
		$this->populateMime($mime, $data, 0);

		$this->headers['Content-MD5'] = base64_encode(md5($data, true));

		return $this->sendRequest('PUT', $this->buildURL($bucket, $s3_name), $data);
	}

	/**
	 * Retrieve a file
	 *
	 * @param string $name name of the file to retrieve
	 * @param string $bucket name of the bucket where the file is located
	 * @return false | string
	 */
	public function getFile($filename, $bucket) {
		$this->cleanState();
		return $this->sendRequest('GET', $this->buildURL($bucket, $filename));
	}
	
	/**
	 * Retrieve a file
	 *
	 * @param string $name name of the file to retrieve
	 * @param string $bucket name of the bucket where the file is located
	 * @return boolean
	 */
	public function existsFile($filename, $bucket) {
		$this->cleanState();
		return ($this->sendRequest('HEAD', $this->buildURL($bucket, $filename)) === true);
	}

	/**
	 * Create a new bucket
	 *
	 * @param string $name Bucket name
	 * @param string $name Bucket permissions
	 * @param region $region Region name within which the bucket should be created
	 * @return boolean
	 */
	public function createBucket($name, $acl = self::ACL_PRIVATE, $region = null) {
		$this->cleanState();
		if ($region) {
			$xml = new SimpleXMLelement('<CreateBucketConfiguration />');
			$xml['xmlns'] = 'http://s3.amazonaws.com/doc/2006-03-01/';
			$xml->LocationConstraint = $region;
			$data = trim(str_replace('<?xml version="1.0"?>', '', $xml->asXML()));
		} else {
			$data = null;
		}

		$this->amz_headers = array('x-amz-acl' => $acl ? $acl : self::ACL_PRIVATE);
		$this->headers = array('Content-Type' => 'application/xml');

		return $this->sendRequest('PUT', $this->buildURL($name), $data);
	}
	
	/**
	 * Determine whether or not the bucket exists
	 *
	 * @param string $name Bucket name
	 * @return boolean
	 */
	public function existsBucket($name) {
		$this->cleanState();
		return ($this->sendRequest('HEAD', $this->buildURL($name)) === true);
	}

	/**
	 * Create a signature for the S3 request
	 *
	 * @param string $action Action being performed
	 * @param string $resource URL of the request being executed
	 * @return string
	 */
	private function authSignature($action, $resource) {
		$key = $action . "\n";
		if (!empty($this->headers['Content-MD5'])) {
			$key .= $this->headers['Content-MD5'] . "\n";
		} else {
			$key .= "\n";
		}
		if (!empty($this->headers['Content-Type'])) {
			$key .= $this->headers['Content-Type'] . "\n";
		} else {
			$key .= "\n";
		}
		$key .= $this->headers['Date'] . "\n";
		ksort($this->amz_headers);
		foreach ($this->amz_headers as $k => $v) {
			$key .= strtolower($k) . ':' . $v . "\n";
		}
		$url = parse_url($resource);
		if ($url['host'] != $this->s3_host) {
			$key .= '/' . preg_replace('!\.' . preg_quote($this->s3_host) . '$!', '', $url['host']) . '/';
			if ($url['path'] != '/') {
				$key .= ltrim($url['path'], '/');
			}
		} else {
			$key .= $url['path'];
		}

		return base64_encode(hash_hmac('sha1', $key, $this->secret_key, true));
	}

	/**
	 * Create a URL for the S3 request
	 *
	 * @param string $bucket Bucket name
	 * @param string $path Path to the file being accessed
	 * @return string
	 */
	private function buildURL($bucket, $path = '') {
		return 
				($this->use_ssl ? 'https://' : 'http://') .
				$bucket . '.'  .
				$this->s3_host . '/' .
				$path;
	}

	/**
	 * Transmit a request to S3
	 *
	 * @param string $action Action being performed
	 * @param string $url URL for the request being performed
	 * @param string $data Content of the request
	 * @return boolean | integer | string
	 */
	private function sendRequest($action, $url, $data = null) {

		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $url);

		$this->headers['Date'] = gmdate('D, d M Y H:i:s T');

		$headers = array();
		foreach ($this->headers as $k => $v) {
			$headers[] = $k . ': ' . $v;
		}
		foreach ($this->amz_headers as $k => $v) {
			$headers[] = $k . ': ' . $v;
		}

		$headers[] = 'Authorization: AWS ' . $this->public_key . ':' . $this->authSignature($action, $url);

		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_HEADER, self::DEBUG_MODE);
		curl_setopt($curl, CURLINFO_HEADER_OUT, self::DEBUG_MODE);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);

		switch ($action) {
			case 'GET':
				break;
			case 'PUT':
				if ($data !== null && is_string($data)) {
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $action);
					curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
				} else if (is_resource($data)) {
					curl_setopt($curl, CURLOPT_PUT, true);
					curl_setopt($curl, CURLOPT_INFILE, $data);
					curl_setopt($curl, CURLOPT_INFILESIZE, $this->filesize);
				} else {
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $action);
				}
				break;
			case 'HEAD':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'HEAD');
				curl_setopt($curl, CURLOPT_NOBODY, true);
				break;

			case 'DELETE':
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
				break;

			default:
				throw new Exception('Invalid S3 Action: ' . $action);
		}
		$ret = curl_exec($curl);

		if (($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) == 200 || ($code == 204 && $action == 'DELETE')) {
			curl_close($curl);
			if ($action == 'GET') {
				return $ret;
			} else {
				return true;
			}
		} else {
			$this->error = array(
				'response' => $ret,
				'response_info' => curl_getinfo($curl),
				'code' => $code,
			);
			curl_close($curl);
			return $code;
		}
	}

	/**
	 * Clear out state variables allowing the class to be re-used
	 *
	 * @return void
	 */
	private function cleanState() {
		$this->errors = $this->headers = $this->amz_headers = array();
	}

	/**
	 * Initialize the amz* headers for store requests
	 *
	 * @param string $storage The storage mode to use
	 * @param boolean $encrypt Whether or not the file should be stored in encrypted mode
	 * @param string $acl What ACL mode should be assigned to the file
	 * @return void
	 */
	private function initStorage($storage, $encrypt, $acl) {
		$this->cleanState();
		if ($storage) {
			$this->amz_headers['x-amz-storage-class'] = $storage;
		} else {
			$this->amz_headers['x-amz-storage-class'] = self::STORAGE_CLASS_STANDARD;
		}
		if ($encrypt) {
			$this->amz_headers['x-amz-server-side-encryption'] = 'AES256';
		}
		if ($acl) {
			$this->amz_headers['x-amz-acl'] = $acl;
		} else {
			$this->amz_headers['x-amz-acl'] = self::ACL_PRIVATE;
		}
	}

	/**
	 * Populate the Content-Type header based on supplied mime-type or fileinfo with fail-over to application/octet-stream 
	 *
	 * @param string $mime The default mime type to use 
	 * @param string $data The file or string of data being analyzed
	 * @param boolean $is_file Whether not the supplied data is a file or a string
	 * @return void
	 */
	private function populateMime($mime, $data, $is_file) {
		if ($mime) {
			$this->headers['Content-Type'] = $mime;
			return;
		}
		$m = new finfo(FILEINFO_MIME);
		if ($is_file) {
			$mime = strtok($m->file($data), ';');
		} else {
			$mime = strtok($m->buffer($data), ';');
		}
		if ($mime) {
			$this->headers['Content-Type'] = $mime;
		} else {
			$this->headers['Content-Type'] = 'application/octet-stream';
		}
	}
}
