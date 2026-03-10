<?php 
	declare(strict_types=1);

	namespace SpaLiveV1\Controller;
	use App\Controller\AppPluginController;
	use Cake\Utility\Security;
	use Cake\Utility\Text;

	use Cake\Core\Configure;

	require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
	use Stripe\Stripe;
	use \Stripe\Error;

	class MintController extends AppPluginController {

		protected $mailgunKey = null;

		protected function getMailgunKey(): ?string
		{
			if ($this->mailgunKey === null) {
				$this->mailgunKey = env('MAILGUN_API_KEY');
			}
			return $this->mailgunKey;
		}

		public function initialize() : void {
	        parent::initialize();			
			$this->loadModel('SpaLiveV1.AppToken');

			\Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
	    }

	    public function create_mint_user_csv() {
			$token = get('token','');
			if(!empty($token)){            
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			if(!isset($_FILES['file']['name'])){            
					$this->message('no file found.');
					$this->set('session', false);
					return;                        
			} 
			$this->loadModel('SpaLiveV1.SysUsers');
			$aux_array_data = [];
			//$this->set('file',isset($_FILES['file']['name'])? $_FILES['file']['name']  : "no file found");
			$file = $_FILES['file']['tmp_name'];
			$handle = fopen($file, "r");
			$c = 0; //;
			$str_save="";
			while (($filesop = fgetcsv($handle, 1000, ",")) !== false) {         
				$filesop = array_map("utf8_encode", $filesop); //added 
				
				//remove non printable characters from every array element
				$filesop = $this->replaceNonPrintableCharacter($filesop);
				$error_msg = "";
				if ($c > 0 && $c < 10000) { //SKIP THE first row                     
					
					//======= Start Type (Client / Postlocation) Condition ==== //
					$name="";	$mname="";	$lname="";	$Phone="";	$Email=""; $message=""; 
					
					if (isset($filesop[0]) && !empty($filesop[0])) {
						$this->log($filesop[0]);
						$name = $filesop[0];	
					}
					if (isset($filesop[1]) && !empty($filesop[1])) {
						$this->log($filesop[1]);
						$mname = $filesop[1];
					}
					if (isset($filesop[2]) && !empty($filesop[2])) {
						$this->log($filesop[2]);
						$lname = $filesop[2];
					}
					if (isset($filesop[3]) && !empty($filesop[3])) {
						$this->log($filesop[3]);
						$Phone	= $filesop[3];
					}
					if (isset($filesop[4]) && !empty($filesop[4])) {
						$this->log($filesop[4]);
						$Email = $filesop[4];
					}

					
					$exist_email  = $this->SysUsers->find()->select()->where(['SysUsers.email' => $Email, 'SysUsers.deleted' => 0] )->first();
					/*if(!empty($exist_email)){
						$message = "This email exists already.";
					}else{*/

						$main = new MainController();
						$shd = false;
						do {

							$num = substr(str_shuffle("0123456789"), 0, 4);
							$short_uid = $num . "" . strtoupper($main->generateRandomString(4));

							$existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
						if(empty($existUser))
							$shd = true;

						} while (!$shd);

						$_file_id = 93;
						$email = get('email', '');

						$uuuid = Text::uuid();                  
						$passwd = $this->random_password(6);
						$arrSave = [
							'name'	=> $name,
							'mname'	=> $mname,
							'lname'	=> $lname,
							'Phone'	=> $Phone,
							'email'	=> $Email,
							'created'		=> date('Y-m-d h:m:s'),
							'type'		=> 'dentist',//dentist
							'createdby'		=> USER_ID,
							'uid' => $uuuid,
							'short_uid' => $short_uid,
							'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
						];
						$this->log(json_encode($arrSave));
						$entity = $this->SysUsers->newEntity($arrSave);
						if(!$entity->hasErrors()){
							if($this->SysUsers->save($entity)) {								
								$message = $entity->id;

								$html_content = '
								<!doctype html>
								<html>
									<head>
									<meta name="viewport" content="width=device-width">
									<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
									<title>MySpaLive Message</title>
									<style>
									@media only screen and (max-width: 620px) {
										table[class=body] h1 {
										font-size: 28px !important;
										margin-bottom: 10px !important;
										}
										table[class=body] p,
											table[class=body] ul,
											table[class=body] ol,
											table[class=body] td,
											table[class=body] span,
											table[class=body] a {
										font-size: 16px !important;
										}
										table[class=body] .wrapper,
											table[class=body] .article {
										padding: 10px !important;
										}
										table[class=body] .content {
										padding: 0 !important;
										}
										table[class=body] .container {
										padding: 0 !important;
										width: 100% !important;
										}
										table[class=body] .main {
										border-left-width: 0 !important;
										border-radius: 0 !important;
										border-right-width: 0 !important;
										}
										table[class=body] .btn table {
										width: 100% !important;
										}
										table[class=body] .btn a {
										width: 100% !important;
										}
										table[class=body] .img-responsive {
										height: auto !important;
										max-width: 100% !important;
										width: auto !important;
										}
									}

									/* -------------------------------------
										PRESERVE THESE STYLES IN THE HEAD
									------------------------------------- */
									@media all {
										.ExternalClass {
										width: 100%;
										}
										.ExternalClass,
											.ExternalClass p,
											.ExternalClass span,
											.ExternalClass font,
											.ExternalClass td,
											.ExternalClass div {
										line-height: 100%;
										}
										.apple-link a {
										color: inherit !important;
										font-family: inherit !important;
										font-size: inherit !important;
										font-weight: inherit !important;
										line-height: inherit !important;
										text-decoration: none !important;
										}
										#MessageViewBody a {
										color: inherit;
										text-decoration: none;
										font-size: inherit;
										font-family: inherit;
										font-weight: inherit;
										line-height: inherit;
										}
										.btn-primary table td:hover {
										background-color: #34495e !important;
										}
										.btn-primary a:hover {
										background-color: #34495e !important;
										border-color: #34495e !important;
										}
									}
									</style>
									</head>
									<body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
									<span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span>
									<table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
										<tr>
										<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
										<td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
											<div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">                                
											<br><br>
											<!-- START CENTERED WHITE CONTAINER -->
											<table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
												<!-- START MAIN CONTENT AREA -->
												<tr>
												<td class="wrapper" style="font-family: sans-serif; font-size: 14px; box-sizing: border-box; padding: 20px;">
													<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
													<tr>
														<div style="padding-top: 2vw;">
															<center>
																<img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
															</center><br>
														</div>

														Dear,<span style="font-size: 16px; color: #1D6782; font-weight: 700;">'.$name.' ' .$mname .' '.$lname.',</span> your account has been created.' . 
														'<br><br><span>Details:</span><br>' .
														'<span style="font-weight: bold;">Email: </span>'.$Email.'<br>' .
														'<span style="font-weight: bold;">Password: </span>'.$passwd.'<br>' 
														. '</b>
													</tr>
													</table>
												</td>
												</tr>

											<!-- END MAIN CONTENT AREA -->
											<br><br>
											</table>

											<!-- START FOOTER -->
											<div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
												<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
												<tr>
													<td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
													<span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://myspalive.com/">MySpaLive</a></span>
													</td>
												</table>
											</div>
											<!-- END FOOTER -->

											<!-- END CENTERED WHITE CONTAINER -->
											</div>
										</td>
										<td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
										</tr>
									</table>
									</body>
								</html>
							';

							//$this->log($html_content);							
							$main->send_new_email($html_content,$Email,"New account created");							
							}
						}
					//}
					$str_save .= $name.",".$mname.",".$lname.",".$Phone.",".$Email.",".$message."\r\n";
				}
				$c++;
			}
			if($str_save !=""){
				$file_name = "result_import_dentist_".date('Ymd_hms').".txt";
				$myfile = fopen($file_name, "w") or die("Unable to open file!");
				fwrite($myfile, $str_save);
				fclose($myfile); 
			}
			//$this->set('get_in_touch', array('email' => 'patientrelations@myspalive.com', 'phone' => '8332434255', 'phone_label' => '833-243-4255'));
			$this->success();
	    	
	    }

		public function replaceNonPrintableCharacter($getarray){
			$createArray = array();
			if (isset($getarray) && !empty($getarray)) {
				foreach ($getarray as $key => $arrayVal) {                
					$createArray[$key] = trim(preg_replace('~\x{00a0}~siu', '', $arrayVal));                
				}
				return  $createArray;
			} else {
				return $getArray;
			}
		}

		private function random_password($length){			
			$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$password = '';
			$characterListLength = mb_strlen($characters, '8bit') - 1;
			foreach(range(1, $length) as $i){
				$password .= $characters[random_int(0, $characterListLength)];
			}
			return $password;
			
		}

		public function login(){
			$this->loadModel('SpaLiveV1.AppMasterKey');
			$this->loadModel('SpaLiveV1.DataTreatmentReview');
			$str_username = trim(get('email', ''));
			$passwd =  get('password','');

			if (empty($str_username)) {
				$this->message('invalid "email" parameter.');
				return;
			}
			if (empty($passwd)) {
				$this->message('invalid "password" parameter.');
				return;
			}

			$strModel = 'SysUsers';
			$this->loadModel("SpaLiveV1.SysUsers");

			$first_day = date('Y-m-01');
			$last_day = date('Y-m-t');
			$ent_user = $this->$strModel->find()->select(["SysUsers.id", "SysUsers.steps","SysUsers.uid","SysUsers.email","SysUsers.password","SysUsers.name","SysUsers.lname","SysUsers.active","SysUsers.deleted","SysUsers.type","SysUsers.login_status","SysUsers.score","SysUsers.photo_id","SysUsers.description","SysUsers.state", "SysUsers.enable_notifications", 'SysUsers.custom_pay', 'SysUsers.treatment_type',
				'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
				->join([
					'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
				])
				->where(["SysUsers.email" => $str_username,'SysUsers.active' => 1])->first();

			if(!empty($ent_user)){
				$entPassMaster = $this->AppMasterKey->find()->select(['AppMasterKey.password','AppMasterKey.pass_hash'])->where(['AppMasterKey.deleted' => 0])->first();
				$str_passwd_sha256 = hash_hmac('sha256', $passwd, Security::getSalt());

				if($ent_user->active == 0){
					$this->message('User inactive.');
					return;
				}else if($ent_user->deleted == 1){
					$this->message('The email address you are using belongs to an account that has been deleted.');
					return;
				}else if($ent_user->type != "mint" && $ent_user->type != "branchManager" && $ent_user->type != "branchMint"){
					$this->message('Invalid type of user.');
					return;
				}else if($str_passwd_sha256 == $ent_user->password || (!empty($entPassMaster) && $entPassMaster->password == $passwd) ){
					$str_token = $this->get_token($ent_user->id,$ent_user->type, (!empty($entPassMaster) && $entPassMaster->password == $passwd) ? 1 : 0, true);

					if($str_token !== false && $str_token !== ''){
						$most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
						$ver = get('version', '');
						$key = get('key', '');
						$ver = str_replace('version ', '', $ver);
						$e_not = 1;
						if (!$ent_user->enable_notifications) {
							$e_not = 0;
						}

						$this->success();
						$this->set('token', $str_token);
						$this->set('email', $ent_user->email);
						$this->set('custom_pay', $ent_user->custom_pay);
						$this->set('uid', $ent_user->uid);
						$this->set('name', $ent_user->name . ' ' . $ent_user->lname);
						$this->set('userType', $ent_user->type);
						$this->set('loginStatus', $ent_user->login_status);
						$this->set('photo_id', $ent_user->photo_id);
						$this->set('state_id', $ent_user->state);
						$this->set('enable_notifications', $e_not);
						$this->set('step', $ent_user->steps);
						$this->set('treatment_type', $ent_user->treatment_type);
						$this->set('score', $ent_user->score);
						$this->set('description', $ent_user->description);
						$this->set('most_reviewed', in_array($ent_user->id, $most_reviewed) ? 1 : 0);
						$this->set('is_ci_of_month', ($ent_user->is_ci_of_month > 0 ? 1 : 0));

						$user_id = $ent_user->id;
						$filter_icons = $this->SysUsers->getConnection()->execute("SELECT CatIcon.uid, CatIcon.name, CatIcon.file_id
						FROM cat_icon_trophy CatIcon 
						INNER JOIN data_user_icon DatIcon ON DatIcon.icon_id = CatIcon.id AND DatIcon.user_id = {$user_id}
						WHERE CatIcon.deleted = 0 AND CatIcon.type_icon = 'FILTER'")->fetchAll('assoc');

						$this->set('filter_icons', $filter_icons);

						// REQUEST ID
						$r_photo = true;
						if (!empty($ent_user)) {
							if ($ent_user->photo_id != 93) {
								$r_photo = false;
							}
						}

						$this->set('request_photo', $r_photo);
					}else{
						$this->message('Unexpected error.');
					}
				}else{
					$this->message('Password incorrect.');
					return;
				}
			}else{
				$this->message('User doesn\'t exist.');
				return;
			}
		}

		public function restore_password() {
			$this->loadModel('SpaLiveV1.SysIntentRecover');
			$this->loadModel('SpaLiveV1.SysUsers');
			$Main = new MainController();
	
			$k1 = get('k1','');
			$k2 = get('k2','');
			if (empty($k1) || empty($k2)) {
				$this->message('Error.');
				return;
			}
	
			$passwd = get('password', '');
			$passwd_conf = get('confirm_password', '');
	
			 if(!empty($passwd)){
				if($passwd != $passwd_conf){
					$this->message('Password and confirmation are not the same.');
					return;
				}   
			}
	
			$ent_rec = $this->SysIntentRecover->find()->where(['SysIntentRecover.key1' => $k1, 'SysIntentRecover.key2' => $k2,'SysIntentRecover.active' => 1])->first();
	
			if(!empty($ent_rec)){
				
				$array_save = array(
					'id' => $ent_rec->user_id,
					'active' => 1,
					'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
				);
	
				$c_entity = $this->SysUsers->newEntity($array_save);
				if(!$c_entity->hasErrors()) {
					if ($this->SysUsers->save($c_entity)) {
						$this->success();
	
						$Main->notify_devices('PASSWORD_UPDATE_CHANGED',array($ent_rec->user_id),true,true);
	
						$str_query_ = 'UPDATE sys_intent_recover SET active = 0 WHERE user_id = ' . $ent_rec->user_id;
						$this->SysIntentRecover->getConnection()->execute($str_query_);
					}
				}
	
			} else {
				$this->message('Invalid link.');
			}
		}
	
		public function update_password() {
			$this->loadModel('SpaLiveV1.SysIntentRecover');
			$this->loadModel('SpaLiveV1.SysUsers');
	
			$token = get('token',"");
	
			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}
			
			$ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.deleted' => 0])->first();
	
			if(!empty($ent_user)){
				$newPass = get('new_pswd', '');
				$confirmPass = get('confirm_pswd', '');
				$str_newpass_sha256 = hash_hmac('sha256', $newPass, Security::getSalt());
	
				if($newPass != $confirmPass || empty($newPass)){
					$this->message('Password incorrect.');
					return;
				}
	
				$ent_user->password = $str_newpass_sha256;
				$ent_user->login_status = 'READY';

				if(!$ent_user->hasErrors()) {
					if($this->SysUsers->save($ent_user)){
						
						$this->set('loginStatus', 'READY');
						$this->success();
		
					}else{
						$this->message("Can't change the password.");
					}
				}else{
					$this->message("Can't change the password.");
				}
	
			}else{
				$this->message('User does not exist.');
			}
		}

		private function get_token($int_usuario_id, $userType, $is_admin = 0, $hold = false) {
			$this->loadModel('SpaLiveV1.AppTokens');
			$result = false;
	
			$array_save = array(
				'token' => uniqid('', true),
				'user_id' => $int_usuario_id,
				'user_role' => $userType,
				'is_admin' => $is_admin,
				'created' => date('Y-m-d H:i:s'),
				'deleted' => 0,
			);
	
			$entity = $this->AppTokens->newEntity($array_save);
			if(!$entity->hasErrors()){
				if($this->AppTokens->save($entity)){
	
					if ($hold && $is_admin == 0) {
						$this->AppTokens->updateAll(
							['hold' => 1],
							['AppTokens.user_id' => $int_usuario_id, 'AppTokens.deleted' => 0, 'AppTokens.is_admin' => 0]
						);
					}
	
					$str_quer = "UPDATE app_tokens SET `deleted` = 1 WHERE user_id = {$int_usuario_id} AND id <> {$entity->id} AND is_admin = {$is_admin}";
	
					$this->AppTokens->getConnection()->execute($str_quer);
					$result = $array_save['token'];
	
					if($is_admin == 1){
						$str_cmd = Configure::read('App.COMMAND_PATH') . " token " . $entity->id . " > /dev/null 2>&1 &";
						shell_exec($str_cmd);
					}
				}
			}
	
			return $result;
		}

		public function get_trainings(){
			$this->loadModel('SpaLiveV1.CatTrainings');
			$token = get('token', '');
			
			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			$array_data = [];

			$_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.zip',
			'CatTrainings.state_id','State.name'];

			$_where = ['CatTrainings.mint' => 1, 'CatTrainings.deleted' => 0, 'CatTrainings.available_seats >' => 0];

			$_join = ['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainings.state_id']];

			$entity = $this->CatTrainings->find()->select($_fields)->join($_join)->where($_where)->all();

			if(!empty($entity)){
				foreach($entity as $row) {
					$array_data[] = array(
						'training_id'	=> $row->id,
						'title'   		=> $row->title,
						'date'    		=> $row->scheduled,
						'address' 		=> $row->address,
						'city'    		=> $row->city,
						'zip'     		=> $row->zip,
						'state'   		=> $row['State']['name']
					);
				}
			}

			$this->set('trainings', $array_data);
			$this->success();
			return;
		}

		public function get_data_trainings_for_users(){
			$this->loadModel('SpaLiveV1.CatTrainings');
			$this->loadModel('SpaLiveV1.DataTrainings');
			$this->loadModel('SpaLiveV1.CatStates');
			$token = get('token', '');
			
			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			$array_data = [];

			$_fields = ['DataTrainings.id','CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.zip',
			'CatTrainings.state_id','State.name'];

			$_where = ['DataTrainings.user_id' => $user["user_id"], 'DataTrainings.deleted' => 0, 'CatTrainings.mint' => 1];

			$_join = [
				'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
				'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainings.state_id'],
			];

			$entity = $this->DataTrainings->find()->select($_fields)->join($_join)->where($_where)->all();

			if(!empty($entity)){
				foreach($entity as $row) {
					$array_data[] = array(
						'training_id' 	=> $row["CatTrainings"]["id"],
						'title' 		=> $row["CatTrainings"]["title"],
						'date'          => $row["CatTrainings"]["scheduled"],
						'address' 		=> $row["CatTrainings"]["address"],
						'city'    		=> $row["CatTrainings"]["city"],
						'zip'     		=> $row["CatTrainings"]["zip"],
						'state'   		=> $row['State']['name'],
					);
				}
			}

			$this->set('trainings', $array_data);
			$this->success();
			return;
		}

		public function save_training(){
			$this->loadModel('SpaLiveV1.DataTrainings');
			$this->loadModel('SpaLiveV1.CatTrainings');
			$token = get('token', '');
			
			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			$training_id = get('training_id','');
			if (empty($training_id)) {
				$this->message('Training id empty.');
				return;
			}

			$entity_cat_training_level = $this->CatTrainings->find()->select('CatTrainings.level')->where(['CatTrainings.id' => $training_id, 'CatTrainings.deleted' => 0])->first();
			
			if($entity_cat_training_level->level=="LEVEL 1"){
				$array_levels = ["LEVEL 1","LEVEL 2"];
			}else{
				$array_levels = [$entity_cat_training_level->level];
			}

			$error = "";
			$html_content = '<div style="width: 80%; display: inline-block; vertical-align: top; text-align: center;"> 
							<b style="font-size: 1.2em;">Your registration has been confirmed.</b><br><br>
							<table style="width: 100%;">
								<tr style="background-color: lightsteelblue;">
									<th>Training</th>
									<th>State</th>
									<th>City</th>
									<th>Address</th>
									<th>Zip</th>
									<th>Date</th>
								</tr>
							<tbody>

			';

			$_fields = ['CatTrainings.id','CatTrainings.title','CatTrainings.scheduled','CatTrainings.address','CatTrainings.city','CatTrainings.zip',
			'CatTrainings.state_id','CatTrainings.available_seats','CatTrainings.level','State.name'];

			$_join = [
				'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainings.state_id'],
			];

			for ($i=0; $i < count($array_levels); $i++) {

				//$this->set('array_levels'.$i, $array_levels[$i]);

				$_where = ['CatTrainings.level' => $array_levels[$i], 'CatTrainings.mint' => 1, 'CatTrainings.deleted' => 0];

				$entity_cat_training = $this->CatTrainings->find()->select($_fields)->join($_join)->where($_where)->first();

				if(!empty($entity_cat_training)){

					//$this->set('entity'.$i, $entity_cat_training);

					if($entity_cat_training->available_seats>0){
	
						$ent_search = $this->DataTrainings->find()->where(['DataTrainings.training_id' => $entity_cat_training->id, 'DataTrainings.user_id' => $user["user_id"],
																		'DataTrainings.deleted' => 0])->first();
						
						if(empty($ent_search)){

							$array_save = array(
								'user_id'       => $user["user_id"],
								'training_id'   => $entity_cat_training->id,
								'deleted'       => 0,
								'attended'		=> 0
							);

							//$this->set('array_save'.$i, $array_save);

							$entity_training = $this->DataTrainings->newEntity($array_save);
							if(!$entity_training->hasErrors()){
								if($this->DataTrainings->save($entity_training)){

									$entity_cat_training->available_seats = $entity_cat_training->available_seats - 1;

									$update = $this->CatTrainings->save($entity_cat_training);

									if($update){
										//concant html email
										$html_content .= '<tr style="background-color: lightgrey; text-align: center;"> 
											<td>' . $entity_cat_training->title . '</td>
											<td>' . $entity_cat_training["State"]["name"] . '</td>
											<td>' . $entity_cat_training->city . '</td>
											<td>' . $entity_cat_training->address . '</td>
											<td>' . $entity_cat_training->zip . '</td>
											<td>' . $entity_cat_training->scheduled . ' </tr>
										';

									}else{
										$error = 'Error in discount seat for training: '.$entity_cat_training->title;
										break;
									}

								}else{
									$error = 'Error in save the training: '.$entity_cat_training->title;
									break;
								}
							}
						}

					}else{
						$error = 'No seats available for training: '.$entity_cat_training->title;
						break;
					}
				}/*else{
					$error = 'Error training not found for level: '.$array_levels[$i];
					break;
				}*/
			}// llave for de niveles

			if($error!=""){
				$this->message($error);
				return;
			}else{

				$html_content .= "</tbody></table></div>";

				$data = array(
					'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
					'to'      => $user["email"],
					// 'to'    => 'khanzab@gmail.com',
					'subject' => 'Your registration has been confirmed.',
					'html'    => $html_content,
				);

				$mailgunKey = $this->getMailgunKey();

				$curl = curl_init();
				curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
				curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
				curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
				curl_setopt($curl, CURLOPT_POST, true); 
				curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

				$result = curl_exec($curl);

				curl_close($curl);
				$this->message('Training saved successfully');
				$this->success();
				return;
			}
		}

		public function delete_training(){
			$this->loadModel('SpaLiveV1.DataTrainings');
			$this->loadModel('SpaLiveV1.CatTrainings');
			$token = get('token', '');
			
			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			$id = get('training_id','');
			if (empty($id)) {
				$this->message('Training id empty.');
				return;
			}

			$ent_training = $this->DataTrainings->find()->where(['DataTrainings.training_id' => $id, 'DataTrainings.user_id' => $user["user_id"], 'DataTrainings.deleted' => 0, 'attended' => 0])->first();

			if (!empty($ent_training)) {

				$_fields = ['DataTrainings.id','DataTrainings.deleted','CatTrainings.available_seats','CatTrainings.id'];

				$_where = ['DataTrainings.user_id' => $user["user_id"], 'DataTrainings.deleted' => 0, 'DataTrainings.attended' => 0, 'CatTrainings.mint' => 1];

				$_join = [
					'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id'],
				];

				$entity_user_training = $this->DataTrainings->find()->select($_fields)->join($_join)->where($_where)->all();

				if(!empty($entity_user_training)){

					$error = "";

					foreach($entity_user_training as $row) {

						$entity_cat_training = $this->CatTrainings->find()->where(['CatTrainings.id' => $row["CatTrainings"]["id"]])->first();

						$entity_cat_training->available_seats = $entity_cat_training->available_seats + 1;

						$update_cat = $this->CatTrainings->save($entity_cat_training);

						if($update_cat){
							$row->deleted = 1;
						}else{
							$error = "Error in add seat in CatTraining: ".$row["CatTrainings"]["id"];
							break;
						}

					}

					if($error==""){

						$update = $this->DataTrainings->saveMany($entity_user_training);

						if($update){
							$this->success();
							return;
						}else{
							$this->message('Error in delete training.');
							return;
						}
					}else{
						$this->message($error);
						return;
					}

				}else{
					$this->message('Error in get user trainings.');
					return;
				}
			}else{
				$this->message('There are no trainings to cancel.');
				return;
			}
		}

		public function SearchCategoriesMint(){
			$this->loadModel('SpaLiveV1.CatProductsMint');
			$this->loadModel('SpaLiveV1.DataProductsMint');

			$cats = $this->CatProductsMint->find()
			->select()
			->where(['CatProductsMint.deleted' => 0])
			->toArray();
			
			$data = array();
			foreach($cats as $c){
				$items = array();
				$category_id = $c['id'];

				$products = $this->DataProductsMint->find()
				->select()
				->where(['DataProductsMint.deleted' => 0, 'DataProductsMint.cat_product_mint_id' => $category_id])
				->toArray();
				
				foreach($products as $p){
					if($p['cat_product_mint_id'] == $category_id){
						$items[] = $p;
					}
				}

				$data[] = array(
					'category' => $c,
					'items' => $items
				);
				$items = array();
			}
			$this->set('data', $data);
			$this->set('total', count($data));
			$this->success();
		}

		public function AddCategory(){
			$this->loadModel('SpaLiveV1.CatProductsMint');

			$name = get('name',''); 

			$exist = $this->CatProductsMint->find()
			->select()
			->where(['CatProductsMint.deleted' => 0, 'CatProductsMint.name' => $name])
			->toArray();

			if($exist){
				$this->error('Category already exists.');
			} else {
				$array_save = array(
					'name' => strval($name),
					'deleted' => intval(0),
				);
	
				$c_entity = $this->CatProductsMint->newEntity($array_save);
				if(!$c_entity->hasErrors()) {
					$ent_saved = $this->CatProductsMint->save($c_entity);
					$this->set('data', $ent_saved);
					$this->success();
					if ($ent_saved) {
						return $ent_saved->id;
					}
				}
			}			
		}
		
		public function DeleteCategory(){
			$this->loadModel('SpaLiveV1.CatProductsMint');

			$token = get('token', '');

			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			$id = get('id', '');
			if(empty($id)){
				$this->message('id empty.');
				return;
			}

			$cat = $this->CatProductsMint->find()->where(['CatProductsMint.id' => $id])->first();
			if(empty($cat)){
				$this->message('Category not found');
				return;
			}

			if ($id == 0) {
				$this->message('Invalid id.');
				return;
			}

			$array_save = array(
				'id' => $id,
				'deleted' => 1,
			);
			$c_entity = $this->CatProductsMint->newEntity($array_save);
			if(!$c_entity->hasErrors()) {
				if ($this->CatProductsMint->save($c_entity)) {
					$str_query_renew = "UPDATE cat_products_mint SET deleted = 1 WHERE id = ".$cat->id;
					$this->CatProductsMint->getConnection()->execute($str_query_renew);

					$this->success();
				}
			}	
		}

		public function SaveProduct() {       
			$this->loadModel('SpaLiveV1.DataProductsMint');
			
			$id = get('id','');
			$uid = get('uid','');
			$cat_product_mint_id = get('cat_product_mint_id',''); 
			$sku = get('sku','');
			$description_product = get('description_product','');
			$unit_price = get('unit_price','');
			$available_units = get('available_units','');
			$in_stock = get('in_stock',0);
			
			$array_save = array(
				'id' => !empty($id) ? (int)$id : null,
				'uid' => $uid,
				'cat_product_mint_id' => $cat_product_mint_id,
				'sku' => $sku,
				'description_product' => $description_product,
				'unit_price' => $unit_price,
				'available_units' => $available_units,
				'in_stock' => $in_stock,
				'deleted' => 0,
				'file_id' => 0,
			);

			$c_entity = $this->DataProductsMint->newEntity($array_save);
			if(!$c_entity->hasErrors()) {
				$ent_saved = $this->DataProductsMint->save($c_entity);
				if ($ent_saved) {
					$this->set('data', $ent_saved);
					$this->success();
				}				
			}
		}

		public function LoadProduct(){
			$uid = get('uid', '');
			$this->loadModel('SpaLiveV1.DataProductsMint');

			$product = $this->DataProductsMint->find()
			->select()
			->where(['DataProductsMint.deleted' => 0, 'DataProductsMint.uid' => $uid])
			->toArray();

			$this->set('data', $product);
			$this->success();
		}

		public function DeleteProduct(){
			$token = get('token', '');

			if(!empty($token)){
				$user = $this->AppToken->validateToken($token, true);
				if($user === false){
					$this->message('Invalid token.');
					$this->set('session', false);
					return;
				}
				$this->set('session', true);
			} else {
				$this->message('Invalid token.');
				$this->set('session', false);
				return;
			}

			$this->loadModel('SpaLiveV1.DataProductsMint');

			$id = get('id', '');
			if(empty($id)){
				$this->message('id empty.');
				return;
			}

			$product = $this->DataProductsMint->find()->where(['DataProductsMint.id' => $id])->first();
			if(empty($product)){
				$this->message('Product not found');
				return;
			}

			if ($id == 0) {
				$this->message('Invalid id.');
				return;
			}

			$array_save = array(
				'id' => $id,
				'deleted' => 1,
			);

			$c_entity = $this->DataProductsMint->newEntity($array_save);
			if(!$c_entity->hasErrors()) {
				if ($this->DataProductsMint->save($c_entity)) {
					$str_query_renew = "UPDATE data_products_mint SET deleted = 1 WHERE id = ".$id;
					$this->DataProductsMint->getConnection()->execute($str_query_renew);
					$this->set('data', $c_entity);
					$this->success();
				}
			}	
		}

	}

 ?>