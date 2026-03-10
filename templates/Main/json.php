<?php
    if(isset($this->viewVars['_'])){
        $output = json_encode($this->viewVars['_']);
    }else{
        $output = json_encode($this->viewVars);
    }

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
        case 'gzip': $output = gzencode($output, 9, FORCE_GZIP); break;
        case 'deflate':  $output = gzencode($output, 9, FORCE_DEFLATE); break;
        default: // empty
    }

    echo $output;