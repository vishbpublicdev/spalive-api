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

}