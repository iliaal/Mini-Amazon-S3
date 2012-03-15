<?php
define('TEST_PUB_KEY', '');
define('TEST_PRIVATE_KEY', '');

require "../s3mini.php";

class MiniS3Test extends PHPUnit_Framework_TestCase {
  private $s3;
  private $bucket_name;

  public function __construct() {
    $this->bucket_name = 'test.bucket' . time();
    $this->s3 = new AmazonS3(TEST_PUB_KEY, TEST_PRIVATE_KEY);

    return parent::__construct();
  }

  public function testCreateBucket() {
    $this->assertTrue($this->s3->createBucket($this->bucket_name));
  }   

  public function testStoreString() {
    $this->assertTrue($this->s3->storeString('test 123', $this->bucket_name, 'abc.txt'));
  }
  
  public function testGetFile() {
    $this->assertEquals('test 123', $this->s3->getFile('abc.txt', $this->bucket_name));
  }

  public function testDeleteFile() {
    $this->assertTrue($this->s3->deleteFile('abc.txt', $this->bucket_name));
  }

  public function testBucketExistsSuccess() {
    $this->assertTrue($this->s3->existsBucket($this->bucket_name));
  }
  
  public function testBucketExistsFailure() {
    $this->assertFalse($this->s3->existsBucket($this->bucket_name . '.nosuchbucket'));
  }

  public function testStoreFile() {
    $file = tempnam(sys_get_temp_dir(), 'S#');
    file_put_contents($file, 'test ABC');
    $this->assertTrue($this->s3->storeFile($file, $this->bucket_name, 'abc.txt'));
    unlink($file);
    $this->assertTrue($this->s3->deleteFile('abc.txt', $this->bucket_name));
  }

  public function testDeleteBucket() {
    $this->assertTrue($this->s3->deleteBucket($this->bucket_name));
  }

  public function testDeleteFullBucket() {
    $this->assertTrue($this->s3->createBucket($this->bucket_name));  
    $this->assertTrue($this->s3->storeString('test 123', $this->bucket_name, 'abc.txt'));
    $this->assertEquals(409, $this->s3->deleteBucket($this->bucket_name));

    $this->assertTrue($this->s3->deleteFile('abc.txt', $this->bucket_name));
    $this->assertTrue($this->s3->deleteBucket($this->bucket_name));
  }

  public function testExistsFile() {
    $this->assertTrue($this->s3->createBucket($this->bucket_name));
    $this->assertTrue($this->s3->storeString('test 123', $this->bucket_name, 'abc.txt'));

    $this->assertTrue($this->s3->existFile('abc.txt', $this->bucket_name));
    $this->assertFalse($this->s3->existFile('nosuchfile', $this->bucket_name));

    $this->assertTrue($this->s3->deleteFile('abc.txt', $this->bucket_name));
    $this->assertTrue($this->s3->deleteBucket($this->bucket_name));
  }
}
