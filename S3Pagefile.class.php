<?php

/**
 * S3 Pagefile
 * 
 * Represents a single file located on S3. Handles "installation" of the 
 * file in the same quirky way the core Pagefile-class does, but with some
 * additional checks to prevent accidental installation when waking up 
 * from the database. These checks might prevent some API use cases, 
 * however it is fully compatible with the PW admin.
 * 
 * Note: To "install" through the API, always provide the absolute path to 
 * the file.
 * 
 */
 
require_once __DIR__.'/vendor/autoload.php';

use Aws\S3\S3Client;
use Aws\S3\Exception\NoSuchKeyException;
use Guzzle\Http\EntityBody;

class S3Pagefile extends Pagefile
{
  protected $client;
  protected $aws_region;
  protected $aws_default_region;
  protected $s3_bucket;
  protected $s3_default_bucket;
  protected $s3_default_expiration;
  protected $pagefiles;

  // We want to track whether we are waking up from DB, because we *NEVER* want to install
  // a new file if we are
  protected $wakeup; 

  public function __construct(Pagefiles $pagefiles, $filename, $wakeup = false)
  {
    $m = wire('modules')->get('FieldtypeS3File');

    // TODO: Validate settings elsewhere
    if(!$m->aws_access_key_id or !$m->aws_secret_access_key or !$m->aws_region or !$m->aws_s3_default_bucket)
      throw new WireException("Bucket is not configured");

    $this->client = S3Client::factory(array(
      'key'    => $m->aws_access_key_id,
      'secret' => $m->aws_secret_access_key,
      'region' => $m->aws_region
    ));

    $this->aws_region = $m->aws_region;
    $this->aws_default_region = $m->aws_region;

    $this->s3_bucket = $m->aws_s3_default_bucket;
    $this->s3_default_bucket = $m->aws_s3_default_bucket;

    $this->s3_default_expiration = '+10 minutes';

    $this->wakeup = $wakeup;

    // Respecting the parent constructor, which also sets empty values
    // These can change in setFilename() (which is called through the parent's
    // constructor)
    $this->set('size', 0); 
    $this->set('s3_location', ''); 
    $this->set('s3_url', ''); 
    $this->set('s3_url_expires', ''); 

    parent::__construct($pagefiles, $filename);
  }


  public function setFilename($filename)
  {
    // Check if we were called from wakeup, we *NEVER* want to end up installing
    // a new file then
    if($this->isStored())
    {
      $this->set('basename', $filename);
      return;
    }

    // I'm not exactly sure why the parent compares the basename with the filename,
    // to decide on installation. The InputfieldFile -system always gives just the basename. 
    $basename = basename($filename);

    // You should never try to make an instance of an existing Pagefile by trying to supply
    // the name that is in the database. A new instance is a new instance...

    // TODO: Not exactly sure what happens with the overwrite thingy enabled etc
    // or if this method is used in some weird way through the API (meaning that
    // getFile found a file with the same name). Currently we always install the
    // file as a new file if we end up here

    // We make a HUGE assumption here that we ended up here through a WireUpload.
    // It is quite odd that we are only passed the basename of the file...

    // This is to support installing new files in the very weird constructor way that PW supports
    if($basename != $filename)
      $fullpath = $filename;
    else
      $fullpath = $this->pagefiles->path() . $filename;

    if(!is_readable($fullpath))
      throw new WireException("Cannot read {$fullpath} - installation cancelled");

    // Check if there is already a file with the same $basename - has to be done like
    // this because we can't trust WireUpload's renaming process (since the files don't
    // persist on disk)
    $basename = $this->getFreeBasename($basename);

    // Quick debug purposes: 
    // throw new WireException("Going to install " . $filename . " as " . $basename . " from " . $fullpath);

    try {
      $this->installS3($fullpath, $basename);
    } catch(Exception $e) {
      // Just rethrow
      throw $e;
    } finally {
      // Even if we failed, keep the temporary files clean
      if(basename($filename) == $filename)
      {
        // Remove the original file from disk in the case of WireUploaded files
        $this->removeTemp($fullpath);
      }
    }
  }

  protected function removeTemp($fullpath)
  {
    unlink($fullpath);
    $dp = opendir(dirname($fullpath));
    $empty = true;
    while(($file = readdir($dp)) !== false)
    {
      if($file == "." or $file == "..")
      {
        $empty = false;
        break;
      }
    }
    rmdir(dirname($fullpath));
  }

  protected function getFreeBasename($basename)
  {
    // So many arguments... we have changed this so that the name is NOT originalized
    // - WireUpload has already handles this
    $basename = $this->pagefiles->cleanBasename($basename, false, false, true);
    $pathInfo = pathinfo($basename); 

    $basename = basename($basename, ".{$pathInfo['extension']}"); 
    $basenameNoExt = $basename; 
    $basename .= ".{$pathInfo['extension']}"; 

    $cnt = 0; 
    while($file = $this->pagefiles->getFile($basename))
    {
      $cnt++;
      $basename = "{$basenameNoExt}-{$cnt}.{$pathInfo['extension']}";
    }
    return $basename;
  }

