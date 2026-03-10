<?php
declare(strict_types=1);

namespace App\View;

class JavascriptView extends AppView
{
    public $layout = 'javascript2';

    public function initialize(): void
    {
        parent::initialize();
echo 'hello';exit;
        $this->response = $this->response->withType('ajax');
    }

    public function output()
    {
echo 'hello';exit;

        $result = $this->viewVars['content'];
        // return $result;
        // Obtener el encoding valido
        if(isset($_SERVER['HTTP_ACCEPT_ENCODING'])){
            // Determine supported compression method
            $gzip = strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip');
            $deflate = strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'deflate');

            // Determine used compression method
            $encoding = $gzip? 'gzip' : ($deflate ? 'deflate' : 'none');

            // Check for buggy versions of Internet Explorer
            if (!strstr($_SERVER['HTTP_USER_AGENT'], 'Opera') && preg_match('/^Mozilla\/4\.0 \(compatible; MSIE ([0-9]\.[0-9])/i', $_SERVER['HTTP_USER_AGENT'], $matches)) {
                $version = floatval($matches[1]);
                if ($version < 6) $encoding = 'none';
            }
        }else{
            $encoding = 'none';
        }

        if ($encoding != 'none') header ("Content-Encoding: " . $encoding);

        switch ($encoding){
            case 'gzip': $result = gzencode($result, 9, FORCE_GZIP); break;
            case 'deflate':  $result = gzencode($result, 9, FORCE_DEFLATE); break;
            default: // empty
        }

        return $result;
    }
}