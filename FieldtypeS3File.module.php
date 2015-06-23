<?php

/**
 * ProcessWire S3 File Fieldtype
 *
 * Field that stores one or more files using Amazon S3 as backend
 *
 */

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/S3Pagefile.class.php';
require_once __DIR__.'/S3Pagefiles.class.php';

use Aws\S3\S3Client;

class FieldtypeS3File extends FieldtypeFile implements ConfigurableModule 
{
  public function __construct()
  {
    parent::__construct();
    $this->defaultInputfieldClass = "InputfieldFile";
  }


  protected function getDefaultFileExtensions()
  {
    return "pdf doc docx xls xlsx gif jpg jpeg png txt";
  }

  protected function getBlankPagefile(Pagefiles $pagefiles, $filename, $wakeup = false)
  {
    return new S3Pagefile($pagefiles, $filename, $wakeup); 
  }

  // This apparently is stored as the default value for the inputfield
  public function getBlankValue(Page $page, Field $field)
  {
    $pagefiles = new S3Pagefiles($page);
    $pagefiles->setField($field); 
    $pagefiles->setTrackChanges(true); 
    return $pagefiles; 
  }

  // Overriden so we can populate the S3-related fields from the database
  // The rest is business as usual (as in FieldtypeFile)
  public function ___wakeupValue(Page $page, Field $field, $value)
  {
    if($value instanceof Pagefiles) return $value; 
    $pagefiles = $this->getBlankValue($page, $field); 
    if(empty($value)) return $pagefiles; 
  
    if(!is_array($value) || array_key_exists('data', $value)) $value = array($value); 
    foreach($value as $v) {
      if(empty($v['data'])) continue; 

      // Ensure installation is NEVER called when we are waking up from the DB
      $pagefile = $this->getBlankPagefile($pagefiles, $v['data'], true); 
      $pagefile->description(true, $v['description']); 
      if(isset($v['modified'])) $pagefile->modified = $v['modified'];
      if(isset($v['created'])) $pagefile->created = $v['created'];
      if(isset($v['tags'])) $pagefile->tags = $v['tags'];
      if(isset($v['size'])) $pagefile->size = $v['size'];
      if(isset($v['s3_location'])) $pagefile->s3_location = $v['s3_location'];
      if(isset($v['s3_url'])) $pagefile->s3_url = $v['s3_url'];
      if(isset($v['s3_url_expires'])) $pagefile->s3_url_expires = $v['s3_url_expires'];
      $pagefile->setTrackChanges(true); 
      $pagefiles->add($pagefile); 
    }
  
    $pagefiles->resetTrackChanges(true); 
    return $pagefiles;  
  }

  // Overriden so we can store S3-related values in the database
  // The rest is business as usual (as in FieldtypeFile)
  public function ___sleepValue(Page $page, Field $field, $value)
  {
    $sleepValue = array();
    if(!$value instanceof Pagefiles) return $sleepValue; 
  
    foreach($value as $pagefile) {
      $item = array(
        'data' => $pagefile->basename,
        'description' => $pagefile->description(true), 
        'size' => $pagefile->size,
        's3_location' => $pagefile->s3_location,
        's3_url' => $pagefile->s3_url,
        's3_url_expires' => $pagefile->s3_url_expires,
      ); 
  
      if($field->fileSchema & self::fileSchemaDate) { 
        $item['modified'] = date('Y-m-d H:i:s', $pagefile->modified);
        $item['created'] = date('Y-m-d H:i:s', $pagefile->created);
      }
  
      if($field->fileSchema & self::fileSchemaTags) {
        $item['tags'] = $pagefile->tags;
      }
  
      $sleepValue[] = $item;
    }
    return $sleepValue;
  }


  public function ___getConfigInputfields(Field $field)
  {
    $inputfields = parent::___getConfigInputfields($field);

    // TODO: Implement overridable S3 settings
    
    return $inputfields;
  }

