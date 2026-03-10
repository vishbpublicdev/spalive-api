<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Core\Configure;

class MainController extends AppController
{
	public function initialize():void{
        parent::initialize();

		$this->loadModel('ApiDebug');
        $this->loadModel('ApiApplication');
    }

	// http://localhost/api-spalive/?key=lkmklfdslkjfkj&token=1235&controller=Clinic&action=load
    public function index()
    {
		header('Access-Control-Allow-Origin: *');

		$this->RequestHandler->renderAs($this, 'json');
        $this->viewBuilder()->setTemplate('/Main/json');

		$this->_init_api();
		
		if(!empty(API_KEY)){
            $Application = $this->ApiApplication->get_app(API_KEY);
            if ($this->_init_app($Application)) {
				if(APP_DEBUG > 0) $this->ApiDebug->create_log(APP_ID, APP_KEY_ID, APP_DEBUG, API_ACTION, APP_USER);
				
				$class = '\\' . APP_FOLDER . '\Controller\\' . API_CONTROLLER . 'Controller';
				if(class_exists($class)){

					$this->_execute($class);
				}else{
					$this->message_error('Invalid controller.');
				}
            }else{
				$this->set('messages','Invalid key.');
			}
        }
	}

    private function message_error($message)
	{
		$this->set('messages',$message);
		$this->ApiDebug->set_error($message);
	}

    private function _execute($class)
	{
		$Controller = new $class();
		if(method_exists($Controller, API_ACTION)){
			call_user_func_array([$Controller, API_ACTION], []);
			$this->_output($Controller->output());
			if(APP_DEBUG > 0){ 
				if( API_ACTION == 'apply_promo_purchase'){
					$this->ApiDebug->set_result($Controller->output());
				}
			}
		}else{
			$this->message_error('Invalid action.');
		}
	}

    private function _output($vars)
	{
		$this->set($vars);
	}

    private function _init_api()
	{
        $str_app_key = get('key','');
		$str_action = get('action','');

		define('API_KEY', $str_app_key);
		define('API_TOKEN', get('token',''));
		define('API_VERSION', get('v', '1'));
		

		if(empty($str_action)){
			$action = '';
			$controller = '';
		}else{
			$str_action = str_replace("-", "_", $str_action);
			$arr_action = explode(':',$str_action);
			if(sizeof($arr_action) < 2){
				$arr_action2 = explode('____', $str_action);
				if (sizeof($arr_action2) < 2){
					$action = trim($arr_action[0]);
					$controller = 'Main';
				} else {
					$action = trim($arr_action2[1]);
					$controller = trim($arr_action2[0]);
				}
			}else{
				$action = trim($arr_action[1]);
				$controller = trim($arr_action[0]);
			}
		}

		define('API_ACTION', $action);
		define('API_CONTROLLER', $controller);
	}

    private function _init_app($Application)
	{
		if(empty($Application)) return false;

		define('APP_NAME', $Application->appname);
		define('APP_ID', intval($Application->id));
		define('APP_KEY_ID', intval($Application->key_id));
		define('APP_ORIGEN', trim($Application->type));
		define('APP_DEBUG', intval($Application->debug));
		define('APP_FOLDER', APP_NAME . 'V' . API_VERSION);
		if (defined('API_TOKEN')) {
			$this->loadModel('AppTokens');
			$ent_token = $this->AppTokens->find()->select('AppTokens.user_id')->where(['AppTokens.token' => API_TOKEN])->first();
			if (!empty($ent_token)) {
				define('APP_USER', $ent_token->user_id);
			}
		}

		if (!defined('APP_USER')) define('APP_USER', 0);
 

		$str_json_config = trim($Application->json_config);
		Configure::write('API_CONFIG', empty($str_json_config)? array() : json_decode($str_json_config, true));

		return true;
	}
}
