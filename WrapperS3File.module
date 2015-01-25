<?php

// Temporary autoload module to provide the download wrapper
class WrapperS3File extends Wiredata implements Module
{
  public function init()
  {
    $this->addHook("ProcessPageView::pageNotFound", $this, 'hookWrapper');
  }

  public function hookWrapper($event)
  {
    $page = $event->arguments[0]; 
    if($page && $page->id) return; 

    $path = $event->arguments[1];

    if($path == '/s3wrapper/')
    {
      $page = wire('pages')->get($this->input->get->page_id);
      $field = $this->input->get->field;
      $file = $page->$field->getFile($this->input->get->basename);

      if(!$file)
        throw new PageNotFoundException($this->input->get->basename . " does not exist");

      if(!$file->s3_url or !$file->s3_url_expires or time() > strtotime($file->s3_url_expires))
      {
        $file->updateUrl();
      }      
      $this->session->redirect($file->s3_url);
    }
  }
}