<?php

class WP_License_It_Protect_File {

    public function __construct(){

    }


     /**
     * @usgae Block http access to a dir
     * @param $upload_dir
     */
    function blockHTTPAccess($upload_dir, $fileType = '\.zip$')
    {
        // Block direct access to the files (Apache 2.4 and 2.2). Downloads go through the license API.
        // Note: nginx ignores .htaccess, so deny this folder in the server config as well.
        $cont = "<FilesMatch \"{$fileType}\">\r\n"
            . "<IfModule mod_authz_core.c>\r\nRequire all denied\r\n</IfModule>\r\n"
            . "<IfModule !mod_authz_core.c>\r\nOrder allow,deny\r\nDeny from all\r\n</IfModule>\r\n"
            . "</FilesMatch>\r\n";
        @file_put_contents($upload_dir . '/.htaccess', $cont);
        @file_put_contents($upload_dir . '/index.php', "<?php\r\n// Silence is golden.\r\n");
        //@file_put_contents($upload_dir . '/web.config', $_cont);
    }



}




