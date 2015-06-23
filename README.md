FieldtypeS3File
===============

First version of a File -field that stores uploaded files directly to Amazon S3. The 
aim of this module is to extend the existing core implementations as much as possible 
to retain compatibility, familiar interface and of course to benefit from the existing 
logic (mainly the AJAX-uploading mechanism). 

Files are served through the signed S3 URLs - never through the local disk. The generation
of the URLs is currently handled by a download wrapper (WrapperS3File). The final solution
will be something else and it will obviously support some sort of authentication.

There are a few important features that are missing and also a few notes to take into
consideration. These can be found in the comments of FieldtypeS3File.module.php. The
biggest "issue" is that you cannot request the location of the file through filename().
Technically this could be implemented, e.g. the actual file could be downloaded 
to the disk, however this should be avoided and hence it's left unimplemented until
there's a good reason to do so. Even then it might be better to explicitly request the
file stored on the local disk. The point here is that it might cause a lot of accidental
traffic ($$$) if used wrong (i.e. foreach($pagefiles as $file) { echo $file->filename(); })

Also there is no FieldtypeS3Image yet.

TODO
----

- More testing (the overwriting stuff, ZIP-upload, etc)
- Image field (will probaly be extending this one instead of the PW-field)
- Process-module for handling buckets
- Prefix-settings (to support the S3 "folders" etc)
- Remove Wrapper-module after the above is completed + Bucket creation from module
- Add per field settings (publicity, buckets, expiration, etc)
- Support other AWS credential-methods
- Hooks to delete files from S3 when the field is destroyed(?)

