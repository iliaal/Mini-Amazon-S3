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

	public function __construct($public_key, $secret_key, $use_ssl = 1) {
		$this->use_ssl = $use_ssl;
		$this->secret_key = $secret_key;
		$this->public_key = $public_key;
	}

	public function setS3Host($host) {
		$this->s3_host = $host;
	}

	public function getErrorInfo() {
		return $this->error;
	}

	public function deleteFile($filename, $bucket) {
		$this->cleanState();
		return $this->sendRequest('DELETE', $this->buildURL($bucket, $filename));
	}

	public function deleteBucket($bucket) {
		$this->cleanState();
		return $this->sendRequest('DELETE', $this->buildURL($bucket));
	}

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

	public function storeString($data, $bucket, $s3_name, $mime = '', $acl = self::ACL_PRIVATE, $storage = self::STORAGE_CLASS_STANDARD, $encrypt = 1) {
		$this->initStorage($storage, $encrypt, $acl);
		$this->populateMime($mime, $data, 0);

		$this->headers['Content-MD5'] = base64_encode(md5($data, true));

		return $this->sendRequest('PUT', $this->buildURL($bucket, $s3_name), $data);
	}

	public function getFile($filename, $bucket) {
		$this->cleanState();
		return $this->sendRequest('GET', $this->buildURL($bucket, $filename));
	}

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
	
	public function existsBucket($name) {
		$this->cleanState();
		return ($this->sendRequest('HEAD', $this->buildURL($name)) === true);
	}

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

	private function buildURL($bucket, $path = '') {
		return 
				($this->use_ssl ? 'https://' : 'http://') .
				$bucket . '.'  .
				$this->s3_host . '/' .
				$path;
	}

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

	private function cleanState() {
		$this->errors = $this->headers = $this->amz_headers = array();
	}

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