  public function getDatabaseSchema(Field $field)
  {
    $schema = parent::getDatabaseSchema($field);
    $schema['size'] = "int unsigned";
    $schema['s3_location'] = "text";
    $schema['s3_url'] = "text";
    $schema['s3_url_expires'] = "datetime";
    return $schema;
  }

  public static function getModuleConfigInputfields(array $data)
  {
    $inputfields = new InputfieldWrapper(); 

    // TODO: Support for environment variables and profiles
    // http://docs.aws.amazon.com/aws-sdk-php/guide/latest/credentials.html#credential-profiles

    $field = wire('modules')->get('InputfieldText');
    $field->name = 'aws_access_key_id';
    $field->label = "AWS access key ID";
    if(isset($data['aws_access_key_id'])) $field->value = $data['aws_access_key_id'];
    $inputfields->add($field); 

    $field = wire('modules')->get('InputfieldText'); 
    $field->name = 'aws_secret_access_key';
    $field->label = 'AWS secret access key';
    if(isset($data['aws_secret_access_key'])) $field->value = $data['aws_secret_access_key'];
    $inputfields->add($field);

    $field = wire('modules')->get('InputfieldText'); 
    $field->name = 'aws_region';
    $field->label = 'AWS region';
    if(isset($data['aws_region'])) $field->value = $data['aws_region'];
    $inputfields->add($field);

    $field = wire('modules')->get('InputfieldText'); 
    $field->name = 'aws_s3_default_bucket';
    $field->label = 'AWS S3 default bucket';
    if(isset($data['aws_region'])) $field->value = $data['aws_s3_default_bucket'];
    $inputfields->add($field);

    // TODO: Create a Process-module for managing buckets etc so we can remove this
    
    // Display bucket "tools" if configuration is set
    if(isset($data['aws_access_key_id']) and isset($data['aws_secret_access_key']) and isset($data['aws_region']) and isset($data['aws_s3_default_bucket']))
    {
      $s3Client = S3Client::factory(array(
          'key'    => $data['aws_access_key_id'],
          'secret' => $data['aws_secret_access_key'],
          'region' => $data['aws_region']
      ));
      if(!$s3Client->isValidBucketName($data['aws_s3_default_bucket']))
      {
        $field->error("Invalid bucket name");
      }
      else
      {
        // Handle creation of a new bucket
        if(wire('input')->get->create)
        {
          try {
            $bucket = ['Bucket'=>$data['aws_s3_default_bucket'], 'LocationConstraint'=>$data['aws_region']];
            $s3Client->createBucket($bucket);
            wire('session')->message("Bucket created!");
          } catch(Exception $e) {
            $field->error("Cannot create bucket: " . $e->getMessage());
          }
          wire('session')->redirect('edit?name=FieldtypeS3File');
        }
        // Handle deletion
        elseif(wire('input')->get->delete)
        {
          try {
            $bucket = ['Bucket'=>$data['aws_s3_default_bucket'], 'LocationConstraint'=>$data['aws_region']];
            $s3Client->deleteBucket($bucket);
            // $s3Client->waitUntil('BucketNotExists', $bucket);
            wire('session')->message("Bucket deleted!");
          } catch(Exception $e) {
            $field->error("Cannot delete bucket: " . $e->getMessage());
          }
          wire('session')->redirect('edit?name=FieldtypeS3File');
        }

        // Display bucket status
        $exists = $s3Client->doesBucketExist($data['aws_s3_default_bucket']);
        $field = wire('modules')->get('InputfieldMarkup');
        $field->value = "Bucket exists: " . (($exists) ? "Yes" : "No");
        if(!$exists)
          $field->value.= "<br /><a href='edit?name=FieldtypeS3File&create=1'>Create</a>";
        else
          $field->value.= "<br /><a href='edit?name=FieldtypeS3File&delete=1'>Delete</a>";
        $inputfields->add($field);
      }
    }
    
    return $inputfields; 
  }
}
