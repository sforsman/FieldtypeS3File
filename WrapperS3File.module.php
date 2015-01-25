<?php

/**
 * Quick wrapper for S3 downloads
 * 
 * Basically requests a signed URL from S3 (when necessary), then
 * redirects to the location.
 * 
 */

class WrapperS3File extends Wiredata implements Module
{
  // Defines where we "listen"
  static $location = "/s3wrapper/";
  
  public function init()
  {
    $this->addHook("ProcessPageView::pageNotFound", $this, 'hookWrapper');
  }

  public function hookWrapper($event)
  {
    // Basic checks yanked from an example
    $page = $event->arguments[0]; 
    if($page && $page->id) return; 

    $path = $event->arguments[1];
    if($path != self::$location)
      return;
      
    $page = wire('pages')->get($this->input->get->page_id);
    $field = $this->input->get->field;
    $file = $page->$field->getFile($this->input->get->basename);

    if(!$file)
      throw new PageNotFoundException($this->input->get->basename . " does not exist");

    // Check if our "cached" URL needs refreshing
    if(!$file->s3_url or !$file->s3_url_expires or time() > strtotime($file->s3_url_expires))
      $file->updateUrl();
      
    $this->session->redirect($file->s3_url);
  }
}
