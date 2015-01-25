<?php

class S3Pagefiles extends Pagefiles
{
  public function makeBlankItem()
  {
    return new S3Pagefile($this, ''); 
  }

  public function add($item)
  {
    if(is_string($item))
    {
      $item = new S3Pagefile($this, $item); 
    }
    return parent::add($item); 
  }

  // These will always go to temp
  public function path()
  {
    // $this->config->paths->tmp is inside assets, so using system temp
    $temp = sys_get_temp_dir() .  DIRECTORY_SEPARATOR;
    $page_id = $this->page->id;
    // Creating a folder with the page ID like PW
    $fullpath = $temp."pw_".$page_id.DIRECTORY_SEPARATOR;
    if(!file_exists($fullpath))
    {
      if(!@mkdir($fullpath))
        throw new WireException("Failed to create {$fullpath}");
    }
    return $fullpath;
  }
}
