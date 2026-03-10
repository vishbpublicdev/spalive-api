<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.4
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace App\View;

/**
 * A view class that is used for AJAX responses.
 * Currently only switches the default layout and sets the response type -
 * which just maps to text/html by default.
 */
class JsonView extends AppView
{
    /**
     * The name of the layout file to render the view inside of. The name
     * specified is the filename of the layout in /templates/Layout without
     * the .php extension.
     *
     * @var string
     */
    public $layout = 'json';

    /**
     * Initialization hook method.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        // $this->response = $this->response->withType('ajax');
    }

    public function output()
    {
        // debug($this->viewVars);exit;
        if(isset($this->viewVars['_'])){
            $result = json_encode($this->viewVars['_']);
        }else{
            $result = json_encode($this->viewVars);
        }

        // $result = $this->viewVars['content'];
        // debug($result);exit;
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
