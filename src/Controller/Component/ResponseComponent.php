<?php
namespace App\Controller\Component;

use Cake\Utility\Security;

use Cake\Event\Event;

use Cake\Controller\Component;
use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Hash;

class ResponseComponent extends Component {
    private $messages = [];
    private $success = false;
    private $controller = null;

    // private $post = null;
    private $errors = [];

    public function initialize(array $config): void {
        $this->controller = $this->_registry->getController();
    }

    public function beforeRender(Event $event)
    {
        // debug($this->controller->viewBuilder());exit;
        $viewBuilder = $this->controller->viewBuilder();
        $viewBuilder->setVar('success', $this->success);
        $viewBuilder->setVar('min_version', Configure::read('App.min_version'));

        if(!empty($this->messages)){
            // $this->controller->viewVars['messages'] = $this->messages;
            $viewBuilder->setVar('messages', $this->messages);
        }

        if(!empty($this->errors)){
            // $this->controller->viewVars['errors'] = $this->errors;
            $viewBuilder->setVar('errors', $this->success);
        }

        // if(!isset($this->viewVars['message'])){
        //     $this->viewVars['message'] = [];
        // }

    	// 	$this->viewVars['message'] = array_merge($this->viewVars['message'], $obj_message);
    	// }else{
        //     // $this->set('success', $bool_success);
    	// }
    }

    public function success($_success = true)
    {
        $this->success = $_success;
    }

    public function set($key, $value)
    {
        $this->controller->set($key, $value);
    }

    public function data($_data = [])
    {
        $this->controller->viewVars['data'] = $_data;
    }

    public function add_errors($errors) {
        if(empty($errors)) return;

        if(is_string($errors)){
            $this->errors[] = $errors;
        }elseif(is_array($errors)){
            foreach($errors as $field => $rules){
                if(is_array($rules)){
                    foreach($rules as $rule => $error){
                        $this->errors[] = $error;
                    }
                }else{
                    $this->errors[] = $rules;
                }
            }
        }
    }

    public function add_messages($messages) {
        if(empty($messages)) return;

        if(is_string($messages)){
            $this->messages[] = $messages;
        }
    }

    public function is_error() {
        return count($this->errors)? true : false;
    }

    public function message($_message = null) {
        // if(!isset($this->viewVars['message'])){
        //     $this->viewVars['message'] = [];
        // }

    	if(is_array($_message)){
    		$this->messages = array_merge($this->messages, $_message);
    	}else{
            $this->messages[] = $_message;
            // $this->set('success', $bool_success);
    	}
    }

}