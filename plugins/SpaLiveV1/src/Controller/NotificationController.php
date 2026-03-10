<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;


class NotificationController extends AppPluginController{

	public function initialize() : void{
        parent::initialize();
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.ApiDevice');
    }

    public function notify_register(){
    	$token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session ', false);
                return;
            }
            $this->set('session ', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session ', false);
            return;
        }

		// if($is_admin == 1){return;}

		$uid = get('device_uid');
		$name = get('name');
		$version = get('ver');
		$device = get('device');
		$device_token = get('device_token','');
		$user_id = $user['user_id'];


		if($device == 'IOS'){
			$device_token = str_replace(array(' ','<','>'), array('','',''), $device_token);
		}

		if(!empty($device)){
			$array_save = array(
				//'id' => 0,
				'application_id' => APP_ID,
				'device' => $device,
				'uid' => $uid,
				'token' => $device_token,
				'name' => $name,
				'version' => $version,
				'user_id' => $user_id,
			);

			$array_device = $this->ApiDevice->find()->select(['ApiDevice.id'])->where([ 'OR' => ['ApiDevice.uid' => $uid, 'ApiDevice.token' => $device_token]])->first();

			if(!empty($array_device)){
				$array_save['id'] = intval($array_device->id);
			}

			$saveDevice = $this->ApiDevice->save($this->ApiDevice->newEntity($array_save));
			if($saveDevice !== false){
				$device_id = !isset($array_save['id']) ? $saveDevice->id : $array_save['id'];
				$this->success();
				$this->set('uid',$uid);
				$this->set('device_token',$device_token);
			}else{
				$this->message('The device could not be registered.');
			}
		}else{
			$this->message('Invalid type device..');
		}
	}


	// Controller Actions

	public function update_notification_setting(){
		$token = get('token', '');
		
		if(!empty($token)){
			$user = $this->AppToken->validateToken($token, true);
			if($user === false){
				$this->message('Invalid token.');
				$this->set('session ', false);
				return;
			}
			$this->set('session ', true);
		} else {
			$this->message('Invalid token.');
			$this->set('session ', false);
			return;
		}

		$setting = get('setting', '');
		if(empty($setting)){
			$this->message('Empty setting.');
			return;
		}

		$enable = get('enable', 0);

		$user_settings = $this->get_notifications_settings($user['user_id']);

		if(empty($user_settings)){			
			$this->create_notification_setting($user['user_id']);
			$user_settings = $this->get_notifications_settings($user['user_id']);	
		}

		switch($setting){
			case 'email':
				$user_settings->allow_email = $enable;
				break;	
			case 'sms':
				$user_settings->allow_sms = $enable;
				break;
			case 'push':
				$user_settings->allow_push = $enable;
				break;
		}
		
		$user_settings = $this->DataNotificationsSettings->save($user_settings);
		$this->success();
		$this->set('settings',$user_settings);
	}

	// Generic Functions
	private function create_notification_setting($user_id){
		if(!empty($this->get_notifications_settings($user_id))){
			return;
		}
		$array_save = array(
			'user_id' => $user_id,
			'allow_email' => 1,
			'allow_sms' => 1,
			'allow_push' => 1,
		);
		$setting = $this->DataNotificationsSettings->newEntity($array_save);
		$c_entity = $this->DataNotificationsSettings->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataNotificationsSettings->save($c_entity); 
        }
	}

	private function get_notifications_settings($user_id){
		$this->loadModel('SpaLiveV1.DataNotificationsSettings');
		$setting = $this->DataNotificationsSettings->find()->where(['user_id' => $user_id])->first();
		return $setting;
	}
}