  // This method will *ALWAYS* install a new file in S3, do not try to override existing files with this
  protected function installS3($fullpath, $filename)
  {
    $field = $this->pagefiles->getField();
    if(!$field)
      throw new WireException("How did we end up in this situation? There is no field associated with the S3Pagefiles");

    // Generate a free key
    $baseKey = "PW_".$field->name."_".$this->pagefiles->getPage()->id."_".$filename;
    $key = $baseKey;
    while($this->objectExists($key))
    {
      // Quick and dirty
      $key = $baseKey . "_" .uniqid();
    }

    $this->putObject($fullpath,$key);

    // $this->message("File {$filename} uploaded to S3!");

    $this->changed('file');

    // I wonder why parent::set is used instead of $this->
    parent::set('basename', $filename); 
    // So we don't have to update these from S3
    parent::set('size', filesize($fullpath));
    parent::set('s3_location', $this->aws_region . ":" . $this->s3_bucket . ":" . $key);
    parent::set('created', time());
    parent::set('modified', time());
  }

  public function putObject($filename,$key)
  {
    $this->client->putObject([
      'Bucket' => $this->s3_bucket,
      'Key'    => $key,
      'Body'   => EntityBody::factory(fopen($filename, 'r+'))
    ]);
  }

  public function objectExists($key)
  {
    return $this->client->doesObjectExist([
      'Bucket' => $this->s3_bucket,
      'Key'    => $key,
    ]);
  }

  public function setLocation()
  {
    list($region,$bucket,$key) = explode(":", $this->s3_location);
    $this->switchRegion($region);
    $this->setBucket($bucket);
    return $key;
  }

  protected function switchRegion($region = false)
  {
    // Reset to default region (if necessary)
    if($region === false)
      $region = $this->aws_default_region;

    if($this->aws_region !== $region)
    {
      $this->setRegion($region);
      // $this->message("Switched region to {$region}");
    }
  }

  protected function setBucket($bucket)
  {
    $this->s3_bucket = $bucket;
  }

  protected function setRegion($region)
  {
    $this->client->setRegion($region);
    $this->aws_region = $region;
  }

  public function getObject($key = false)
  {
    if($key === false)
      $key = $this->setLocation();

    // $this->message("Loading {$key} from S3");
    // TODO: Should probably cache the objects
    $object = $this->client->getObject(['Bucket'=>$this->s3_bucket, 'Key'=>$key]);
    return $object;
  }

  public function getObjectUrl($key = false)
  {
    if($key === false)
      $key = $this->setLocation();    

    $expires = $this->s3_default_expiration; // TODO: Make this overridable
    $newUrl = $this->client->getObjectUrl($this->s3_bucket, $key, $expires);

    return [$newUrl, strtotime($expires . " -30 seconds")];
  }

  public function updateUrl()
  {
    list($url, $expires) = $this->getObjectUrl();
    $this->s3_url = $url;
    $this->s3_url_expires = date("Y-m-d H:i:s", $expires);
    $this->save();
  }

  public function save()
  {
    // It's weird there's no implementation for this
    $this->changed('file');

    $field = $this->pagefiles->getField();
    $page = $this->pagefiles->getPage();

    $page->setOutputFormatting(false); // Why do we need this?
    $page->save($field->name);
  }


  public function deleteObject($key)
  {
    $this->client->deleteObject(['Bucket'=>$this->s3_bucket, 'Key'=>$key]);
  }

  public function get($key)
  {
    switch($key)
    {
      // We are only interested in overriding these, since they use filemtime to detect filesize
      case 'modified':
      case 'created':
        // We need to look it up directly, because using parent::get() causes the filemtime calls
        $value = isset($this->data[$key]) ? $this->data[$key] : null; 
        if(!$value) $value = time();
        return $value;
      default:
        return parent::get($key);
    }
  }

  public function ___httpUrl()
  {
    // TODO: We really need a Process module for this
    // Not returning directly since it's slow and PW finds the urls when editing the page, for an example
    // return $this->getObjectUrl($this->basename);
    $request = http_build_query([
      'page_id'  => $this->pagefiles->page->id,
      'field'    => $this->pagefiles->field->name,
      'basename' => $this->basename,
    ]);
    return rtrim($this->config->urls->root,'/') . WrapperS3File::$location . "?" . $request;
  }
  
  public function unlink()
  {
    $key = $this->setLocation();
    $this->deleteObject($key);
    // $this->message("Deleted {$this->basename} from S3");
  }
  
  public function url()
  {
    return self::isHooked('S3Pagefile::url()') ? $this->__call('url', array()) : $this->___url();
  }

  protected function ___url()
  {
    return $this->___httpUrl();
  }
  
  public function filename()
  {
    return self::isHooked('S3Pagefile::filename()') ? $this->__call('filename', array()) : $this->___filename();
  }

  protected function ___filename()
  {
    // TODO: Check where this is used. Probably not by the API itself. We could return basename instead
    throw new WireException(__("Amazon files are never located on the disk"));
  }

  public function filesize()
  {
    return $this->size;
  }

  public function rename($basename)
  {
    throw new WireException("Unsupported at the moment");
  }

  public function copyToPath($path)
  {
    throw new WireException("Unsupported at the moment");
  }

  protected function ___install($filename)
  {
    throw new WireException("Never try to install using the regular install() method");
  }

  // Just a debug helper to get the call stack for AJAX calls (since Exceptions are not logged
  // then)
  protected function dumpStack()
  {
    @ob_start();
    debug_print_backtrace();
    file_put_contents("/tmp/stack.txt", ob_get_contents());
    @ob_end_clean();
  }

  // A simple method to check if the file is stored. Currently this is true only when
  // the object is created through wakeup
  public function isStored()
  {
    return $this->wakeup;
  }
}
